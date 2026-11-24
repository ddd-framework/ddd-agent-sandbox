package main

// The administration reads of the runner (README "Administration"): what the session of this runner holds, which
// processes run, the last commands and session events, the disk usage and one directory listing. They serve the
// admin screen "Sandboxen" through PHP. None of them counts as activity of the session (the idle release stays as it
// is), none answers the session's environment, and every command, argv and output tail passes the secret mask: each
// value of the env the session was claimed with (SecretMinChars or longer) is replaced by ***.

import (
	"encoding/json"
	"net/http"
	"os"
	"path"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
	"unicode/utf8"
)

const (
	// HistoryExecs: commands kept per session (foreground execs and background jobs)
	HistoryExecs = 100
	// HistoryEvents: session events kept per session
	HistoryEvents = 200
	// HistoryCommandBytes, HistoryTailBytes: the longest command and output tail an entry keeps
	HistoryCommandBytes = 2048
	HistoryTailBytes    = 8192
	// ProcessArgvChars: the longest argv a process view shows
	ProcessArgvChars = 512
	// ListingMaxEntries: the most entries one directory listing answers (truncated: true beyond)
	ListingMaxEntries = 2000
	// SecretMinChars: env values shorter than this are not masked (they carry no secret and "1" or "de" would mask
	// ordinary output)
	SecretMinChars = 4
	// SecretMask replaces every env value in commands, argv and tails
	SecretMask = "***"
)

// Session event types.
const (
	EventSessionClaimed = "SESSION_CLAIMED"
	EventTimeout        = "TIMEOUT"
	EventShellExited    = "SHELL_EXITED"
	EventJobKilled      = "JOB_KILLED"
	EventQuotaExceeded  = "QUOTA_EXCEEDED"
	EventMemoryExceeded = "MEMORY_EXCEEDED"
	// EventOOMSuspected: a command ended by SIGKILL (exit 137) without a runner timeout, limit or kill: most likely the
	// kernel's OOM killer inside the sandbox
	EventOOMSuspected = "OOM_SUSPECTED"
	// EventFuseLost: the placeholder file system's holder ended; new shells start without the mounts
	EventFuseLost = "FUSE_LOST"
)

// secretMask replaces the session's env values.
type secretMask struct {
	replacer *strings.Replacer
}

func newSecretMask(environment map[string]string) secretMask {
	values := make([]string, 0, len(environment))
	for _, value := range environment {
		if utf8.RuneCountInString(value) >= SecretMinChars {
			values = append(values, value)
		}
	}
	if len(values) == 0 {
		return secretMask{}
	}
	// the longest value first: a value that contains another is masked whole
	sort.Slice(values, func(left, right int) bool {
		if len(values[left]) != len(values[right]) {
			return len(values[left]) > len(values[right])
		}
		return values[left] < values[right]
	})
	pairs := make([]string, 0, 2*len(values))
	for _, value := range values {
		pairs = append(pairs, value, SecretMask)
	}
	return secretMask{replacer: strings.NewReplacer(pairs...)}
}

func (mask secretMask) apply(text string) string {
	if mask.replacer == nil {
		return text
	}
	return mask.replacer.Replace(text)
}

// headBytes keeps at most limit bytes from the start, cut at a character boundary.
func headBytes(text string, limit int) (string, bool) {
	if len(text) <= limit {
		return text, false
	}
	cut := limit
	for cut > 0 && !utf8.RuneStart(text[cut]) {
		cut--
	}
	return text[:cut], true
}

// tailBytes keeps at most limit bytes from the end, cut at a character boundary.
func tailBytes(text string, limit int) (string, bool) {
	if len(text) <= limit {
		return text, false
	}
	start := len(text) - limit
	for start < len(text) && !utf8.RuneStart(text[start]) {
		start++
	}
	return text[start:], true
}

