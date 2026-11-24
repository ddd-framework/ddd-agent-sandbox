package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"math/rand/v2"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"
)

// Runner states.
const (
	StateIdle      = "idle"
	StateBusy      = "busy"
	StateReleasing = "releasing"
	StateBroken    = "broken"
)

var (
	// ErrRunnerBusy: the runner holds another session (503).
	ErrRunnerBusy = errors.New("runner busy")
	// ErrRunnerBroken: a wipe failed; the runner takes no more sessions (503) until the pod is replaced.
	ErrRunnerBroken = errors.New("runner broken")
	// ErrSessionNotFound: no such session on this runner (404).
	ErrSessionNotFound = errors.New("session not found")
)

// Runner owns at most one session at a time.
type Runner struct {
	config     Config
	executable string
	privileged bool
	ownUID     int
	logger     *slog.Logger

	mutex   sync.Mutex
	state   string
	session *Session
	nextUID int
	// quotaMountsOwn: every quota directory is its own mount, so statfs can stand in for the walk
	quotaMountsOwn bool
	// transfer: the client of the transfer proxy (nil: not configured, drive mode streams through PHP)
	transfer *transferClient
	// memory: the session cgroup that measures memory (memory.go); adoptFailures counts processes not placed in it
	memory        memoryCgroups
	adoptFailures atomic.Int32
	// fuse: the placeholder file system, started at the first drive claim with mounts and kept for the runner's life;
	// fuseFailures counts sessions whose placeholder FUSE could not be mounted (logged once)
	fuse         *fuseService
	fuseMutex    sync.Mutex
	fuseFailures atomic.Int32
}

// NewRunner creates the runner. privileged means: the runner can switch uid (root with SETUID and SETGID). Without
// it every session runs under the runner's own uid (tests and local development only).
func NewRunner(config Config, executable string, privileged bool, logger *slog.Logger) *Runner {
	runner := &Runner{
		config:         config,
		executable:     executable,
		privileged:     privileged,
		ownUID:         os.Getuid(),
		logger:         logger,
		state:          StateIdle,
		nextUID:        rand.IntN(config.UIDSpan),
		quotaMountsOwn: allOwnMounts(config.QuotaDirs),
		memory:         memoryCgroups{mode: MemoryRSS},
	}
	if config.MemoryLimitBytes <= 0 {
		runner.memory.mode = MemoryOff
	} else if privileged {
		runner.memory = detectMemoryCgroups(config.MemoryMode, config.CgroupRoot)
	}
	return runner
}

func allOwnMounts(directories []string) bool {
	for _, directory := range directories {
		if !InspectMount(directory).OwnMount {
			return false
		}
	}
	return len(directories) > 0
}

// WorkspaceMount answers the workspace's mount state now (for /healthz).
func (runner *Runner) WorkspaceMount() MountState {
	return InspectMount(runner.config.Workspace)
}

// State answers the runner state for /healthz.
func (runner *Runner) State() string {
	runner.mutex.Lock()
	defer runner.mutex.Unlock()
	return runner.state
}

// helperCommand prepares `<runner> __helper <arguments…>` under uid with the given environment.
func (runner *Runner) helperCommand(uid int, environment []string, arguments ...string) *exec.Cmd {
	if !runner.config.SessionSeccomp {
		arguments = append([]string{helperNoSeccomp}, arguments...)
	}
	command := exec.Command(runner.executable, append([]string{helperArgument}, arguments...)...)
	command.Env = environment
	command.SysProcAttr = &syscall.SysProcAttr{}
	if runner.privileged {
		command.SysProcAttr.Credential = &syscall.Credential{Uid: uint32(uid), Gid: uint32(uid), Groups: []uint32{}}
	}
	return command
}

// processCommand prepares a shell or job helper of the session: inside the placeholder file system's namespaces when
// the session has one (nsenter as root, then the session uid there), else directly under the session uid.
func (session *Session) processCommand(environment []string, arguments ...string) *exec.Cmd {
	command := session.runner.helperCommand(session.UID, environment, arguments...)
	if session.fuse != nil && !session.fuse.alive() {
		// the holder ended unexpectedly: new shells start outside, with empty mount points, instead of not at all
		if session.fuseLost.CompareAndSwap(false, true) {
			session.runner.logger.Warn("placeholder FUSE holder ended; new shells start without the mounts", "session", session.ID)
			session.history.event(EventFuseLost, "new shells start without the mounts")
		}
		return command
	}
	if session.fuse != nil {
		command.Args = append(append([]string{"nsenter"}, session.fuse.namespaceArguments(session.UID)...), command.Args...)
		command.Path = session.runner.config.NsenterPath
		command.Err = nil
		command.SysProcAttr.Credential = nil
	}
	return command
}

