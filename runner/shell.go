package main

import (
	"bufio"
	"bytes"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

// orphanLimit bounds the output that background processes write between two commands; it is handed to the next
// command of the shell.
const orphanLimit = 64 << 10

// markerReader reads one output stream of the shell and splits it per command: everything before the command's token
// belongs to the command. Output that arrives while no command runs is kept (bounded) for the next one.
type markerReader struct {
	mutex   sync.Mutex
	pending []byte
	orphan  []byte
	token   []byte
	sink    io.Writer
	done    chan struct{}
	eof     bool
}

func (reader *markerReader) run(source io.Reader) {
	buffer := make([]byte, 64<<10)
	for {
		count, err := source.Read(buffer)
		if count > 0 {
			reader.feed(buffer[:count])
		}
		if err != nil {
			reader.finish()
			return
		}
	}
}

func (reader *markerReader) feed(data []byte) {
	reader.mutex.Lock()
	defer reader.mutex.Unlock()
	reader.pending = append(reader.pending, data...)
	reader.process()
}

// process hands pending bytes to the current sink; the caller holds the mutex.
func (reader *markerReader) process() {
	for {
		if reader.token == nil {
			reader.orphan = append(reader.orphan, reader.pending...)
			if len(reader.orphan) > orphanLimit {
				reader.orphan = append([]byte(nil), reader.orphan[len(reader.orphan)-orphanLimit:]...)
			}
			reader.pending = reader.pending[:0]
			return
		}
		if index := bytes.Index(reader.pending, reader.token); index >= 0 {
			reader.sink.Write(reader.pending[:index])
			rest := reader.pending[index+len(reader.token):]
			reader.pending = append([]byte(nil), rest...)
			reader.token = nil
			reader.sink = nil
			close(reader.done)
			continue
		}
		keep := len(reader.token) - 1
		if len(reader.pending) > keep {
			cut := len(reader.pending) - keep
			reader.sink.Write(reader.pending[:cut])
			reader.pending = reader.pending[:copy(reader.pending, reader.pending[cut:])]
		}
		return
	}
}

// begin starts capturing for one command; the channel closes when the token was seen or the stream ended.
func (reader *markerReader) begin(token []byte, sink io.Writer) <-chan struct{} {
	reader.mutex.Lock()
	defer reader.mutex.Unlock()
	done := make(chan struct{})
	if reader.eof {
		sink.Write(reader.orphan)
		reader.orphan = nil
		close(done)
		return done
	}
	reader.token = token
	reader.sink = sink
	reader.done = done
	reader.pending = append(reader.orphan, reader.pending...)
	reader.orphan = nil
	reader.process()
	return done
}

// abandon ends the current capture without a token (the shell is gone, a process still holds the pipe).
func (reader *markerReader) abandon() {
	reader.mutex.Lock()
	defer reader.mutex.Unlock()
	if reader.token != nil {
		reader.sink.Write(reader.pending)
		reader.pending = nil
		reader.token = nil
		reader.sink = nil
		close(reader.done)
	}
}

func (reader *markerReader) finish() {
	reader.mutex.Lock()
	defer reader.mutex.Unlock()
	reader.eof = true
	if reader.token != nil {
		reader.sink.Write(reader.pending)
		reader.pending = nil
		reader.token = nil
		reader.sink = nil
		close(reader.done)
	}
}

type shellStatus struct {
	token    string
	exitCode int
	cwd      string
}

// Shell is one persistent bash of a session: cwd and exported variables stay between calls.
type Shell struct {
	name     string
	session  *Session
	command  *exec.Cmd
	pid      int
	stdin    *os.File
	stdout   *markerReader
	stderr   *markerReader
	statuses chan shellStatus
	exited   chan struct{}
	// exitCode is valid after exited is closed
	exitCode int
	busy     sync.Mutex
	mutex    sync.Mutex
	cwd      string
	dead     bool
}

// StartShell starts bash under the session's uid in cwd.
func StartShell(session *Session, name, cwd string) (*Shell, error) {
	runner := session.runner
	config := runner.config
	stdinReader, stdinWriter, err := os.Pipe()
	if err != nil {
		return nil, err
	}
	stdoutReader, stdoutWriter, err := os.Pipe()
	if err != nil {
		return nil, err
	}
	stderrReader, stderrWriter, err := os.Pipe()
	if err != nil {
		return nil, err
	}
	statusReader, statusWriter, err := os.Pipe()
	if err != nil {
		return nil, err
	}
	command := session.processCommand(session.env,
		"shell", config.Workspace, config.Home, cwd,
		strconv.FormatUint(config.MaxProcesses, 10), strconv.FormatUint(config.MaxOpenFiles, 10),
		strconv.FormatUint(config.MaxFileBytes, 10))
	command.Stdin = stdinReader
	command.Stdout = stdoutWriter
	command.Stderr = stderrWriter
	command.ExtraFiles = []*os.File{statusWriter}
	command.SysProcAttr.Setpgid = true
	startErr := session.startAdopted(command)
	stdinReader.Close()
	stdoutWriter.Close()
	stderrWriter.Close()
	statusWriter.Close()
	if startErr != nil {
		stdinWriter.Close()
		stdoutReader.Close()
		stderrReader.Close()
		statusReader.Close()
		return nil, startErr
	}
	shell := &Shell{
		name:     name,
		session:  session,
		command:  command,
		pid:      command.Process.Pid,
		stdin:    stdinWriter,
		stdout:   &markerReader{},
		stderr:   &markerReader{},
		statuses: make(chan shellStatus, 4),
		exited:   make(chan struct{}),
		cwd:      cwd,
	}
	go shell.stdout.run(stdoutReader)
	go shell.stderr.run(stderrReader)
	go shell.readStatuses(statusReader)
	go func() {
		command.Wait()
		shell.exitCode = exitCodeOf(command.ProcessState)
		stdinWriter.Close()
		close(shell.exited)
	}()
	return shell, nil
}

func exitCodeOf(state *os.ProcessState) int {
	if state == nil {
		return -1
	}
	if status, ok := state.Sys().(syscall.WaitStatus); ok && status.Signaled() {
		return 128 + int(status.Signal())
	}
	return state.ExitCode()
}

func (shell *Shell) readStatuses(source io.ReadCloser) {
	defer source.Close()
	reader := bufio.NewReader(source)
	for {
		record, err := reader.ReadBytes(0)
		if err != nil {
			return
		}
		parts := strings.SplitN(string(record[:len(record)-1]), " ", 3)
		if len(parts) != 3 {
			continue
		}
		exitCode, _ := strconv.Atoi(parts[1])
		shell.statuses <- shellStatus{token: parts[0], exitCode: exitCode, cwd: parts[2]}
	}
}

// IsDead reports whether the shell ended; the session then starts a new one in the last cwd.
func (shell *Shell) IsDead() bool {
	shell.mutex.Lock()
	defer shell.mutex.Unlock()
	if shell.dead {
		return true
	}
	select {
	case <-shell.exited:
		shell.dead = true
	default:
	}
	return shell.dead
}

// Cwd is the shell's working directory after its last command.
func (shell *Shell) Cwd() string {
	shell.mutex.Lock()
	defer shell.mutex.Unlock()
	return shell.cwd
}

// quoteShell quotes a string for bash as one word.
func quoteShell(value string) string {
	return "'" + strings.ReplaceAll(value, "'", `'\''`) + "'"
}

// executeOutcome is the raw result of one command in the shell.
type executeOutcome struct {
	exitCode    *int
	timedOut    bool
	shellExited bool
	cwd         string
}

// ErrShellBusy is returned when a command already runs in this shell.
var ErrShellBusy = errors.New("shell busy")

// execute runs one command in the shell; stdout and stderr go to the writers. cwd (absolute, may be empty) is entered
// first. The command runs with stdin from /dev/null and without the status descriptor.
func (shell *Shell) execute(commandText, cwd string, timeout time.Duration, stdout, stderr io.Writer) (executeOutcome, error) {
	if !shell.busy.TryLock() {
		return executeOutcome{}, ErrShellBusy
	}
	defer shell.busy.Unlock()
	timer := newPausableTimer(timeout)
	defer timer.Stop()
	return shell.executeLocked(commandText, cwd, timer, stdout, stderr)
}

// executeLocked runs one command; the caller holds shell.busy. The timeout is a pausable timer: it stands still while
// the command's drive CLI waits for PHP.
func (shell *Shell) executeLocked(commandText, cwd string, timer *pausableTimer, stdout, stderr io.Writer) (executeOutcome, error) {
	if shell.IsDead() {
		return executeOutcome{}, fmt.Errorf("shell ended")
	}
	token := "__RB_" + randomHex(16) + "__"
	stdoutDone := shell.stdout.begin([]byte(token), stdout)
	stderrDone := shell.stderr.begin([]byte(token), stderr)
	before := descendantSet(shell.pid)

	run := "eval -- " + quoteShell(commandText) + " </dev/null 3>&-\n__rb_rc=$?\n"
	if cwd != "" {
		run = "if builtin cd -- " + quoteShell(cwd) + "; then\n" + run +
			"else __rb_rc=1; fi\n"
	}
	script := run + fmt.Sprintf("builtin printf '%%s' '%s'; builtin printf '%%s' '%s' >&2; builtin printf '%%s %%s %%s\\0' '%s' \"$__rb_rc\" \"$PWD\" >&3\n",
		token, token, token)
	go shell.stdin.Write([]byte(script))

	outcome := executeOutcome{}
	var status *shellStatus
	waitStatus := func(deadline <-chan struct{}) bool {
		for {
			select {
			case received := <-shell.statuses:
				if received.token == token {
					status = &received
					return true
				}
			case <-shell.exited:
				return true
			case <-deadline:
				return false
			}
		}
	}
	if !waitStatus(timer.C) {
		outcome.timedOut = true
		killNewDescendants(shell.pid, before)
		grace := make(chan struct{})
		graceTimer := time.AfterFunc(shell.session.runner.config.KillGrace, func() { close(grace) })
		answered := waitStatus(grace)
		graceTimer.Stop()
		if !answered {
			// a builtin loop or a shell that does not answer: end the whole shell
			shell.Kill()
			<-shell.exited
		}
	}
	if status == nil {
		// the shell ended (exit, exec, kill): its own exit code stands for the command
		select {
		case <-shell.exited:
		case <-time.After(time.Second):
		}
		outcome.shellExited = true
		if !outcome.timedOut {
			code := shell.exitCode
			outcome.exitCode = &code
		}
		shell.mutex.Lock()
		shell.dead = true
		shell.mutex.Unlock()
	} else {
		code := status.exitCode
		outcome.exitCode = &code
		shell.mutex.Lock()
		shell.cwd = status.cwd
		shell.mutex.Unlock()
	}
	// the tokens precede the status record, so normally both streams are complete; a background process may still
	// hold a pipe of a dead shell
	for _, done := range []<-chan struct{}{stdoutDone, stderrDone} {
		select {
		case <-done:
		case <-time.After(time.Second):
			shell.stdout.abandon()
			shell.stderr.abandon()
		}
	}
	outcome.cwd = shell.Cwd()
	return outcome, nil
}

// Kill ends the shell's process group (the shell and everything it started without its own session).
func (shell *Shell) Kill() {
	syscall.Kill(-shell.pid, syscall.SIGKILL)
	syscall.Kill(shell.pid, syscall.SIGKILL)
}
