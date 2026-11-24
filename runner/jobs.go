package main

import (
	"fmt"
	"strconv"
	"sync"
	"syscall"
	"time"
)

// Job states.
const (
	JobRunning  = "running"
	JobExited   = "exited"
	JobTimedOut = "timedOut"
	JobKilled   = "killed"
	// JobQuotaExceeded: the session's disk usage passed the quota; every process of the session was ended
	JobQuotaExceeded = "quotaExceeded"
	// JobMemoryExceeded: the session passed its memory limit; every process of the session was ended
	JobMemoryExceeded = "memoryExceeded"
)

// Job is a background command: its own bash under the session uid, in its own process group, started with the
// exported environment and cwd of the shell it was started from.
type Job struct {
	ID       string
	seq      int64
	session  *Session
	pid      int
	recorder *Recorder
	started  time.Time
	done     chan struct{}

	mutex    sync.Mutex
	state    string
	exitCode *int
	ended    time.Time
	output   *RecordedOutput
	quota    *QuotaReport
	memory   *MemoryReport
	hint     *NotLocalHint
	// misses: opens of not-local mount files during this job (placeholder FUSE)
	misses callMisses
	// history: the job's entry in the session history (admin.go)
	history *historyEntry
}

// JobView is the answer of GET /v1/sessions/{id}/jobs/{job}.
type JobView struct {
	ID         string        `json:"jobId"`
	State      string        `json:"state"`
	ExitCode   *int          `json:"exitCode"`
	TimedOut   bool          `json:"timedOut"`
	DurationMs int64         `json:"durationMs"`
	Quota      *QuotaReport  `json:"quota,omitempty"`
	Memory     *MemoryReport `json:"memory,omitempty"`
	*NotLocalHint
	RecordedOutput
}

// StartJob starts a background command.
func (session *Session) StartJob(shellName, commandText, cwd string, timeout time.Duration) (*Job, error) {
	config := session.runner.config
	session.mutex.Lock()
	running := 0
	for _, job := range session.jobs {
		if job.Running() {
			running++
		}
	}
	closed := session.closed
	session.mutex.Unlock()
	if closed {
		return nil, ErrSessionNotFound
	}
	if running >= config.MaxJobs {
		return nil, fmt.Errorf("too many running jobs")
	}
	environment, shellCwd := session.shellEnvironment(shellName)
	if cwd == "" {
		cwd = shellCwd
	}
	seq := session.jobSeq.Add(1)
	id := fmt.Sprintf("j_%d_%s", seq, randomHex(4))
	recorder := session.newRecorder(fmt.Sprintf("%s/job-%d.log", RunnerDir, seq))
	command := session.processCommand(environment,
		"job", config.Workspace, config.Home, cwd,
		strconv.FormatUint(config.MaxProcesses, 10), strconv.FormatUint(config.MaxOpenFiles, 10),
		strconv.FormatUint(config.MaxFileBytes, 10), commandText)
	command.Stdout = recorder.Stdout()
	command.Stderr = recorder.Stderr()
	command.SysProcAttr.Setpgid = true
	// a process the job left running may hold the pipes: do not wait for it after the job's bash exits
	command.WaitDelay = 2 * time.Second
	session.killing.RLock()
	startErr := session.startAdopted(command)
	session.killing.RUnlock()
	if startErr != nil {
		return nil, startErr
	}
	job := &Job{
		history:  session.history.start(shellName, commandText, id, recorder),
		ID:       id,
		seq:      seq,
		session:  session,
		pid:      command.Process.Pid,
		recorder: recorder,
		started:  time.Now(),
		done:     make(chan struct{}),
		state:    JobRunning,
	}
	session.mutex.Lock()
	session.jobs[id] = job
	session.mutex.Unlock()
	go func() {
		timer := time.AfterFunc(timeout, func() { job.Kill(JobTimedOut) })
		command.Wait()
		timer.Stop()
		if job.stateIs(JobMemoryExceeded) {
			job.mutex.Lock()
			report := job.memory
			job.mutex.Unlock()
			if report != nil {
				recorder.Stderr().Write([]byte(report.Message()))
			}
		}
		output := recorder.Finish()
		code := exitCodeOf(command.ProcessState)
		// the end-of-job measurement (a burst between two watcher ticks, or ENOSPC in the output)
		var report *QuotaReport
		if !job.isKilled() {
			report = session.CheckQuotaAfter(output.noSpace)
		}
		job.mutex.Lock()
		if job.state == JobRunning {
			job.state = JobExited
			if report != nil {
				job.state = JobQuotaExceeded
				job.quota = report
				job.exitCode = &code
			}
		}
		if job.state == JobExited {
			job.exitCode = &code
		}
		job.ended = time.Now()
		job.output = &output
		job.hint = session.callHint(commandText, cwd, job.misses.list())
		state := job.state
		exitCode := job.exitCode
		job.mutex.Unlock()
		duration := job.ended.Sub(job.started)
		session.history.finish(job.history, state, exitCode, duration, output)
		if state != JobKilled || !session.isClosed() {
			session.history.recordOutcome("job "+id, state, exitCode, report, duration)
		}
		close(job.done)
		session.runner.logger.Info("job ended", "session", session.ID, "job", id, "state", state, "exitCode", code,
			"durationMs", job.ended.Sub(job.started).Milliseconds(), "stdoutBytes", output.Stdout.Bytes,
			"stderrBytes", output.Stderr.Bytes)
	}()
	session.runner.logger.Info("job started", "session", session.ID, "job", id)
	return job, nil
}

func (job *Job) stateIs(state string) bool {
	job.mutex.Lock()
	defer job.mutex.Unlock()
	return job.state == state
}

func (job *Job) isKilled() bool {
	job.mutex.Lock()
	defer job.mutex.Unlock()
	return job.state != JobRunning
}

// Running reports whether the job still runs.
func (job *Job) Running() bool {
	job.mutex.Lock()
	defer job.mutex.Unlock()
	return job.state == JobRunning
}

// Kill ends the job's process group; state is JobTimedOut or JobKilled ("released" counts as killed).
func (job *Job) Kill(state string) {
	job.mutex.Lock()
	if job.state != JobRunning {
		job.mutex.Unlock()
		return
	}
	if state != JobTimedOut && state != JobQuotaExceeded && state != JobMemoryExceeded {
		state = JobKilled
	}
	job.state = state
	job.mutex.Unlock()
	syscall.Kill(-job.pid, syscall.SIGKILL)
	syscall.Kill(job.pid, syscall.SIGKILL)
}

// View answers the job's status with head and tail of its output so far.
func (job *Job) View() JobView {
	job.mutex.Lock()
	defer job.mutex.Unlock()
	view := JobView{ID: job.ID, State: job.state, ExitCode: job.exitCode, TimedOut: job.state == JobTimedOut}
	if job.state == JobQuotaExceeded {
		view.Quota = job.quota
	}
	if job.state == JobMemoryExceeded {
		view.Memory = job.memory
	}
	if job.output != nil {
		view.NotLocalHint = job.hint
		view.RecordedOutput = *job.output
		view.DurationMs = job.ended.Sub(job.started).Milliseconds()
	} else {
		view.RecordedOutput = job.recorder.Snapshot()
		view.DurationMs = time.Since(job.started).Milliseconds()
	}
	return view
}

// Job answers a job of the session.
func (session *Session) Job(id string) *Job {
	session.mutex.Lock()
	defer session.mutex.Unlock()
	return session.jobs[id]
}
