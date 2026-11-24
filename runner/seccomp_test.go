package main

import (
	"fmt"
	"os"
	"strings"
	"testing"
	"time"
	"unsafe"

	"golang.org/x/sys/unix"
)

// probeSyscalls (the test binary as `__syscalls`) tries the calls the session filter refuses, and one it allows, and
// prints name=errno for each.
func probeSyscalls() {
	result := func(name string, errno unix.Errno) {
		fmt.Printf("%s=%d ", name, int(errno))
	}
	_, _, errno := unix.RawSyscall(unix.SYS_UNSHARE, unix.CLONE_NEWUSER, 0, 0)
	result("unshare-user", errno)
	_, _, errno = unix.RawSyscall(unix.SYS_UNSHARE, unix.CLONE_FS, 0, 0)
	result("unshare-fs", errno)
	var args [11]uint64 // struct clone_args with zero flags: without the filter EINVAL or a child
	_, _, errno = unix.RawSyscall(unix.SYS_CLONE3, uintptr(unsafe.Pointer(&args)), 8, 0)
	result("clone3", errno)
	_, _, errno = unix.RawSyscall(unix.SYS_SETNS, ^uintptr(0), 0, 0)
	result("setns", errno)
	_, _, errno = unix.RawSyscall(unix.SYS_PTRACE, unix.PTRACE_TRACEME, 0, 0)
	result("ptrace", errno)
	_, _, errno = unix.RawSyscall6(unix.SYS_KEYCTL, 0, 0, 0, 0, 0, 0)
	result("keyctl", errno)
	status, _ := os.ReadFile("/proc/self/status")
	for _, line := range strings.Split(string(status), "\n") {
		if strings.HasPrefix(line, "Seccomp:") {
			fmt.Printf("seccomp=%s ", strings.TrimSpace(strings.TrimPrefix(line, "Seccomp:")))
		}
	}
	fmt.Println()
	os.Exit(0)
}

func probeValue(output, name string) string {
	for _, field := range strings.Fields(output) {
		if key, value, found := strings.Cut(field, "="); found && key == name {
			return value
		}
	}
	return "missing"
}

// TestSessionProcessesRunUnderTheSeccompFilter: shells and jobs of a session cannot create namespaces, mount, setns,
// trace or use keyrings; clone3 answers ENOSYS (glibc falls back to clone).
func TestSessionProcessesRunUnderTheSeccompFilter(t *testing.T) {
	tr := newTestRunner(t, nil)
	session := tr.claim("seccomp")
	output := stdoutHead(tr.exec(session, map[string]any{"command": testExecutable + " __syscalls"}))
	// unshare-fs is printed but not checked: Docker's own profile (the image build's unit tests) refuses every unshare
	expect := map[string]unix.Errno{"unshare-user": unix.EPERM, "clone3": unix.ENOSYS,
		"setns": unix.EPERM, "ptrace": unix.EPERM, "keyctl": unix.EPERM}
	for name, errno := range expect {
		if value := probeValue(output, name); value != fmt.Sprint(int(errno)) {
			t.Fatalf("%s: errno %s, expected %d (%s)", name, value, int(errno), output)
		}
	}
	if probeValue(output, "seccomp") != "2" {
		t.Fatalf("the session process is not in seccomp filter mode: %s", output)
	}
	// a background job and its children carry the filter too; fork, pipes and subshells work under it
	status, answer := tr.call("POST", "/v1/sessions/"+session+"/exec", map[string]any{
		"command": "(" + testExecutable + " __syscalls | cat) && echo done", "background": true})
	if status != 202 {
		t.Fatalf("job: %d %v", status, answer)
	}
	view := tr.waitJob(session, answer["jobId"].(string))
	if probeValue(stdoutHead(view), "ptrace") != fmt.Sprint(int(unix.EPERM)) || !strings.Contains(stdoutHead(view), "done") {
		t.Fatalf("job under the filter: %v", view)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

// TestSeccompCanBeSwitchedOff: RUNNER_SESSION_SECCOMP=0 (development) leaves session processes unfiltered.
func TestSeccompCanBeSwitchedOff(t *testing.T) {
	tr := newTestRunner(t, func(config *Config) { config.SessionSeccomp = false })
	session := tr.claim("no-seccomp")
	output := stdoutHead(tr.exec(session, map[string]any{"command": testExecutable + " __syscalls"}))
	if probeValue(output, "ptrace") != "0" {
		t.Fatalf("ptrace should work without the filter: %s", output)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

func TestSeccompProgramResolves(t *testing.T) {
	program, err := sessionSeccompProgram()
	if err != nil || len(program) < len(seccompDenied)+10 || program[len(program)-1].K != seccompErrno(unix.EPERM) {
		t.Fatalf("program: %d instructions, %v", len(program), err)
	}
}

// waitJob polls a job until it is no longer running (10 s at most).
func (tr *testRunner) waitJob(session, job string) map[string]any {
	tr.t.Helper()
	var view map[string]any
	for attempt := 0; attempt < 100; attempt++ {
		_, view = tr.call("GET", "/v1/sessions/"+session+"/jobs/"+job, nil)
		if view["state"] != JobRunning {
			return view
		}
		time.Sleep(100 * time.Millisecond)
	}
	tr.t.Fatalf("job still running: %v", view)
	return view
}
