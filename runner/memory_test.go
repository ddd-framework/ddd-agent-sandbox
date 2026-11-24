package main

import (
	"os"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

// memoryHog (the test binary as `__memhog <bytes> <seconds>`) allocates and touches bytes, then sleeps.
func memoryHog(arguments []string) {
	size, _ := strconv.Atoi(arguments[0])
	seconds, _ := strconv.Atoi(arguments[1])
	buffer := make([]byte, size)
	for index := 0; index < len(buffer); index += 4096 {
		buffer[index] = 1
	}
	os.Stdout.WriteString("allocated\n")
	time.Sleep(time.Duration(seconds) * time.Second)
	buffer[0] = 2
	os.Exit(0)
}

func newMemoryRunner(t *testing.T, mode string) *testRunner {
	return newTestRunner(t, func(config *Config) {
		config.MemoryLimitBytes = 96 << 20
		config.MemoryInterval = 50 * time.Millisecond
		config.MemoryMode = mode
	})
}

func stderrHead(answer map[string]any) string {
	return answer["stderr"].(map[string]any)["head"].(string)
}

// TestMemoryLimitStopsSession: a process above the session's memory limit ends with the whole session; the answer
// says so, the shell comes back, and a background job gets the state memoryExceeded.
func TestMemoryLimitStopsSession(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("the rss measure counts the processes of the session uid: needs root")
	}
	tr := newMemoryRunner(t, MemoryRSS)
	session := tr.claim("memory")
	tr.exec(session, map[string]any{"command": "mkdir -p sub && cd sub"})
	started := time.Now()
	answer := tr.exec(session, map[string]any{"command": testExecutable + " __memhog 268435456 30", "timeoutSeconds": 60})
	if answer["status"] != ExecMemoryExceeded || time.Since(started) > 10*time.Second || answer["shellExited"] != true {
		t.Fatalf("exec past the memory limit: %v after %s", answer, time.Since(started))
	}
	memory := answer["memory"].(map[string]any)
	if memory["limitBytes"].(float64) != 96<<20 || memory["usedBytes"].(float64) <= 96<<20 || memory["measure"] != MemoryRSS ||
		!strings.Contains(stderrHead(answer), "memory limit of the sandbox session exceeded") {
		t.Fatalf("memory report: %v / %q", memory, stderrHead(answer))
	}
	largest := memory["largest"].([]any)
	if len(largest) == 0 || !strings.HasPrefix(largest[0].(map[string]any)["command"].(string), "sandbox-runner") {
		t.Fatalf("largest processes: %v", largest)
	}
	answer = tr.exec(session, map[string]any{"command": "pwd"})
	if answer["status"] != ExecOK || answer["shellRestarted"] != true || !strings.HasSuffix(strings.TrimSpace(stdoutHead(answer)), "/sub") {
		t.Fatalf("after the memory excess: %v", answer)
	}
	status, started2 := tr.call("POST", "/v1/sessions/"+session+"/exec", map[string]any{
		"command": testExecutable + " __memhog 268435456 30", "background": true})
	if status != 202 {
		t.Fatalf("job start: %d %v", status, started2)
	}
	job := started2["jobId"].(string)
	// the state and the report come with the kill; the line in stderr once the job's output is complete
	var view map[string]any
	for deadline := time.Now().Add(10 * time.Second); time.Now().Before(deadline); time.Sleep(100 * time.Millisecond) {
		_, view = tr.call("GET", "/v1/sessions/"+session+"/jobs/"+job, nil)
		if view["state"] == JobMemoryExceeded && view["memory"] == nil {
			t.Fatalf("a memoryExceeded job without its report: %v", view)
		}
		if view["state"] != JobRunning && strings.Contains(stderrHead(view), "memory limit") {
			break
		}
	}
	if view["state"] != JobMemoryExceeded || view["memory"] == nil || !strings.Contains(stderrHead(view), "memory limit") {
		t.Fatalf("job past the memory limit: %v", view)
	}
	// below the limit nothing happens
	answer = tr.exec(session, map[string]any{"command": testExecutable + " __memhog 16777216 1"})
	if answer["status"] != ExecOK || stdoutHead(answer) != "allocated\n" {
		t.Fatalf("a small allocation: %v", answer)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

// TestMemoryAboveLimitWithoutGrowthKillsOnce: memory the session keeps without a process (files in a memory disk)
// stops the session once, not every call again; growth beyond it stops it again.
func TestMemoryAboveLimitWithoutGrowthKillsOnce(t *testing.T) {
	tr := newMemoryRunner(t, MemoryRSS)
	session := tr.claim("memory-steady")
	bound, _ := tr.runner.Session(session)
	var level atomic.Int64
	bound.useMemoryMeasure(func() (int64, error) { return level.Load(), nil })
	level.Store(128 << 20)
	answer := tr.exec(session, map[string]any{"command": "sleep 2; echo late"})
	if answer["status"] != ExecMemoryExceeded || strings.Contains(stdoutHead(answer), "late") {
		t.Fatalf("first excess: %v", answer)
	}
	answer = tr.exec(session, map[string]any{"command": "sleep 0.5; echo steady"})
	if answer["status"] != ExecOK || stdoutHead(answer) != "steady\n" {
		t.Fatalf("steady above the limit: %v", answer)
	}
	level.Store(256 << 20)
	answer = tr.exec(session, map[string]any{"command": "sleep 2; echo grown"})
	if answer["status"] != ExecMemoryExceeded {
		t.Fatalf("growth above the limit: %v", answer)
	}
	level.Store(1 << 20)
	answer = tr.exec(session, map[string]any{"command": "sleep 0.3; echo below"})
	if answer["status"] != ExecOK || stdoutHead(answer) != "below\n" {
		t.Fatalf("below the limit: %v", answer)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

func TestMemoryReportMessage(t *testing.T) {
	report := &MemoryReport{UsedBytes: 3 << 30, LimitBytes: 2 << 30, Largest: []MemoryProcess{{PID: 7, Command: "python3", RSSBytes: 2900 << 20}}}
	message := report.Message()
	for _, part := range []string{"memory limit of the sandbox session exceeded", "3.0 GB used, limit 2.0 GB", "python3 2.8 GB"} {
		if !strings.Contains(message, part) {
			t.Fatalf("message %q lacks %q", message, part)
		}
	}
}