// runHelper runs a helper to completion; stdin may be nil. Answers its stdout and exit code.
func (runner *Runner) runHelper(uid int, environment []string, stdin io.Reader, arguments ...string) ([]byte, int, error) {
	command := runner.helperCommand(uid, environment, arguments...)
	var stdout bytes.Buffer
	command.Stdin = stdin
	command.Stdout = &stdout
	err := command.Run()
	var exitErr *exec.ExitError
	if errors.As(err, &exitErr) {
		return stdout.Bytes(), exitCodeOf(exitErr.ProcessState), nil
	}
	if err != nil {
		return nil, -1, err
	}
	return stdout.Bytes(), 0, nil
}

func (runner *Runner) baseEnvironment() []string {
	return []string{"PATH=" + runner.config.Path, "HOME=" + runner.config.Home, "LANG=C.UTF-8"}
}

// sessionOwned decides whether an entry of a wipe directory belongs to a session.
func (runner *Runner) sessionOwned(uid int) bool {
	if !runner.privileged {
		return true
	}
	return uid != runner.ownUID
}

// Wipe removes everything a session left: the whole workspace, and every entry of the wipe directories that belongs
// to a session uid. In privileged mode it first kills every process of each uid it finds.
func (runner *Runner) Wipe() error {
	defer TidyLostAndFound(runner.config.Workspace, runner.ownUID)
	// drive mode: the root-only state (emptied, the directory may be a mount point) and the CLI socket
	os.Remove(runner.config.DriveSocket)
	if _, err := clearStateDir(runner.config.DriveStateDir); err != nil {
		return err
	}
	directories := append([]string{runner.config.Workspace}, runner.config.WipeDirs...)
	for index, directory := range directories {
		isWorkspace := index == 0
		entries, err := os.ReadDir(directory)
		if errors.Is(err, os.ErrNotExist) && !isWorkspace {
			continue
		}
		if err != nil {
			return fmt.Errorf("read wipe directory: %w", err)
		}
		byUID := map[int][]string{}
		for _, entry := range entries {
			info, err := os.Lstat(filepath.Join(directory, entry.Name()))
			if err != nil {
				continue
			}
			uid := int(info.Sys().(*syscall.Stat_t).Uid)
			if isWorkspace && entry.Name() == lostAndFound && uid == runner.ownUID && info.IsDir() {
				continue
			}
			if !runner.sessionOwned(uid) {
				if isWorkspace {
					if err := os.RemoveAll(filepath.Join(directory, entry.Name())); err != nil {
						return fmt.Errorf("remove runner-owned workspace entry: %w", err)
					}
				}
				continue
			}
			byUID[uid] = append(byUID[uid], entry.Name())
		}
		for uid, names := range byUID {
			if runner.privileged {
				runner.killAll(uid)
			}
			arguments := append([]string{"wipe", directory}, names...)
			if _, code, err := runner.runHelper(uid, runner.baseEnvironment(), nil, arguments...); err != nil || code != 0 {
				return fmt.Errorf("wipe helper failed (exit %d)", code)
			}
		}
	}
	// verify
	for index, directory := range directories {
		entries, err := os.ReadDir(directory)
		if err != nil {
			continue
		}
		for _, entry := range entries {
			info, err := os.Lstat(filepath.Join(directory, entry.Name()))
			if err != nil {
				continue
			}
			owner := int(info.Sys().(*syscall.Stat_t).Uid)
			if index == 0 && entry.Name() == lostAndFound && owner == runner.ownUID {
				continue
			}
			if index == 0 || runner.sessionOwned(owner) {
				return fmt.Errorf("wipe left entries behind")
			}
		}
	}
	return nil
}

// killAll kills every process of uid (privileged mode only: kill(-1) as that uid).
func (runner *Runner) killAll(uid int) error {
	if !runner.privileged {
		return nil
	}
	_, code, err := runner.runHelper(uid, runner.baseEnvironment(), nil, "killall")
	if err != nil || code != 0 {
		return fmt.Errorf("killall failed (exit %d)", code)
	}
	return nil
}

// RestoreFile is one inline file of POST /v1/sessions.
type RestoreFile struct {
	Path          string `json:"path"`
	ContentBase64 []byte `json:"contentBase64"`
	Mode          string `json:"mode,omitempty"`
	Mtime         string `json:"mtime,omitempty"`
}