// ExecRecord is one command of the session history: a foreground exec or a background job.
type ExecRecord struct {
	Seq       int64     `json:"seq"`
	Command   string    `json:"command"`
	Shell     string    `json:"shell"`
	StartedAt time.Time `json:"startedAt"`
	// DurationMs: so far while running
	DurationMs int64 `json:"durationMs"`
	// Status: running, or the exec status (OK, TIMED_OUT, …) or job state (exited, killed, …) at the end
	Status     string `json:"status"`
	ExitCode   *int   `json:"exitCode"`
	StdoutTail string `json:"stdoutTail"`
	StderrTail string `json:"stderrTail"`
	// Truncated: the command or a tail was cut (the runner keeps at most 2,000 characters of a long stream's tail)
	Truncated bool   `json:"truncated"`
	JobID     string `json:"jobId,omitempty"`
}

type historyEntry struct {
	record ExecRecord
	// recorder: the output so far while running (nil once finished)
	recorder *Recorder
	running  bool
}

// SessionEvent is one entry of the session's event log. Detail holds codes and numbers only.
type SessionEvent struct {
	Seq        int64     `json:"seq"`
	OccurredAt time.Time `json:"occurredAt"`
	Type       string    `json:"type"`
	Detail     string    `json:"detail"`
}

// sessionHistory keeps the last commands and events of a session.
type sessionHistory struct {
	mutex     sync.Mutex
	mask      secretMask
	execs     []*historyEntry
	execSeq   int64
	events    []SessionEvent
	eventSeq  int64
	execLimit int
}

// secretMask is the mask of the claim's env (no mask before the claim recorded one).
func (session *Session) secretMask() secretMask {
	if session.history == nil {
		return secretMask{}
	}
	return session.history.mask
}

func newSessionHistory(environment map[string]string) *sessionHistory {
	return &sessionHistory{mask: newSecretMask(environment), execLimit: HistoryExecs}
}

// start records a command that begins now; the entry is finished by finish.
func (history *sessionHistory) start(shell, command, jobID string, recorder *Recorder) *historyEntry {
	masked, cut := headBytes(history.mask.apply(command), HistoryCommandBytes)
	history.mutex.Lock()
	defer history.mutex.Unlock()
	history.execSeq++
	entry := &historyEntry{running: true, recorder: recorder, record: ExecRecord{Seq: history.execSeq, Command: masked,
		Shell: shell, StartedAt: time.Now().UTC(), Status: "running", Truncated: cut, JobID: jobID}}
	history.execs = append(history.execs, entry)
	if len(history.execs) > history.execLimit {
		history.execs = append([]*historyEntry(nil), history.execs[len(history.execs)-history.execLimit:]...)
	}
	return entry
}

// attach gives a running entry its recorder (a foreground exec creates it after the entry).
func (history *sessionHistory) attach(entry *historyEntry, recorder *Recorder) {
	if entry == nil {
		return
	}
	history.mutex.Lock()
	defer history.mutex.Unlock()
	if entry.running {
		entry.recorder = recorder
	}
}

// isClosed: the session is being released (its jobs end as killed then; no event for that).
func (session *Session) isClosed() bool {
	session.mutex.Lock()
	defer session.mutex.Unlock()
	return session.closed
}

// tails answers both output tails of a recorded output, masked and cut to HistoryTailBytes.
func (history *sessionHistory) tails(output RecordedOutput) (string, string, bool) {
	stdout, stdoutCut := tailBytes(history.mask.apply(streamText(output.Stdout)), HistoryTailBytes)
	stderr, stderrCut := tailBytes(history.mask.apply(streamText(output.Stderr)), HistoryTailBytes)
	return stdout, stderr, stdoutCut || stderrCut || output.Stdout.Truncated || output.Stderr.Truncated
}

// streamText is the end of one stream: the whole stream when it fit, else its tail.
func streamText(view OutputView) string {
	if view.Truncated {
		return view.Tail
	}
	return view.Head + view.Tail
}

