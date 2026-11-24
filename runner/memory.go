package main

// Memory per session (README "Memory"). The pod's memory limit belongs to the host: past it the host kills
// gvisor_sentry and every session of the pod is lost. The runner therefore keeps each session below its own limit:
// it measures the session's memory every RUNNER_SESSION_MEMORY_INTERVAL_MS and, above RUNNER_SESSION_MEMORY_LIMIT_BYTES,
// ends every process of the session (as the disk quota does) and answers MEMORY_EXCEEDED. The runner survives.
//
// The measure, by preference:
//   - cgroup: every session process is placed in a cgroup of its own (sandbox-session below the cgroup root). gVisor's
//     memory cgroup enforces no limit, but its usage counts what the session really holds: anonymous memory once
//     however many forked processes share it, plus tmpfs files (/tmp, /dev/shm) its processes wrote; the page cache
//     of the workspace volume (9p) does not count. Measured 2026-11-23 under runsc release-20261102.0.
//   - rss: the sum of VmRSS of the session uid's processes (where no cgroup can be made). Shared pages count once per
//     process, so it overstates forked workers.
// RLIMIT_AS is no measure: Chromium and V8 reserve tens of GiB of address space. RLIMIT_DATA does not bind
// anonymous mmap under gVisor (measured: 1 GiB allocated under a 512 MiB RLIMIT_DATA).

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// Memory measures.
const (
	MemoryCgroupV1 = "cgroup-v1"
	MemoryCgroupV2 = "cgroup-v2"
	MemoryRSS      = "rss"
	MemoryOff      = "off"
	// sessionCgroupName is the cgroup of the one session below the cgroup root
	sessionCgroupName = "sandbox-session"
)

// MemoryReport is the answer part of a memory excess.
type MemoryReport struct {
	UsedBytes  int64 `json:"usedBytes"`
	LimitBytes int64 `json:"limitBytes"`
	// Measure: cgroup-v1, cgroup-v2 or rss
	Measure string          `json:"measure"`
	Largest []MemoryProcess `json:"largest"`
	// RemovedShmBytes: files of the session in /dev/shm (memory) that were removed because they alone kept the
	// session above its limit
	RemovedShmBytes int64 `json:"removedShmBytes,omitempty"`
}

// MemoryProcess is one of the largest processes at the moment of the excess.
type MemoryProcess struct {
	PID      int    `json:"pid"`
	Command  string `json:"command"`
	RSSBytes int64  `json:"rssBytes"`
}

// Message is the line the session's output gets.
func (report *MemoryReport) Message() string {
	var largest []string
	for _, process := range report.Largest {
		largest = append(largest, fmt.Sprintf("%s %s", process.Command, humanBytes(process.RSSBytes)))
	}
	message := fmt.Sprintf("sandbox: memory limit of the sandbox session exceeded (%s used, limit %s): every process "+
		"of the session was stopped", humanBytes(report.UsedBytes), humanBytes(report.LimitBytes))
	if len(largest) > 0 {
		message += "; largest: " + strings.Join(largest, ", ")
	}
	if report.RemovedShmBytes > 0 {
		message += fmt.Sprintf("; files in /dev/shm (%s) were removed", humanBytes(report.RemovedShmBytes))
	}
	return message + ". Use less memory (smaller chunks, fewer parallel workers, a smaller page) and run it again.\n"
}

// memoryCgroups is the cgroup setup the runner found at start.
type memoryCgroups struct {
	mode string
	// directory: the session cgroup (empty in rss mode)
	directory string
}

func (cgroups memoryCgroups) usageFile() string {
	if cgroups.mode == MemoryCgroupV2 {
		return "memory.current"
	}
	return "memory.usage_in_bytes"
}