// Claim binds the runner to a workspace key with a fresh uid.
func (runner *Runner) Claim(workspaceKey string, environment map[string]string) (*Session, error) {
	runner.mutex.Lock()
	defer runner.mutex.Unlock()
	switch runner.state {
	case StateBroken:
		return nil, ErrRunnerBroken
	case StateIdle:
	default:
		return nil, ErrRunnerBusy
	}
	TidyLostAndFound(runner.config.Workspace, runner.ownUID)
	uid := runner.config.UIDBase + runner.nextUID%runner.config.UIDSpan
	runner.nextUID = (runner.nextUID + 1) % runner.config.UIDSpan
	if !runner.privileged {
		uid = runner.ownUID
	}
	session := &Session{
		ID:           "s_" + randomHex(12),
		UID:          uid,
		WorkspaceKey: workspaceKey,
		runner:       runner,
		created:      time.Now(),
		shells:       map[string]*Shell{},
		jobs:         map[string]*Job{},
		runs:         map[int64]*execRun{},
		stopWatch:    make(chan struct{}),
	}
	session.env = session.buildEnvironment(environment)
	session.history = newSessionHistory(environment)
	session.touch()
	// statfs baselines of the empty quota mounts (file system metadata, lost+found): the fast watcher counts above them
	for _, directory := range runner.config.QuotaDirs {
		_, _, used, _ := statfsBytes(directory)
		session.statfsBaseline = append(session.statfsBaseline, used)
	}
	session.fastWatch.Store(runner.config.QuotaWatchMode == "statfs" && runner.quotaMountsOwn)
	session.statfsMeasure = session.statfsUsage
	session.memoryMeasure = session.memoryUsage
	runner.session = session
	runner.state = StateBusy
	go session.watchQuota()
	go session.watchMemory()
	return session, nil
}

// Session answers the bound session with this id.
func (runner *Runner) Session(id string) (*Session, error) {
	runner.mutex.Lock()
	defer runner.mutex.Unlock()
	if runner.session == nil || runner.session.ID != id || runner.state != StateBusy {
		return nil, ErrSessionNotFound
	}
	runner.session.touch()
	return runner.session, nil
}

// Release ends the session: kills its jobs, shells and every process of its uid, wipes, frees the runner.
func (runner *Runner) Release(id, reason string) error {
	runner.mutex.Lock()
	session := runner.session
	if session == nil || session.ID != id || runner.state != StateBusy {
		runner.mutex.Unlock()
		return ErrSessionNotFound
	}
	runner.state = StateReleasing
	runner.mutex.Unlock()

	started := time.Now()
	session.closeAll()
	driveErr := session.closeDrive()
	if runner.memory.directory != "" {
		killCgroup(runner.memory.directory)
	}
	killErr := runner.killAll(session.UID)
	// no session process is left inside the placeholder file system's namespace: its bind goes
	session.detachFuse()
	wipeErr := runner.Wipe()

	runner.mutex.Lock()
	defer runner.mutex.Unlock()
	runner.session = nil
	if err := errors.Join(driveErr, killErr, wipeErr); err != nil {
		runner.state = StateBroken
		runner.logger.Error("session release failed", "session", session.ID, "uid", session.UID,
			"reason", reason, "durationMs", time.Since(started).Milliseconds(), "state", runner.state)
		return err
	}
	runner.state = StateIdle
	runner.logger.Info("session released", "session", session.ID, "uid", session.UID, "reason", reason,
		"durationMs", time.Since(started).Milliseconds(), "lifetimeMs", time.Since(session.created).Milliseconds(),
		"execs", session.execSeq.Load(), "jobs", session.jobSeq.Load())
	return nil
}

// ReapIdle releases the session when it had no call for SessionIdle and nothing runs.
func (runner *Runner) ReapIdle() {
	runner.mutex.Lock()
	session := runner.session
	busy := runner.state == StateBusy
	runner.mutex.Unlock()
	if session == nil || !busy || session.inflight.Load() > 0 || session.activeExecs.Load() > 0 || session.hasRunningJobs() {
		return
	}
	if time.Since(time.Unix(0, session.lastActivity.Load())) < runner.config.SessionIdle {
		return
	}
	runner.Release(session.ID, "idle")
}