// finish completes an entry with its status, exit code and output.
func (history *sessionHistory) finish(entry *historyEntry, status string, exitCode *int, duration time.Duration, output RecordedOutput) {
	if entry == nil {
		return
	}
	stdout, stderr, cut := history.tails(output)
	history.mutex.Lock()
	defer history.mutex.Unlock()
	entry.running = false
	entry.recorder = nil
	entry.record.Status = status
	entry.record.ExitCode = exitCode
	entry.record.DurationMs = duration.Milliseconds()
	entry.record.StdoutTail, entry.record.StderrTail = stdout, stderr
	entry.record.Truncated = entry.record.Truncated || cut
}

// Execs answers the last limit commands, the newest first; running ones with their output so far.
func (history *sessionHistory) Execs(limit int) []ExecRecord {
	history.mutex.Lock()
	entries := append([]*historyEntry(nil), history.execs...)
	history.mutex.Unlock()
	records := make([]ExecRecord, 0, min(limit, len(entries)))
	for index := len(entries) - 1; index >= 0 && len(records) < limit; index-- {
		history.mutex.Lock()
		record, recorder, running := entries[index].record, entries[index].recorder, entries[index].running
		history.mutex.Unlock()
		if running {
			record.DurationMs = time.Since(record.StartedAt).Milliseconds()
			if recorder != nil {
				stdout, stderr, cut := history.tails(recorder.Snapshot())
				record.StdoutTail, record.StderrTail = stdout, stderr
				record.Truncated = record.Truncated || cut
			}
		}
		records = append(records, record)
	}
	return records
}

// event records a session event; detail must hold codes and numbers only (never commands, output or file names).
func (history *sessionHistory) event(eventType, detail string) {
	history.mutex.Lock()
	defer history.mutex.Unlock()
	history.eventSeq++
	history.events = append(history.events, SessionEvent{Seq: history.eventSeq, OccurredAt: time.Now().UTC(),
		Type: eventType, Detail: detail})
	if len(history.events) > HistoryEvents {
		history.events = append([]SessionEvent(nil), history.events[len(history.events)-HistoryEvents:]...)
	}
}

// Events answers the last limit events, the newest first.
func (history *sessionHistory) Events(limit int) []SessionEvent {
	history.mutex.Lock()
	defer history.mutex.Unlock()
	events := make([]SessionEvent, 0, min(limit, len(history.events)))
	for index := len(history.events) - 1; index >= 0 && len(events) < limit; index-- {
		events = append(events, history.events[index])
	}
	return events
}

// recordOutcome adds the events a finished command implies. label names it ("exec 7", "job j_3_ab12cd34");
// endOfCallQuota is the report of the end-of-call measurement (the watcher's excesses are recorded where they kill).
func (history *sessionHistory) recordOutcome(label, status string, exitCode *int, endOfCallQuota *QuotaReport, duration time.Duration) {
	switch {
	case status == ExecMemoryExceeded || status == JobMemoryExceeded:
		// recorded by the watcher
	case endOfCallQuota != nil:
		history.event(EventQuotaExceeded, label+" (end-of-call check): "+quotaDetail(endOfCallQuota))
	case status == ExecTimedOut || status == JobTimedOut:
		history.event(EventTimeout, label+" after "+strconv.FormatInt(duration.Milliseconds(), 10)+" ms")
	case status == ExecShellExited:
		history.event(EventShellExited, label+": the command ended its shell")
	case status == JobKilled:
		history.event(EventJobKilled, label)
	case exitCode != nil && *exitCode == 137 && (status == ExecOK || status == JobExited):
		history.event(EventOOMSuspected, label+": ended by SIGKILL (exit 137) without a runner timeout or limit")
	}
}

func quotaDetail(report *QuotaReport) string {
	return report.Reason + ", " + strconv.FormatInt(report.TotalBytes, 10) + " bytes used, limit " +
		strconv.FormatInt(report.LimitBytes, 10)
}

