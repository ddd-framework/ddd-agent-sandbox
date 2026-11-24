package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestSessionGetsPassedImageEnvironmentOnly(t *testing.T) {
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", "/opt/pw-browsers-test")
	t.Setenv("BROWSER_PATH", "/usr/local/bin/chrome-headless-shell")
	t.Setenv("RUNNER_PRIVATE_TEST_VALUE", "must-not-leak")
	tr := newTestRunner(t, nil)
	session := tr.claim("env-space")
	answer := tr.exec(session, map[string]any{"command": "echo $PLAYWRIGHT_BROWSERS_PATH; echo $BROWSER_PATH; echo [$RUNNER_PRIVATE_TEST_VALUE]"})
	if stdoutHead(answer) != "/opt/pw-browsers-test\n/usr/local/bin/chrome-headless-shell\n[]\n" {
		t.Fatalf("shell environment: %q", stdoutHead(answer))
	}
	answer = tr.exec(session, map[string]any{"command": "echo $PLAYWRIGHT_BROWSERS_PATH", "background": true})
	jobID := answer["jobId"].(string)
	var job map[string]any
	for attempt := 0; attempt < 50; attempt++ {
		if _, job = tr.call("GET", "/v1/sessions/"+session+"/jobs/"+jobID, nil); job["state"] != JobRunning {
			break
		}
		time.Sleep(50 * time.Millisecond)
	}
	if stdoutHead(job) != "/opt/pw-browsers-test\n" {
		t.Fatalf("job environment: %v", job)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

const testQuota = 8 << 20

func newQuotaRunner(t *testing.T) *testRunner {
	return newTestRunner(t, func(config *Config) {
		config.QuotaBytes = testQuota
		config.QuotaInterval = 100 * time.Millisecond
		config.QuotaDirs = []string{config.Workspace, filepath.Dir(config.Home)}
	})
}

func TestQuotaRefusesPut(t *testing.T) {
	tr := newQuotaRunner(t)
	session := tr.claim("quota-put")
	if status, content := tr.raw("PUT", "/v1/sessions/"+session+"/files/big1.bin", bytes.Repeat([]byte("a"), 4<<20)); status != 200 {
		t.Fatalf("first PUT: %d %s", status, content)
	}
	status, answer := tr.rawJSON("PUT", "/v1/sessions/"+session+"/files/big2.bin", bytes.Repeat([]byte("b"), 5<<20))
	if status != http.StatusRequestEntityTooLarge || answer["error"] != "QUOTA_EXCEEDED" ||
		answer["limitBytes"].(float64) != testQuota || answer["totalBytes"].(float64) < 4<<20 {
		t.Fatalf("PUT past the quota: %d %v", status, answer)
	}
	if _, err := os.Stat(filepath.Join(tr.workspace, "big2.bin")); err == nil {
		t.Fatal("refused PUT left a file")
	}
	// replacing a file counts only the difference
	if status, content := tr.raw("PUT", "/v1/sessions/"+session+"/files/big1.bin", bytes.Repeat([]byte("c"), 7<<20)); status != 200 {
		t.Fatalf("replacing PUT within the quota: %d %s", status, content)
	}
	status, manifest := tr.call("GET", "/v1/sessions/"+session+"/manifest", nil)
	if status != 200 || manifest["limitBytes"].(float64) != testQuota || manifest["usedBytes"].(float64) < 7<<20 ||
		manifest["totalBytes"].(float64) != 7<<20 {
		t.Fatalf("manifest: %d limit %v used %v total %v", status, manifest["limitBytes"], manifest["usedBytes"], manifest["totalBytes"])
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

func TestQuotaStopsExecAndShellRecovers(t *testing.T) {
	tr := newQuotaRunner(t)
	session := tr.claim("quota-exec")
	tr.exec(session, map[string]any{"command": "mkdir -p sub && cd sub && export KEEP=1"})
	started := time.Now()
	answer := tr.exec(session, map[string]any{"command": "for i in $(seq 1 40); do head -c 1048576 /dev/zero > chunk-$i; sleep 0.05; done; sleep 30", "timeoutSeconds": 60})
	if answer["status"] != ExecQuotaExceeded || time.Since(started) > 10*time.Second {
		t.Fatalf("exec past the quota: %v after %s", answer, time.Since(started))
	}
	quota := answer["quota"].(map[string]any)
	largest := quota["largest"].([]any)
	if quota["limitBytes"].(float64) != testQuota || quota["totalBytes"].(float64) <= testQuota || len(largest) != 5 ||
		!strings.Contains(largest[0].(map[string]any)["path"].(string), "chunk-") {
		t.Fatalf("quota report: %v", quota)
	}
	if answer["shellExited"] != true || answer["timedOut"] == true {
		t.Fatalf("the shell should have been ended: %v", answer)
	}
	// still past the quota, but not growing: a command that only reads runs to its end (the watcher kills only growth)
	answer = tr.exec(session, map[string]any{"command": "sleep 0.5; ls chunk-* | wc -l"})
	if answer["status"] != ExecQuotaExceeded || answer["shellExited"] == true || answer["exitCode"].(float64) != 0 {
		t.Fatalf("a reading command while past the quota: %v", answer)
	}
	// the shell came back in its last cwd; freeing space works
	answer = tr.exec(session, map[string]any{"command": "sleep 0.3; rm -f chunk-*; pwd"})
	if answer["status"] != ExecOK || answer["shellExited"] == true || strings.TrimSpace(stdoutHead(answer)) != filepath.Join(tr.workspace, "sub") {
		t.Fatalf("after the quota: %v", answer)
	}
	answer = tr.exec(session, map[string]any{"command": "sleep 0.3; echo fine"})
	if answer["status"] != ExecOK || stdoutHead(answer) != "fine\n" {
		t.Fatalf("below the quota again: %v", answer)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

func TestQuotaStopsJobsAndCountsTmpAndClosedFolders(t *testing.T) {
	tr := newQuotaRunner(t)
	session := tr.claim("quota-job")
	// a folder the session closed for itself still counts, and keeps its mode; HOME (on /tmp in the pod) counts
	tr.exec(session, map[string]any{"command": "mkdir hidden && head -c 3145728 /dev/zero > hidden/h && chmod 000 hidden && head -c 2097152 /dev/zero > $HOME/t"})
	_, manifest := tr.call("GET", "/v1/sessions/"+session+"/manifest", nil)
	if manifest["usedBytes"].(float64) < 5<<20 {
		t.Fatalf("usage misses the closed folder or HOME: %v", manifest["usedBytes"])
	}
	answer := tr.exec(session, map[string]any{"command": "stat -c %a hidden"})
	if stdoutHead(answer) != "0\n" {
		t.Fatalf("the closed folder's mode changed: %q", stdoutHead(answer))
	}
	tr.exec(session, map[string]any{"command": "chmod 755 hidden"})
	answer = tr.exec(session, map[string]any{"command": "for i in $(seq 1 20); do head -c 1048576 /dev/zero > job-$i; sleep 0.05; done; sleep 30", "background": true})
	jobID := answer["jobId"].(string)
	var job map[string]any
	for attempt := 0; attempt < 100; attempt++ {
		if _, job = tr.call("GET", "/v1/sessions/"+session+"/jobs/"+jobID, nil); job["state"] != JobRunning {
			break
		}
		time.Sleep(100 * time.Millisecond)
	}
	if job["state"] != JobQuotaExceeded || job["quota"] == nil {
		t.Fatalf("job past the quota: %v", job)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

func (tr *testRunner) rawJSON(method, path string, body []byte) (int, map[string]any) {
	tr.t.Helper()
	status, content := tr.raw(method, path, body)
	var answer map[string]any
	if err := json.Unmarshal(content, &answer); err != nil {
		tr.t.Fatalf("%s %s: no JSON (%d): %s", method, path, status, content)
	}
	return status, answer
}

// a burst that finishes between two watcher ticks is caught by the measurement at the end of the exec: no kill, the
// files stay, the shell keeps its state
func TestQuotaCheckedAtEndOfExecAndJob(t *testing.T) {
	tr := newTestRunner(t, func(config *Config) {
		config.QuotaBytes = testQuota
		config.QuotaInterval = time.Hour // the watcher never ticks: only the end-of-call check can see the burst
		config.QuotaDirs = []string{config.Workspace, filepath.Dir(config.Home)}
	})
	session := tr.claim("quota-end")
	tr.exec(session, map[string]any{"command": "export KEEP=1"})
	answer := tr.exec(session, map[string]any{"command": "for i in 1 2 3; do head -c 4194304 /dev/zero > burst-$i & done; wait"})
	quota, _ := answer["quota"].(map[string]any)
	if answer["status"] != ExecQuotaExceeded || answer["exitCode"].(float64) != 0 || answer["shellExited"] == true ||
		quota == nil || quota["reason"] != QuotaLimit || quota["totalBytes"].(float64) < 12<<20 || len(quota["largest"].([]any)) != 3 {
		t.Fatalf("burst: %v", answer)
	}
	answer = tr.exec(session, map[string]any{"command": "ls burst-* | wc -l; echo $KEEP"})
	if stdoutHead(answer) != "3\n1\n" || answer["status"] != ExecQuotaExceeded {
		t.Fatalf("files and shell state must stay (and the next call still reports the excess): %v", answer)
	}
	answer = tr.exec(session, map[string]any{"command": "rm burst-*"})
	if answer["status"] != ExecOK {
		t.Fatalf("after freeing space: %v", answer)
	}
	answer = tr.exec(session, map[string]any{"command": "head -c 10485760 /dev/zero > job.bin", "background": true})
	jobID := answer["jobId"].(string)
	var job map[string]any
	for attempt := 0; attempt < 50; attempt++ {
		if _, job = tr.call("GET", "/v1/sessions/"+session+"/jobs/"+jobID, nil); job["state"] != JobRunning {
			break
		}
		time.Sleep(50 * time.Millisecond)
	}
	if job["state"] != JobQuotaExceeded || job["exitCode"].(float64) != 0 || job["quota"] == nil {
		t.Fatalf("job end check: %v", job)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

func TestNoSpaceInOutputIsQuotaExceeded(t *testing.T) {
	tr := newTestRunner(t, nil)
	session := tr.claim("quota-enospc")
	answer := tr.exec(session, map[string]any{"command": "printf 'dd: error writing f: No space ' >&2; sleep 0.1; printf 'left on device\\n' >&2; exit 1"})
	quota, _ := answer["quota"].(map[string]any)
	if answer["status"] != ExecQuotaExceeded || quota == nil || quota["reason"] != QuotaNoSpace {
		t.Fatalf("ENOSPC in the output: %v", answer)
	}
	answer = tr.exec(session, map[string]any{"command": "echo fine"})
	if answer["status"] != ExecOK {
		t.Fatalf("next call: %v", answer)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

func TestRecorderSeesNoSpaceAcrossChunks(t *testing.T) {
	recorder, _, _ := newTestRecorder(10, 10, 1<<20)
	recorder.Stdout().Write([]byte("No space left"))
	recorder.Stderr().Write([]byte("on device"))
	if recorder.Finish().noSpace {
		t.Fatal("the two streams must not be joined")
	}
	recorder, _, _ = newTestRecorder(10, 10, 1<<20)
	for _, chunk := range []string{"write: No sp", "ace left on d", "evice\n"} {
		recorder.Stderr().Write([]byte(chunk))
	}
	if !recorder.Finish().noSpace {
		t.Fatal("ENOSPC split over three chunks not seen")
	}
}

// regression (cluster, 2026-11-22): a refused PUT changes no state, and a statfs estimate above the limit (ext4 frees
// deleted blocks only at its journal commit) kills nothing while the exact walk is below it
func TestRefusedPutAndStatfsFalseAlarmChangeNothing(t *testing.T) {
	tr := newQuotaRunner(t)
	session := tr.claim("quota-false-alarm")
	tr.exec(session, map[string]any{"command": "export KEEP=1; head -c 2097152 /dev/zero > small.bin; head -c 1048576 /dev/zero > $HOME/cache.bin"})
	status, answer := tr.rawJSON("PUT", "/v1/sessions/"+session+"/files/big.bin", bytes.Repeat([]byte("b"), 7<<20))
	byDirectory, _ := answer["byDirectory"].(map[string]any)
	if status != http.StatusRequestEntityTooLarge || answer["reason"] != QuotaLimit || len(byDirectory) == 0 {
		t.Fatalf("PUT past the quota: %d %v", status, answer)
	}
	answer = tr.exec(session, map[string]any{"command": "sleep 0.3; echo $KEEP"})
	if answer["status"] != ExecOK || stdoutHead(answer) != "1\n" {
		t.Fatalf("exec after a refused PUT: %v", answer)
	}
	bound, err := tr.runner.Session(session)
	if err != nil {
		t.Fatal(err)
	}
	// statfs overstates by a phantom 64 MiB (blocks of deleted files before the ext4 journal commit), and follows the
	// real usage otherwise
	bound.useStatfsMeasure(func() int64 {
		usage, _ := bound.MeasureUsage("")
		return usage.TotalBytes + 64<<20
	})
	answer = tr.exec(session, map[string]any{"command": "sleep 0.5; echo $KEEP"})
	if answer["status"] != ExecOK || stdoutHead(answer) != "1\n" || answer["shellExited"] == true {
		t.Fatalf("a statfs false alarm must not kill: %v", answer)
	}
	// a real excess under statfs still stops the command, confirmed by the walk; HOME (below /tmp) is reported
	started := time.Now()
	answer = tr.exec(session, map[string]any{"command": "head -c 9437184 /dev/zero > real.bin; sleep 30"})
	quota, _ := answer["quota"].(map[string]any)
	if answer["status"] != ExecQuotaExceeded || quota == nil || quota["reason"] != QuotaLimit ||
		answer["shellExited"] != true || time.Since(started) > 5*time.Second {
		t.Fatalf("real excess under statfs: %v", answer)
	}
	byDirectory = quota["byDirectory"].(map[string]any)
	if byDirectory[tr.workspace].(float64) < 11<<20 || byDirectory[filepath.Join(tr.scratch)].(float64) < 1<<20 {
		t.Fatalf("byDirectory: %v", byDirectory)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}