// Session is the binding of the runner to one workspace key.
type Session struct {
	ID           string
	UID          int
	WorkspaceKey string
	runner       *Runner
	env          []string
	created      time.Time

	mutex        sync.Mutex
	shells       map[string]*Shell
	jobs         map[string]*Job
	closed       bool
	execSeq      atomic.Int64
	jobSeq       atomic.Int64
	lastActivity atomic.Int64
	inflight     atomic.Int32
	// activeExecs counts foreground execs; the quota watcher measures while one runs or a job runs
	activeExecs atomic.Int32
	stopWatch   chan struct{}
	quotaGen    atomic.Int64
	quotaReport *QuotaReport
	// statfsBaseline: used bytes of each quota mount at claim; fastWatch: the watcher uses statfs instead of the walk
	statfsBaseline []int64
	fastWatch      atomic.Bool
	// statfsOffset: by how much statfs overstated the exact walk at the last confirmation (ext4 frees the blocks of
	// deleted files only at its next journal commit); statfsMeasure is replaceable in tests
	statfsOffset  int64
	statfsMeasure func() int64
	// reportedUsage: the usage of the last quota report while the session is past a limit (0 below it). The watcher
	// kills only growth beyond it, so a command that frees space (rm) or leaves the usage alone runs to its end.
	reportedUsage atomic.Int64
	// killing is held (write) while a quota excess kills the uid's processes; new shells and jobs and the answers of
	// interrupted execs wait for it (read), so a kill-by-uid never hits the next call
	killing sync.RWMutex
	// runs: foreground execs by seq until their final answer was delivered (a paused exec outlives its HTTP call)
	runs map[int64]*execRun
	// drive: the working-copy state in drive mode (nil in the legacy mode)
	drive         *DriveState
	driveListener net.Listener
	// fuse: the placeholder file system of the mounts (nil: none, or Plan B); shells and jobs start inside its
	// namespaces; fuseLost: its holder ended unexpectedly (logged once)
	fuse     *fuseService
	fuseLost atomic.Bool
	// memory (memory.go): the generation moves with every excess; memoryReported is the usage after the last kill
	// while the session stays above the limit (0 below it); memoryMeasure is replaceable in tests
	memoryGen      atomic.Int64
	memoryReport   *MemoryReport
	memoryReported atomic.Int64
	memoryMeasure  func() (int64, error)
	// memoryPeak: the highest memory measure seen (admin.go)
	memoryPeak atomic.Int64
	// history: the last commands and session events with the secret mask of the claim's env (admin.go)
	history *sessionHistory
}

// QuotaReport is the answer part of a quota excess: usage below the quota directories, the limit, the largest files.
type QuotaReport struct {
	// Reason: LIMIT (usage above the runner's quota) or NO_SPACE (the workspace file system answered ENOSPC: the hard
	// limit of the memory-backed emptyDir)
	Reason     string `json:"reason"`
	TotalBytes int64  `json:"totalBytes"`
	// LimitBytes: the effective limit, the smaller of QuotaBytes and what the workspace mount can still hold
	LimitBytes int64 `json:"limitBytes"`
	QuotaBytes int64 `json:"quotaBytes"`
	FreeBytes  int64 `json:"freeBytes"`
	// ByDirectory: usage per quota directory, e.g. {"/workspace": …, "/tmp": …} (HOME and its caches are in /tmp)
	ByDirectory map[string]int64 `json:"byDirectory,omitempty"`
	Largest     []UsagePath      `json:"largest"`
}

// Exec statuses.
const (
	ExecOK            = "OK"
	ExecTimedOut      = "TIMED_OUT"
	ExecShellExited   = "SHELL_EXITED"
	ExecQuotaExceeded = "QUOTA_EXCEEDED"
	// ExecMemoryExceeded: the session passed its memory limit; every process of the session was ended
	ExecMemoryExceeded = "MEMORY_EXCEEDED"
)

// MeasureUsage measures the disk usage below the quota directories as the session uid; target (absolute, may be
// empty) is a file whose own usage is answered as TargetBytes.
func (session *Session) MeasureUsage(target string) (Usage, error) {
	if target == "" {
		target = "-"
	}
	arguments := append([]string{"usage", target}, session.runner.config.QuotaDirs...)
	output, code, err := session.helper(nil, arguments...)
	if err != nil || code != 0 {
		return Usage{}, fmt.Errorf("usage helper failed (exit %d)", code)
	}
	var usage Usage
	if err := json.Unmarshal(output, &usage); err != nil {
		return Usage{}, err
	}
	return usage, nil
}

// workspaceFree answers the free space of the workspace mount (huge when statfs fails: then only the quota counts).
func (session *Session) workspaceFree() int64 {
	_, free, _, err := statfsBytes(session.runner.config.Workspace)
	if err != nil {
		return 1 << 62
	}
	return free
}

// statfsUsage is the fast measure: what the quota mounts hold above their state at claim.
func (session *Session) statfsUsage() int64 {
	var total int64
	for index, directory := range session.runner.config.QuotaDirs {
		if _, _, used, err := statfsBytes(directory); err == nil && index < len(session.statfsBaseline) {
			total += max(used-session.statfsBaseline[index], 0)
		}
	}
	return total
}