// observedSession resolves {id} for an administration read: no activity is recorded (the idle release must not see
// an admin looking as use), but the call counts as in flight so the session is not released under it.
func (api *API) observedSession(writer http.ResponseWriter, request *http.Request) (*Session, func(), bool) {
	api.runner.mutex.Lock()
	session := api.runner.session
	busy := api.runner.state == StateBusy
	api.runner.mutex.Unlock()
	if session == nil || !busy || session.ID != request.PathValue("id") {
		writeError(writer, http.StatusNotFound, "SESSION_NOT_FOUND", "no such session on this runner")
		return nil, nil, false
	}
	session.inflight.Add(1)
	return session, func() { session.inflight.Add(-1) }, true
}

// queryLimit reads ?limit= (1 … ceiling, default ceiling); ok false: it answered 400.
func queryLimit(writer http.ResponseWriter, request *http.Request, ceiling int) (int, bool) {
	text := request.URL.Query().Get("limit")
	if text == "" {
		return ceiling, true
	}
	limit, err := strconv.Atoi(text)
	if err != nil || limit < 1 || limit > ceiling {
		writeError(writer, http.StatusBadRequest, "INVALID_LIMIT", "limit must be between 1 and "+strconv.Itoa(ceiling))
		return 0, false
	}
	return limit, true
}

// SessionSummary is one entry of GET /v1/sessions.
type SessionSummary struct {
	SessionID        string    `json:"sessionId"`
	WorkspaceKey     string    `json:"workspaceKey"`
	CreatedAt        time.Time `json:"createdAt"`
	LastActivityAt   time.Time `json:"lastActivityAt"`
	MemoryBytes      int64     `json:"memoryBytes"`
	MemoryPeakBytes  int64     `json:"memoryPeakBytes"`
	MemoryLimitBytes int64     `json:"memoryLimitBytes"`
	// MemoryMeasure: cgroup-v1, cgroup-v2, rss or off
	MemoryMeasure  string `json:"memoryMeasure"`
	DiskUsedBytes  int64  `json:"diskUsedBytes"`
	DiskQuotaBytes int64  `json:"diskQuotaBytes"`
	// DiskMeasure: exact (the walk) or statfs (the mounts' used bytes above their state at claim)
	DiskMeasure string `json:"diskMeasure"`
	// MountMode: FUSE (placeholders, streamed), PLAIN (Plan B, or a legacy session without drive mounts)
	MountMode       string `json:"mountMode"`
	MountModeReason string `json:"mountModeReason"`
	// Mode: legacy or drive
	Mode             string `json:"mode"`
	Pod              string `json:"pod"`
	RunningJobCount  int    `json:"runningJobCount"`
	RunningExecCount int    `json:"runningExecCount"`
	ShellCount       int    `json:"shellCount"`
}

// podName is the pod's name (HOSTNAME in Kubernetes).
func podName() string {
	name, err := os.Hostname()
	if err != nil {
		return ""
	}
	return name
}

// summary answers the session's state now. The disk usage comes from statfs where every quota directory is its own
// mount (milliseconds), else from the exact walk.
func (session *Session) summary() SessionSummary {
	runner := session.runner
	config := runner.config
	summary := SessionSummary{
		SessionID: session.ID, WorkspaceKey: session.WorkspaceKey, CreatedAt: session.created.UTC(),
		LastActivityAt:   time.Unix(0, session.lastActivity.Load()).UTC(),
		MemoryLimitBytes: config.MemoryLimitBytes, MemoryMeasure: runner.memory.mode,
		DiskQuotaBytes: config.QuotaBytes, Pod: podName(), Mode: "legacy", MountMode: "PLAIN",
		MountModeReason:  "legacy session: no drive mounts",
		RunningExecCount: int(session.activeExecs.Load()),
	}
	if runner.memory.mode != MemoryOff {
		session.mutex.Lock()
		measure := session.memoryMeasure
		session.mutex.Unlock()
		if used, err := measure(); err == nil {
			summary.MemoryBytes = used
			session.notePeak(used)
		}
	}
	summary.MemoryPeakBytes = max(session.memoryPeak.Load(), summary.MemoryBytes)
	if runner.quotaMountsOwn {
		summary.DiskUsedBytes, summary.DiskMeasure = session.statfsUsage(), "statfs"
	} else if usage, err := session.MeasureUsage(""); err == nil {
		summary.DiskUsedBytes, summary.DiskMeasure = usage.TotalBytes, "exact"
	}
	summary.DiskUsedBytes = max(summary.DiskUsedBytes, 0)
	session.mutex.Lock()
	summary.ShellCount = len(session.shells)
	for _, job := range session.jobs {
		if job.Running() {
			summary.RunningJobCount++
		}
	}
	session.mutex.Unlock()
	if state := session.drive; state != nil {
		summary.Mode = "drive"
		state.mutex.Lock()
		filesystem := state.MountFilesystem
		state.mutex.Unlock()
		summary.MountMode, summary.MountModeReason = mountModeOf(filesystem, len(state.Mounts))
	}
	return summary
}