// detectMemoryCgroups finds a cgroup the runner may create and that reports memory usage: v1 below
// <root>/memory (gVisor's default), else v2 at <root>. Without one the measure is rss. A leftover session cgroup of
// an earlier process is emptied (its processes killed) and reused.
func detectMemoryCgroups(setting, root string) memoryCgroups {
	switch setting {
	case MemoryOff:
		return memoryCgroups{mode: MemoryOff}
	case MemoryRSS:
		return memoryCgroups{mode: MemoryRSS}
	}
	candidates := []memoryCgroups{
		{mode: MemoryCgroupV1, directory: filepath.Join(root, "memory", sessionCgroupName)},
		{mode: MemoryCgroupV2, directory: filepath.Join(root, sessionCgroupName)},
	}
	for _, candidate := range candidates {
		if err := os.Mkdir(candidate.directory, 0o755); err != nil && !errors.Is(err, fs.ErrExist) {
			continue
		}
		if _, err := os.Stat(filepath.Join(candidate.directory, candidate.usageFile())); err != nil {
			os.Remove(candidate.directory)
			continue
		}
		if _, err := os.Stat(filepath.Join(candidate.directory, "cgroup.procs")); err != nil {
			os.Remove(candidate.directory)
			continue
		}
		killCgroup(candidate.directory)
		return candidate
	}
	return memoryCgroups{mode: MemoryRSS}
}

// cgroupPIDs lists the processes of a cgroup.
func cgroupPIDs(directory string) []int {
	data, err := os.ReadFile(filepath.Join(directory, "cgroup.procs"))
	if err != nil {
		return nil
	}
	var pids []int
	for _, field := range strings.Fields(string(data)) {
		if pid, err := strconv.Atoi(field); err == nil && pid > 0 {
			pids = append(pids, pid)
		}
	}
	return pids
}

// killCgroup kills every process of the cgroup until it is empty (a few rounds catch forks meanwhile).
func killCgroup(directory string) int {
	killed := 0
	for round := 0; round < 50; round++ {
		pids := cgroupPIDs(directory)
		if len(pids) == 0 {
			return killed
		}
		for _, pid := range pids {
			if syscall.Kill(pid, syscall.SIGKILL) == nil {
				killed++
			}
		}
		time.Sleep(10 * time.Millisecond)
	}
	return killed
}

// adoptProcess places a freshly started session process in the session cgroup (before it may run anything).
func (session *Session) adoptProcess(pid int) {
	cgroups := session.runner.memory
	if cgroups.directory == "" {
		return
	}
	if err := os.WriteFile(filepath.Join(cgroups.directory, "cgroup.procs"), []byte(strconv.Itoa(pid)), 0o644); err != nil {
		session.runner.adoptFailures.Add(1)
		if session.runner.adoptFailures.Load() == 1 {
			session.runner.logger.Warn("session process not placed in the memory cgroup; the memory measure misses it",
				"session", session.ID, "mode", cgroups.mode)
		}
	}
}

// startAdopted starts a session process (a helper command) that waits for the runner first: once it is placed in the
// session cgroup it continues, so everything it starts is measured.
func (session *Session) startAdopted(command *exec.Cmd) error {
	syncReader, syncWriter, err := os.Pipe()
	if err != nil {
		return err
	}
	descriptor := 3 + len(command.ExtraFiles)
	command.ExtraFiles = append(command.ExtraFiles, syncReader)
	for index, argument := range command.Args {
		if argument == helperArgument {
			command.Args = slices.Insert(command.Args, index+1, helperSyncPrefix+strconv.Itoa(descriptor))
			break
		}
	}
	startErr := command.Start()
	syncReader.Close()
	if startErr != nil {
		syncWriter.Close()
		return startErr
	}
	session.adoptProcess(command.Process.Pid)
	syncWriter.Write([]byte{1})
	syncWriter.Close()
	return nil
}