// watchQuota measures every QuotaInterval while an exec or a job runs: the usage (an exact walk, or statfs of the
// mounts once a walk takes longer than QuotaExactBudget) and the free space of the workspace mount. Past either limit
// it ends every process of the session uid; the report then comes from one exact walk (the five largest files).
func (session *Session) watchQuota() {
	config := session.runner.config
	ticker := time.NewTicker(config.QuotaInterval)
	defer ticker.Stop()
	statfsTicks := 0
	for {
		select {
		case <-session.stopWatch:
			return
		case <-ticker.C:
		}
		if session.activeExecs.Load() == 0 && !session.hasRunningJobs() {
			continue
		}
		var total int64
		if session.fastWatch.Load() {
			session.mutex.Lock()
			measureStatfs := session.statfsMeasure
			session.mutex.Unlock()
			raw := measureStatfs()
			statfsTicks++
			if config.QuotaRecalibrateTicks > 0 && statfsTicks%config.QuotaRecalibrateTicks == 0 {
				// the offset learned at a false alarm may have been a passing lag: reset it against the walk
				if usage, err := session.MeasureUsage(""); err == nil {
					session.statfsOffset = raw - usage.TotalBytes
				}
			}
			total = raw - session.statfsOffset
		} else {
			started := time.Now()
			usage, err := session.MeasureUsage("")
			if err != nil {
				continue
			}
			total = usage.TotalBytes
			if elapsed := time.Since(started); config.QuotaWatchMode == "auto" && elapsed > config.QuotaExactBudget &&
				session.runner.quotaMountsOwn {
				session.fastWatch.Store(true)
				session.runner.logger.Info("quota watcher uses statfs", "session", session.ID,
					"walkMs", elapsed.Milliseconds(), "budgetMs", config.QuotaExactBudget.Milliseconds())
			}
		}
		free := session.workspaceFree()
		reason := quotaExceededBy(config.QuotaBytes, total, free, config.FreeReserve)
		if reason == "" {
			session.reportedUsage.Store(0)
			continue
		}
		measure := "exact"
		usage, err := session.MeasureUsage("")
		if session.fastWatch.Load() {
			// statfs only raises the suspicion: the exact walk decides (statfs lags behind deletions on ext4 and counts
			// directories and metadata); a false alarm recalibrates the estimate and kills nothing
			measure = "statfs"
			if err != nil {
				continue
			}
			if quotaExceededBy(config.QuotaBytes, usage.TotalBytes, free, config.FreeReserve) == "" {
				session.statfsOffset += total - usage.TotalBytes
				session.runner.logger.Info("quota statfs estimate above the limit, exact walk below: no action",
					"session", session.ID, "statfsBytes", total, "exactBytes", usage.TotalBytes,
					"limitBytes", effectiveLimit(config.QuotaBytes, usage.TotalBytes, free, config.FreeReserve),
					"freeBytes", free)
				continue
			}
			reason = quotaExceededBy(config.QuotaBytes, usage.TotalBytes, free, config.FreeReserve)
		} else if err != nil {
			usage = Usage{TotalBytes: total, Largest: []UsagePath{}}
		}
		// already reported and not grown since (by more than 1 MiB): the running command is cleaning up or reading,
		// not filling; the end-of-call check reports the state
		if reported := session.reportedUsage.Load(); reported > 0 && usage.TotalBytes <= reported+1<<20 {
			continue
		}
		session.reportedUsage.Store(usage.TotalBytes)
		session.quotaExceeded(session.newQuotaReport(reason, usage, free), measure)
	}
}

// quotaExceeded records the excess and kills every process of the session: jobs (state quotaExceeded), shells (they
// restart on the next call in their last cwd, as after a timeout) and, by uid, everything else.
func (session *Session) quotaExceeded(report *QuotaReport, measure string) {
	session.killing.Lock()
	defer session.killing.Unlock()
	session.mutex.Lock()
	if session.closed {
		session.mutex.Unlock()
		return
	}
	session.quotaReport = report
	session.quotaGen.Add(1)
	shells := make([]*Shell, 0, len(session.shells))
	for _, shell := range session.shells {
		shells = append(shells, shell)
	}
	jobs := make([]*Job, 0, len(session.jobs))
	for _, job := range session.jobs {
		jobs = append(jobs, job)
	}
	session.mutex.Unlock()
	for _, job := range jobs {
		job.mutex.Lock()
		job.quota = report
		job.mutex.Unlock()
		job.Kill(JobQuotaExceeded)
	}
	for _, shell := range shells {
		shell.Kill()
	}
	session.runner.killAll(session.UID)
	session.history.event(EventQuotaExceeded, "watcher ("+measure+"): "+quotaDetail(report)+"; "+
		strconv.Itoa(len(jobs))+" jobs and "+strconv.Itoa(len(shells))+" shells stopped")
	session.runner.logger.Warn("quota exceeded", "session", session.ID, "reason", report.Reason, "measure", measure,
		"totalBytes", report.TotalBytes, "limitBytes", report.LimitBytes, "quotaBytes", report.QuotaBytes,
		"freeBytes", report.FreeBytes, "byDirectory", report.ByDirectory, "jobs", len(jobs), "shells", len(shells))
}

// Quota report reasons.
const (
	QuotaLimit   = "LIMIT"
	QuotaNoSpace = "NO_SPACE"
)

func (session *Session) newQuotaReport(reason string, usage Usage, free int64) *QuotaReport {
	config := session.runner.config
	return &QuotaReport{Reason: reason, TotalBytes: usage.TotalBytes,
		LimitBytes: effectiveLimit(config.QuotaBytes, usage.TotalBytes, free, config.FreeReserve),
		QuotaBytes: config.QuotaBytes, FreeBytes: free, ByDirectory: usage.ByDirectory, Largest: usage.Largest}
}