// mountModeOf maps the drive state's mountFilesystem ("fuse", "plain", "plain: <reason>") to FUSE or PLAIN.
func mountModeOf(filesystem string, mounts int) (string, string) {
	switch {
	case filesystem == "fuse":
		return "FUSE", ""
	case strings.HasPrefix(filesystem, "plain: "):
		return "PLAIN", strings.TrimPrefix(filesystem, "plain: ")
	case mounts == 0:
		return "PLAIN", "no mounted spaces"
	}
	return "PLAIN", "the placeholder file system is switched off or not offered on this runner"
}

// notePeak raises the session's memory peak.
func (session *Session) notePeak(used int64) {
	for {
		peak := session.memoryPeak.Load()
		if used <= peak || session.memoryPeak.CompareAndSwap(peak, used) {
			return
		}
	}
}

// listSessions: GET /v1/sessions, the session of this runner (none or one).
func (api *API) listSessions(writer http.ResponseWriter, _ *http.Request) {
	api.runner.mutex.Lock()
	session := api.runner.session
	state := api.runner.state
	api.runner.mutex.Unlock()
	sessions := []SessionSummary{}
	if session != nil && state == StateBusy {
		session.inflight.Add(1)
		sessions = append(sessions, session.summary())
		session.inflight.Add(-1)
	}
	writeJSON(writer, http.StatusOK, map[string]any{"pod": podName(), "state": state, "sessions": sessions})
}

// ProcessView is one process of the session (GET …/processes). Never its environment.
type ProcessView struct {
	PID        int        `json:"pid"`
	Argv       string     `json:"argv"`
	Truncated  bool       `json:"truncated"`
	StartedAt  *time.Time `json:"startedAt"`
	CPUSeconds float64    `json:"cpuSeconds"`
	RSSBytes   int64      `json:"rssBytes"`
	JobID      string     `json:"jobId,omitempty"`
}

// clockTicks is USER_HZ, 100 on every Linux the runner runs on (amd64, arm64).
const clockTicks = 100

// bootTime reads btime of /proc/stat (zero when unknown).
func bootTime() time.Time {
	data, err := os.ReadFile("/proc/stat")
	if err != nil {
		return time.Time{}
	}
	for _, line := range strings.Split(string(data), "\n") {
		if value, found := strings.CutPrefix(line, "btime "); found {
			seconds, err := strconv.ParseInt(strings.TrimSpace(value), 10, 64)
			if err == nil {
				return time.Unix(seconds, 0).UTC()
			}
		}
	}
	return time.Time{}
}