// processStatus answers uid, VmRSS (bytes) and liveness from /proc/<pid>/status.
func processStatus(pid int) (uid int, rss int64, alive bool) {
	data, err := os.ReadFile("/proc/" + strconv.Itoa(pid) + "/status")
	if err != nil {
		return -1, 0, false
	}
	uid = -1
	alive = true
	for _, line := range strings.Split(string(data), "\n") {
		name, value, found := strings.Cut(line, ":")
		if !found {
			continue
		}
		fields := strings.Fields(value)
		switch name {
		case "Uid":
			if len(fields) > 0 {
				uid, _ = strconv.Atoi(fields[0])
			}
		case "VmRSS":
			if len(fields) > 0 {
				kib, _ := strconv.ParseInt(fields[0], 10, 64)
				rss = kib << 10
			}
		case "State":
			alive = len(fields) == 0 || fields[0] != "Z"
		}
	}
	return uid, rss, alive
}

// sessionPIDs lists the session's processes: the cgroup's, or every process of the session uid.
func (session *Session) sessionPIDs() []int {
	if directory := session.runner.memory.directory; directory != "" {
		return cgroupPIDs(directory)
	}
	if !session.runner.privileged {
		return nil
	}
	var pids []int
	entries, _ := os.ReadDir("/proc")
	for _, entry := range entries {
		pid, err := strconv.Atoi(entry.Name())
		if err != nil {
			continue
		}
		if uid, _, alive := processStatus(pid); alive && uid == session.UID {
			pids = append(pids, pid)
		}
	}
	return pids
}

// memoryUsage measures the session: the cgroup's usage, or the sum of the session processes' RSS.
func (session *Session) memoryUsage() (int64, error) {
	cgroups := session.runner.memory
	if cgroups.directory != "" {
		data, err := os.ReadFile(filepath.Join(cgroups.directory, cgroups.usageFile()))
		if err != nil {
			return 0, err
		}
		return strconv.ParseInt(strings.TrimSpace(string(data)), 10, 64)
	}
	var total int64
	for _, pid := range session.sessionPIDs() {
		_, rss, _ := processStatus(pid)
		total += rss
	}
	return total, nil
}

// largestProcesses names the three largest processes of the session by RSS.
func (session *Session) largestProcesses() []MemoryProcess {
	processes := []MemoryProcess{}
	for _, pid := range session.sessionPIDs() {
		_, rss, alive := processStatus(pid)
		if !alive || rss == 0 {
			continue
		}
		command, _ := os.ReadFile("/proc/" + strconv.Itoa(pid) + "/comm")
		processes = append(processes, MemoryProcess{PID: pid, Command: strings.TrimSpace(string(command)), RSSBytes: rss})
	}
	sort.Slice(processes, func(left, right int) bool { return processes[left].RSSBytes > processes[right].RSSBytes })
	if len(processes) > 3 {
		processes = processes[:3]
	}
	return processes
}

// watchMemory measures the session every MemoryInterval while it lives (background processes grow between calls
// too). Above the limit it ends every process of the session; a session that stays above it without growing (files in
// a memory disk) is not killed again for that.
func (session *Session) watchMemory() {
	config := session.runner.config
	if config.MemoryLimitBytes <= 0 || session.runner.memory.mode == MemoryOff {
		return
	}
	ticker := time.NewTicker(config.MemoryInterval)
	defer ticker.Stop()
	for {
		select {
		case <-session.stopWatch:
			return
		case <-ticker.C:
		}
		session.mutex.Lock()
		measure := session.memoryMeasure
		session.mutex.Unlock()
		used, err := measure()
		if err != nil {
			continue
		}
		session.notePeak(used)
		if used <= config.MemoryLimitBytes {
			session.memoryReported.Store(0)
			continue
		}
		if reported := session.memoryReported.Load(); reported > 0 && used <= reported+16<<20 {
			continue
		}
		session.memoryExceeded(used)
	}
}