// CheckQuotaAfter measures once at the end of an exec or job: a burst can fill the workspace between two watcher
// ticks. Answers a report when the output reported ENOSPC, the usage is above the quota or the workspace mount is
// (nearly) full; it kills nothing (the command has ended, the files stay for the agent to delete).
func (session *Session) CheckQuotaAfter(noSpace bool) *QuotaReport {
	config := session.runner.config
	free := session.workspaceFree()
	usage, err := session.MeasureUsage("")
	if err != nil {
		if !noSpace {
			return nil
		}
		usage = Usage{Largest: []UsagePath{}}
	}
	reason := quotaExceededBy(config.QuotaBytes, usage.TotalBytes, free, config.FreeReserve)
	if noSpace {
		reason = QuotaNoSpace
	}
	if reason == "" {
		session.reportedUsage.Store(0)
		return nil
	}
	session.reportedUsage.Store(max(usage.TotalBytes, 1))
	return session.newQuotaReport(reason, usage, free)
}

// useStatfsMeasure switches the watcher to statfs with the given measure (tests: a measure that overstates).
func (session *Session) useStatfsMeasure(measure func() int64) {
	session.mutex.Lock()
	session.statfsMeasure = measure
	session.mutex.Unlock()
	session.fastWatch.Store(true)
}

// QuotaSince answers the report when the quota was exceeded after generation gen.
func (session *Session) QuotaSince(gen int64) *QuotaReport {
	if session.quotaGen.Load() == gen {
		return nil
	}
	session.mutex.Lock()
	defer session.mutex.Unlock()
	return session.quotaReport
}

func (session *Session) touch() {
	session.lastActivity.Store(time.Now().UnixNano())
}

// Begin marks a call in flight (the idle reaper waits); the returned function ends it.
func (session *Session) Begin() func() {
	session.inflight.Add(1)
	session.touch()
	return func() {
		session.touch()
		session.inflight.Add(-1)
	}
}

func (session *Session) buildEnvironment(extra map[string]string) []string {
	config := session.runner.config
	values := map[string]string{}
	for name, value := range config.DefaultEnv {
		values[name] = value
	}
	for name, value := range extra {
		values[name] = value
	}
	values["PATH"] = config.Path
	values["HOME"] = config.Home
	values["XDG_CACHE_HOME"] = config.Home + "/.cache"
	values["XDG_CONFIG_HOME"] = config.Home + "/.config"
	values["PYTHONPYCACHEPREFIX"] = config.Home + "/.cache/pycache"
	values["MPLCONFIGDIR"] = config.Home + "/.config/matplotlib"
	values["WORKSPACE"] = config.Workspace
	values["USER"] = "agent"
	values["LOGNAME"] = "agent"
	environment := make([]string, 0, len(values))
	for name, value := range values {
		environment = append(environment, name+"="+value)
	}
	sort.Strings(environment)
	return environment
}

// helper runs one helper of this session to completion.
func (session *Session) helper(stdin io.Reader, arguments ...string) ([]byte, int, error) {
	return session.runner.runHelper(session.UID, session.env, stdin, arguments...)
}

// ResolveCwd turns the cwd of a call into an absolute path: relative paths are below the workspace.
func (session *Session) ResolveCwd(cwd string) (string, error) {
	if cwd == "" {
		return "", nil
	}
	if strings.ContainsRune(cwd, 0) || len(cwd) > 4096 {
		return "", ErrPathRefused
	}
	if filepath.IsAbs(cwd) {
		return filepath.Clean(cwd), nil
	}
	return filepath.Join(session.runner.config.Workspace, cwd), nil
}

// shell answers the named shell, starting it (again) when needed.
func (session *Session) shell(name string) (*Shell, bool, error) {
	session.killing.RLock()
	defer session.killing.RUnlock()
	session.mutex.Lock()
	defer session.mutex.Unlock()
	if session.closed {
		return nil, false, ErrSessionNotFound
	}
	existing := session.shells[name]
	if existing != nil && !existing.IsDead() {
		return existing, false, nil
	}
	cwd := session.runner.config.Workspace
	restarted := existing != nil
	if existing != nil {
		cwd = existing.Cwd()
	} else if len(session.shells) >= session.runner.config.MaxShells {
		return nil, false, fmt.Errorf("too many shells")
	}
	shell, err := StartShell(session, name, cwd)
	if err != nil {
		return nil, false, err
	}
	session.shells[name] = shell
	return shell, restarted, nil
}