// processStat answers the parent pid, cpu ticks (user plus system) and the start in ticks after boot.
func processStat(pid int) (parent int, cpuTicks int64, startTicks int64, ok bool) {
	data, err := os.ReadFile("/proc/" + strconv.Itoa(pid) + "/stat")
	if err != nil {
		return 0, 0, 0, false
	}
	closing := strings.LastIndexByte(string(data), ')')
	if closing < 0 || closing+2 > len(data) {
		return 0, 0, 0, false
	}
	// fields from field 3 (state) on: ppid is field 4, utime 14, stime 15, starttime 22
	fields := strings.Fields(string(data[closing+2:]))
	if len(fields) < 20 {
		return 0, 0, 0, false
	}
	parent, _ = strconv.Atoi(fields[1])
	userTicks, _ := strconv.ParseInt(fields[11], 10, 64)
	systemTicks, _ := strconv.ParseInt(fields[12], 10, 64)
	startTicks, _ = strconv.ParseInt(fields[19], 10, 64)
	return parent, userTicks + systemTicks, startTicks, true
}

// processArgv reads /proc/<pid>/cmdline (argv only), joined by spaces, masked, cut to ProcessArgvChars.
func processArgv(pid int, mask secretMask) (string, bool) {
	data, err := os.ReadFile("/proc/" + strconv.Itoa(pid) + "/cmdline")
	if err != nil {
		return "", false
	}
	text := mask.apply(strings.TrimRight(strings.ReplaceAll(string(data), "\x00", " "), " "))
	text = strings.ToValidUTF8(text, "�")
	if utf8.RuneCountInString(text) <= ProcessArgvChars {
		return text, false
	}
	return string([]rune(text)[:ProcessArgvChars]), true
}

// Processes answers every process of the session with its job (the job's bash or a descendant of it).
func (session *Session) Processes() []ProcessView {
	session.mutex.Lock()
	jobsByPID := map[int]string{}
	for _, job := range session.jobs {
		if job.Running() {
			jobsByPID[job.pid] = job.ID
		}
	}
	session.mutex.Unlock()
	booted := bootTime()
	pids := session.sessionPIDs()
	parents := map[int]int{}
	views := make([]ProcessView, 0, len(pids))
	for _, pid := range pids {
		_, rss, alive := processStatus(pid)
		if !alive {
			continue
		}
		parent, cpuTicks, startTicks, ok := processStat(pid)
		if !ok {
			continue
		}
		parents[pid] = parent
		argv, cut := processArgv(pid, session.history.mask)
		view := ProcessView{PID: pid, Argv: argv, Truncated: cut, CPUSeconds: float64(cpuTicks) / clockTicks,
			RSSBytes: rss}
		if !booted.IsZero() {
			started := booted.Add(time.Duration(startTicks) * time.Second / clockTicks)
			view.StartedAt = &started
		}
		views = append(views, view)
	}
	// the job: the nearest ancestor that is a job's bash (a job's process group may have been left by setsid)
	for index := range views {
		for pid, depth := views[index].PID, 0; pid > 1 && depth < 64; depth++ {
			if jobID, found := jobsByPID[pid]; found {
				views[index].JobID = jobID
				break
			}
			parent, known := parents[pid]
			if !known {
				parent, _, _, _ = processStat(pid)
			}
			pid = parent
		}
	}
	sort.Slice(views, func(left, right int) bool { return views[left].PID < views[right].PID })
	return views
}

func (api *API) listProcesses(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.observedSession(writer, request)
	if !ok {
		return
	}
	defer end()
	writeJSON(writer, http.StatusOK, map[string]any{"processes": session.Processes()})
}

func (api *API) listExecs(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.observedSession(writer, request)
	if !ok {
		return
	}
	defer end()
	limit, ok := queryLimit(writer, request, HistoryExecs)
	if !ok {
		return
	}
	writeJSON(writer, http.StatusOK, map[string]any{"execs": session.history.Execs(limit)})
}

func (api *API) listEvents(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.observedSession(writer, request)
	if !ok {
		return
	}
	defer end()
	limit, ok := queryLimit(writer, request, HistoryEvents)
	if !ok {
		return
	}
	writeJSON(writer, http.StatusOK, map[string]any{"events": session.history.Events(limit)})
}