// memoryExceeded records the excess and ends every process of the session: jobs (state memoryExceeded), shells (they
// restart on the next call in their last cwd) and, by cgroup and uid, everything else. When the session is still above
// its limit without a process, its files in /dev/shm hold the memory: they are removed.
func (session *Session) memoryExceeded(used int64) {
	session.killing.Lock()
	defer session.killing.Unlock()
	session.mutex.Lock()
	if session.closed {
		session.mutex.Unlock()
		return
	}
	shells := make([]*Shell, 0, len(session.shells))
	for _, shell := range session.shells {
		shells = append(shells, shell)
	}
	jobs := make([]*Job, 0, len(session.jobs))
	for _, job := range session.jobs {
		jobs = append(jobs, job)
	}
	session.mutex.Unlock()
	config := session.runner.config
	report := &MemoryReport{UsedBytes: used, LimitBytes: config.MemoryLimitBytes, Measure: session.runner.memory.mode,
		Largest: session.largestProcesses()}
	session.mutex.Lock()
	session.memoryReport = report
	session.mutex.Unlock()
	for _, job := range jobs {
		job.mutex.Lock()
		job.memory = report
		job.mutex.Unlock()
		job.Kill(JobMemoryExceeded)
	}
	for _, shell := range shells {
		shell.Kill()
	}
	session.killProcesses()
	session.mutex.Lock()
	measure := session.memoryMeasure
	session.mutex.Unlock()
	after := used
	for wait := 0; wait < 20; wait++ {
		if after, _ = measure(); after <= config.MemoryLimitBytes {
			break
		}
		time.Sleep(25 * time.Millisecond)
	}
	if after > config.MemoryLimitBytes {
		report.RemovedShmBytes = session.removeShmFiles()
		after, _ = measure()
	}
	session.memoryReported.Store(max(after, 1))
	// the generation moves last: a call that sees it also sees the complete report
	session.memoryGen.Add(1)
	session.history.event(EventMemoryExceeded, strconv.FormatInt(used, 10)+" bytes used, limit "+
		strconv.FormatInt(report.LimitBytes, 10)+" ("+report.Measure+"); "+strconv.Itoa(len(jobs))+" jobs and "+
		strconv.Itoa(len(shells))+" shells stopped, "+strconv.FormatInt(after, 10)+" bytes after the kill")
	session.runner.logger.Warn("memory limit exceeded", "session", session.ID, "measure", report.Measure,
		"usedBytes", used, "limitBytes", report.LimitBytes, "afterKillBytes", after,
		"removedShmBytes", report.RemovedShmBytes, "jobs", len(jobs), "shells", len(shells))
}

// killProcesses ends every process of the session: its cgroup (if any) and its uid.
func (session *Session) killProcesses() {
	if directory := session.runner.memory.directory; directory != "" {
		killCgroup(directory)
	}
	session.runner.killAll(session.UID)
}

// removeShmFiles removes the session's entries in /dev/shm (a memory disk) and answers their size.
func (session *Session) removeShmFiles() int64 {
	const shm = "/dev/shm"
	entries, err := os.ReadDir(shm)
	if err != nil {
		return 0
	}
	var names []string
	var total int64
	for _, entry := range entries {
		info, err := os.Lstat(filepath.Join(shm, entry.Name()))
		if err != nil {
			continue
		}
		if stat, ok := info.Sys().(*syscall.Stat_t); ok && int(stat.Uid) == session.UID {
			names = append(names, entry.Name())
			total += fileUsage(info)
		}
	}
	if len(names) == 0 {
		return 0
	}
	session.runner.runHelper(session.UID, session.runner.baseEnvironment(), nil, append([]string{"wipe", shm}, names...)...)
	return total
}

// MemorySince answers the report when the memory limit was exceeded after generation gen.
func (session *Session) MemorySince(gen int64) *MemoryReport {
	if session.memoryGen.Load() == gen {
		return nil
	}
	session.mutex.Lock()
	defer session.mutex.Unlock()
	return session.memoryReport
}

// useMemoryMeasure replaces the measure (tests).
func (session *Session) useMemoryMeasure(measure func() (int64, error)) {
	session.mutex.Lock()
	session.memoryMeasure = measure
	session.mutex.Unlock()
}