// ExecResult is the answer of a foreground exec.
type ExecResult struct {
	Seq            int64         `json:"seq"`
	Status         string        `json:"status"`
	Quota          *QuotaReport  `json:"quota,omitempty"`
	Memory         *MemoryReport `json:"memory,omitempty"`
	ExitCode       *int          `json:"exitCode"`
	TimedOut       bool          `json:"timedOut"`
	DurationMs     int64         `json:"durationMs"`
	Cwd            string        `json:"cwd"`
	ShellExited    bool          `json:"shellExited,omitempty"`
	ShellRestarted bool          `json:"shellRestarted,omitempty"`
	// drive mode: waiting CLI requests (status DRIVE_REQUESTS), the time spent waiting, not-local files touched
	DriveRequests []DriveRequestView `json:"driveRequests,omitempty"`
	PausedMs      int64              `json:"pausedMs,omitempty"`
	*NotLocalHint
	RecordedOutput
}

func (session *Session) spillOpener(relativePath string) SpillOpener {
	return func() (io.WriteCloser, error) {
		command := session.runner.helperCommand(session.UID, session.env, "spill", session.runner.config.Workspace,
			relativePath, strconv.FormatUint(session.runner.config.MaxFileBytes, 10))
		stdin, err := command.StdinPipe()
		if err != nil {
			return nil, err
		}
		if err := command.Start(); err != nil {
			return nil, err
		}
		return &helperWriter{stdin: stdin, command: command}, nil
	}
}

// helperWriter feeds a helper's stdin; Close waits for it and fails when the helper failed.
type helperWriter struct {
	stdin   io.WriteCloser
	command *exec.Cmd
}

func (writer *helperWriter) Write(data []byte) (int, error) { return writer.stdin.Write(data) }

func (writer *helperWriter) Close() error {
	writer.stdin.Close()
	return writer.command.Wait()
}

func (session *Session) newRecorder(relativeSpillPath string) *Recorder {
	config := session.runner.config
	return NewRecorder(config.HeadChars, config.TailChars, config.SpillMemBytes,
		filepath.Join(config.Workspace, relativeSpillPath), session.spillOpener(relativeSpillPath))
}

// Exec runs a command in the named shell in the foreground and waits for its answer (or its first drive pause).
func (session *Session) Exec(shellName, commandText, cwd string, timeout time.Duration) (ExecResult, error) {
	run, err := session.StartExec(shellName, commandText, cwd, timeout)
	if err != nil {
		return ExecResult{}, err
	}
	return session.AwaitExec(run)
}

// StartExec starts a foreground command; AwaitExec answers it.
func (session *Session) StartExec(shellName, commandText, cwd string, timeout time.Duration) (*execRun, error) {
	shell, restarted, err := session.shell(shellName)
	if err != nil {
		return nil, err
	}
	if !shell.busy.TryLock() {
		return nil, ErrShellBusy
	}
	session.activeExecs.Add(1)
	seq := session.execSeq.Add(1)
	run := &execRun{seq: seq, shell: shell, timer: newPausableTimer(timeout), started: time.Now(),
		done: make(chan struct{}), signal: make(chan struct{}, 1),
		history: session.history.start(shellName, commandText, "", nil)}
	session.mutex.Lock()
	session.runs[seq] = run
	session.mutex.Unlock()
	startCwd := cwd
	if startCwd == "" {
		startCwd = shell.Cwd()
	}
	go func() {
		defer close(run.done)
		defer session.activeExecs.Add(-1)
		defer shell.busy.Unlock()
		defer run.timer.Stop()
		run.result, run.err = session.runExec(run, restarted, commandText, cwd, startCwd)
	}()
	return run, nil
}