func (api *API) readDisk(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.observedSession(writer, request)
	if !ok {
		return
	}
	defer end()
	usage, err := session.MeasureUsage("")
	if err != nil {
		writeError(writer, http.StatusInternalServerError, "USAGE_FAILED", "the workspace usage could not be measured")
		return
	}
	config := api.runner.config
	free := session.workspaceFree()
	writeJSON(writer, http.StatusOK, map[string]any{"usedBytes": usage.TotalBytes, "quotaBytes": config.QuotaBytes,
		"limitBytes": effectiveLimit(config.QuotaBytes, usage.TotalBytes, free, config.FreeReserve),
		"freeBytes":  free, "byDirectory": usage.ByDirectory})
}

// ListingEntry is one entry of a directory listing.
type ListingEntry struct {
	Name string `json:"name"`
	// Type: file, dir, symlink or other
	Type  string `json:"type"`
	Size  int64  `json:"size"`
	Mtime string `json:"mtime,omitempty"`
	// NotLocal: a drive file that is only listed (placeholder), not in the working copy
	NotLocal bool `json:"notLocal,omitempty"`
}

// Listing is the answer of GET …/files?path=.
type Listing struct {
	Path      string         `json:"path"`
	Entries   []ListingEntry `json:"entries"`
	Truncated bool           `json:"truncated"`
}

// listFiles: GET /v1/sessions/{id}/files?path= lists one directory of the workspace (empty path: the root). The
// helper lists as the session uid through os.Root (no way out by symlink or ..); the mounts of a streamed session
// are listed from the drive state (outside the session's namespace their folder is empty).
func (api *API) listFiles(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.observedSession(writer, request)
	if !ok {
		return
	}
	defer end()
	relativePath := "."
	if text := request.URL.Query().Get("path"); text != "" && text != "." && text != "/" {
		clean, err := CleanRelativePath(strings.TrimPrefix(text, "/"))
		if err != nil {
			writeError(writer, http.StatusBadRequest, "PATH_REFUSED", "the path must be relative, inside the workspace, without ..")
			return
		}
		relativePath = clean
	}
	if relativePath != "." && LeadsIntoRunnerDir(api.runner.config.Workspace, relativePath) {
		// the runner's own folder is no part of the workspace view (its files carry unmasked command output)
		writeError(writer, http.StatusBadRequest, "PATH_REFUSED", "the runner's own folder is not listed")
		return
	}
	if session.drive != nil && session.drive.Streamed &&
		(relativePath == DriveSpacesDir || strings.HasPrefix(relativePath, DriveSpacesDir+"/")) {
		listing, found := session.drive.streamedListing(relativePath)
		if !found {
			writeError(writer, http.StatusNotFound, "FILE_NOT_FOUND", "no such directory")
			return
		}
		writeJSON(writer, http.StatusOK, listing)
		return
	}
	output, exitCode, err := session.helper(nil, "list", api.runner.config.Workspace, relativePath,
		strconv.Itoa(ListingMaxEntries))
	if err != nil {
		writeError(writer, http.StatusInternalServerError, "FILE_OPERATION_FAILED", "the directory could not be listed")
		return
	}
	if status, code := helperStatus(exitCode); status != http.StatusOK {
		if code == "NOT_A_REGULAR_FILE" {
			code = "NOT_A_DIRECTORY"
		}
		writeError(writer, status, code, "the directory could not be listed")
		return
	}
	var listing Listing
	if err := json.Unmarshal(output, &listing); err != nil {
		writeError(writer, http.StatusInternalServerError, "FILE_OPERATION_FAILED", "the directory could not be listed")
		return
	}
	if relativePath == "." {
		listing.Path = ""
		// the runner's own folders are no part of the workspace view
		kept := listing.Entries[:0]
		for _, entry := range listing.Entries {
			if entry.Name != lostAndFound && entry.Name != RunnerDir {
				kept = append(kept, entry)
			}
		}
		listing.Entries = kept
	}
	writeJSON(writer, http.StatusOK, listing)
}