func (session *Session) runExec(run *execRun, restarted bool, commandText, cwd, startCwd string) (ExecResult, error) {
	quotaGen := session.quotaGen.Load()
	memoryGen := session.memoryGen.Load()
	seq := run.seq
	recorder := session.newRecorder(fmt.Sprintf("%s/out-%d.log", RunnerDir, seq))
	session.history.attach(run.history, recorder)
	outcome, err := run.shell.executeLocked(commandText, cwd, run.timer, recorder.Stdout(), recorder.Stderr())
	// a quota or memory kill that ended this command answers only once it is complete
	session.killing.RLock()
	session.killing.RUnlock()
	if err != nil {
		session.history.finish(run.history, "FAILED", nil, time.Since(run.started), recorder.Finish())
		return ExecResult{}, err
	}
	memory := session.MemorySince(memoryGen)
	if memory != nil {
		recorder.Stderr().Write([]byte(memory.Message()))
	}
	result := ExecResult{
		Seq:            seq,
		ExitCode:       outcome.exitCode,
		TimedOut:       outcome.timedOut,
		DurationMs:     time.Since(run.started).Milliseconds(),
		PausedMs:       run.timer.PausedFor().Milliseconds(),
		Cwd:            outcome.cwd,
		ShellExited:    outcome.shellExited,
		ShellRestarted: restarted,
		RecordedOutput: recorder.Finish(),
		Status:         ExecOK,
		NotLocalHint:   session.callHint(commandText, startCwd, run.misses.list()),
	}
	var endOfCallQuota *QuotaReport
	if report := session.QuotaSince(quotaGen); report != nil {
		result.Quota = report
	} else {
		result.Quota = session.CheckQuotaAfter(result.noSpace)
		endOfCallQuota = result.Quota
	}
	result.Memory = memory
	switch {
	case result.Memory != nil:
		result.Status = ExecMemoryExceeded
	case result.Quota != nil:
		result.Status = ExecQuotaExceeded
	case result.TimedOut:
		result.Status = ExecTimedOut
	case result.ShellExited:
		result.Status = ExecShellExited
	}
	exitCode := -1
	if result.ExitCode != nil {
		exitCode = *result.ExitCode
	}
	label := "exec " + strconv.FormatInt(seq, 10)
	session.history.finish(run.history, result.Status, result.ExitCode, time.Since(run.started), result.RecordedOutput)
	session.history.recordOutcome(label, result.Status, result.ExitCode, endOfCallQuota, time.Since(run.started))
	session.runner.logger.Info("exec", "session", session.ID, "seq", seq, "durationMs", result.DurationMs,
		"pausedMs", result.PausedMs, "exitCode", exitCode, "status", result.Status, "timedOut", result.TimedOut,
		"shellExited", result.ShellExited, "stdoutBytes", result.Stdout.Bytes, "stderrBytes", result.Stderr.Bytes)
	return result, nil
}

// shellEnvironment answers the exported environment and cwd of the shell, for a background job. When the shell is
// busy or not started, the session's environment and the workspace (or the shell's last cwd) are used.
func (session *Session) shellEnvironment(shellName string) ([]string, string) {
	session.mutex.Lock()
	shell := session.shells[shellName]
	session.mutex.Unlock()
	if shell == nil || shell.IsDead() {
		cwd := session.runner.config.Workspace
		if shell != nil {
			cwd = shell.Cwd()
		}
		return session.env, cwd
	}
	captured := &limitedBuffer{limit: 1 << 20}
	outcome, err := shell.execute("command env -0", "", 10*time.Second, captured, io.Discard)
	if err != nil || outcome.exitCode == nil || *outcome.exitCode != 0 || captured.overflow {
		return session.env, shell.Cwd()
	}
	var environment []string
	for _, entry := range bytes.Split(captured.data, []byte{0}) {
		if name, _, found := strings.Cut(string(entry), "="); found && ValidEnvName(name) {
			environment = append(environment, string(entry))
		}
	}
	// PATH and HOME stay the runner's
	environment = append(environment, "PATH="+session.runner.config.Path, "HOME="+session.runner.config.Home)
	return environment, outcome.cwd
}

func (session *Session) hasRunningJobs() bool {
	session.mutex.Lock()
	defer session.mutex.Unlock()
	for _, job := range session.jobs {
		if job.Running() {
			return true
		}
	}
	return false
}

// closeAll stops every job and shell of the session.
func (session *Session) closeAll() {
	session.mutex.Lock()
	if !session.closed {
		close(session.stopWatch)
	}
	session.closed = true
	shells := session.shells
	jobs := session.jobs
	session.mutex.Unlock()
	for _, job := range jobs {
		job.Kill("released")
	}
	for _, shell := range shells {
		shell.Kill()
	}
	for _, shell := range shells {
		select {
		case <-shell.exited:
		case <-time.After(5 * time.Second):
		}
	}
	for _, job := range jobs {
		select {
		case <-job.done:
		case <-time.After(5 * time.Second):
		}
	}
}

// Manifest answers every file of the workspace (as JSON from the helper, relayed unchanged).
func (session *Session) Manifest() (json.RawMessage, int, error) {
	arguments := []string{"manifest", session.runner.config.Workspace}
	if session.drive != nil {
		arguments = append(arguments, "drive")
	}
	output, code, err := session.helper(nil, arguments...)
	if err != nil || code != 0 {
		return nil, code, err
	}
	usage, err := session.MeasureUsage("")
	if err != nil {
		return nil, -1, err
	}
	var manifest map[string]any
	if err := json.Unmarshal(output, &manifest); err != nil {
		return nil, -1, err
	}
	// totalBytes: the sum of the listed files; usedBytes: the quota's measure (disk usage below the quota
	// directories, /tmp included); limitBytes: the quota
	config := session.runner.config
	free := session.workspaceFree()
	manifest["usedBytes"] = usage.TotalBytes
	manifest["limitBytes"] = effectiveLimit(config.QuotaBytes, usage.TotalBytes, free, config.FreeReserve)
	manifest["quotaBytes"] = config.QuotaBytes
	manifest["freeBytes"] = free
	encoded, err := json.Marshal(manifest)
	return json.RawMessage(encoded), 0, err
}