// streamedListing lists a folder below drivespaces/ from the drive state: the mounts, or a mount's folder with its
// files (local or only listed) and the generated SPACE.md and .drive-index.
func (state *DriveState) streamedListing(relativePath string) (Listing, bool) {
	state.mutex.Lock()
	defer state.mutex.Unlock()
	listing := Listing{Path: relativePath, Entries: []ListingEntry{}}
	if relativePath == DriveSpacesDir {
		for name := range state.Mounts {
			listing.Entries = append(listing.Entries, ListingEntry{Name: name, Type: "dir"})
		}
		sortListing(listing.Entries)
		return listing, true
	}
	rest := strings.TrimPrefix(relativePath, DriveSpacesDir+"/")
	name, inside, _ := strings.Cut(rest, "/")
	mount := state.Mounts[name]
	if mount == nil {
		return listing, false
	}
	prefix := ""
	if inside != "" {
		prefix = inside + "/"
	} else {
		listing.Entries = append(listing.Entries, ListingEntry{Name: DriveSpaceFile, Type: "file",
			Size: int64(len(mount.SpaceMD))}, ListingEntry{Name: DriveIndexFile, Type: "file"})
	}
	folders := map[string]bool{}
	found := inside == ""
	for entryPath, entry := range mount.Entries {
		below, isBelow := strings.CutPrefix(entryPath, prefix)
		if !isBelow || below == "" {
			continue
		}
		found = true
		if child, _, nested := strings.Cut(below, "/"); nested {
			folders[child] = true
			continue
		}
		kind := "file"
		if entry.LinkTarget != "" {
			kind = "symlink"
		}
		listing.Entries = append(listing.Entries, ListingEntry{Name: below, Type: kind, Size: entry.Size,
			NotLocal: !entry.Local})
	}
	for folder := range folders {
		listing.Entries = append(listing.Entries, ListingEntry{Name: folder, Type: "dir"})
	}
	if !found {
		return listing, false
	}
	sortListing(listing.Entries)
	if len(listing.Entries) > ListingMaxEntries {
		listing.Entries, listing.Truncated = listing.Entries[:ListingMaxEntries], true
	}
	return listing, true
}

// sortListing: folders first, then by name.
func sortListing(entries []ListingEntry) {
	sort.Slice(entries, func(left, right int) bool {
		if (entries[left].Type == "dir") != (entries[right].Type == "dir") {
			return entries[left].Type == "dir"
		}
		return entries[left].Name < entries[right].Name
	})
}

// helperList lists one directory below the workspace as the session uid (os.Root: symlinks that lead out and ..
// are refused); at most limit entries.
func helperList(workspace, relativePath, limitText string) error {
	limit, err := strconv.Atoi(limitText)
	if err != nil || limit < 1 {
		return ErrPathRefused
	}
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	directory, err := root.Open(relativePath)
	if err != nil {
		return err
	}
	defer directory.Close()
	info, err := directory.Stat()
	if err != nil {
		return err
	}
	if !info.IsDir() {
		return syscall.ENOTDIR
	}
	names, err := directory.Readdirnames(-1)
	if err != nil {
		return err
	}
	sort.Strings(names)
	listing := Listing{Path: relativePath, Entries: []ListingEntry{}}
	for _, name := range names {
		entryInfo, err := root.Lstat(path.Join(relativePath, name))
		if err != nil {
			continue
		}
		entry := ListingEntry{Name: name, Type: "other", Size: entryInfo.Size(),
			Mtime: entryInfo.ModTime().UTC().Format(time.RFC3339)}
		switch mode := entryInfo.Mode(); {
		case mode.IsDir():
			entry.Type, entry.Size = "dir", 0
		case mode.IsRegular():
			entry.Type = "file"
		case mode&os.ModeSymlink != 0:
			entry.Type = "symlink"
		}
		listing.Entries = append(listing.Entries, entry)
	}
	sortListing(listing.Entries)
	if len(listing.Entries) > limit {
		listing.Entries, listing.Truncated = listing.Entries[:limit], true
	}
	return json.NewEncoder(os.Stdout).Encode(listing)
}
