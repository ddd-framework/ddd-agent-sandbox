package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// testExecutable is a copy of the test binary in a folder every uid may enter: the runner starts it again as its
// helper (TestMain dispatches __helper), under the session uid when the tests run as root.
var testExecutable string

func TestMain(m *testing.M) {
	if len(os.Args) > 1 && os.Args[1] == helperArgument {
		RunHelper(os.Args[2:])
		return
	}
	if filepath.Base(os.Args[0]) == "drive" {
		RunDriveCLI(os.Args[1:])
		return
	}
	if len(os.Args) > 1 && os.Args[1] == fuseHolderArgument {
		RunFuseHolder(os.Args[2:])
		return
	}
	if len(os.Args) > 1 && os.Args[1] == "__syscalls" {
		probeSyscalls()
		return
	}
	if len(os.Args) > 1 && os.Args[1] == "__memhog" {
		memoryHog(os.Args[2:])
		return
	}
	self, err := os.Executable()
	if err != nil {
		panic(err)
	}
	directory, err := os.MkdirTemp("", "runner-bin-")
	if err != nil {
		panic(err)
	}
	os.Chmod(directory, 0o755)
	testExecutable = filepath.Join(directory, "sandbox-runner.test")
	content, err := os.ReadFile(self)
	if err != nil {
		panic(err)
	}
	if err := os.WriteFile(testExecutable, content, 0o755); err != nil {
		panic(err)
	}
	// the drive CLI is the same binary under the name drive (as in the image)
	if err := os.Symlink(testExecutable, filepath.Join(directory, "drive")); err != nil {
		panic(err)
	}
	code := m.Run()
	os.RemoveAll(directory)
	os.Exit(code)
}

type testRunner struct {
	t         *testing.T
	runner    *Runner
	server    *httptest.Server
	workspace string
	scratch   string
}

func newTestRunner(t *testing.T, adjust func(*Config)) *testRunner {
	t.Helper()
	base, err := os.MkdirTemp("", "runner-test-")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(base) })
	os.Chmod(base, 0o755)
	workspace := filepath.Join(base, "workspace")
	scratch := filepath.Join(base, "tmp")
	for _, directory := range []string{workspace, scratch} {
		os.Mkdir(directory, 0o777)
		os.Chmod(directory, 0o777)
	}
	t.Setenv("RUNNER_WORKSPACE", workspace)
	t.Setenv("RUNNER_HOME", filepath.Join(scratch, "home"))
	t.Setenv("RUNNER_WIPE_DIRS", scratch)
	config, err := LoadConfig()
	if err != nil {
		t.Fatal(err)
	}
	config.KillGrace = time.Second
	if adjust != nil {
		adjust(&config)
	}
	logger := slog.New(slog.NewJSONHandler(io.Discard, nil))
	runner := NewRunner(config, testExecutable, os.Geteuid() == 0, logger)
	server := httptest.NewServer((&API{runner: runner}).Handler())
	t.Cleanup(server.Close)
	return &testRunner{t: t, runner: runner, server: server, workspace: workspace, scratch: scratch}
}

func (tr *testRunner) call(method, path string, body any) (int, map[string]any) {
	tr.t.Helper()
	var reader io.Reader
	if body != nil {
		encoded, _ := json.Marshal(body)
		reader = bytes.NewReader(encoded)
	}
	request, _ := http.NewRequest(method, tr.server.URL+path, reader)
	response, err := http.DefaultClient.Do(request)
	if err != nil {
		tr.t.Fatal(err)
	}
	defer response.Body.Close()
	var answer map[string]any
	json.NewDecoder(response.Body).Decode(&answer)
	return response.StatusCode, answer
}

func (tr *testRunner) raw(method, path string, body []byte) (int, []byte) {
	tr.t.Helper()
	request, _ := http.NewRequest(method, tr.server.URL+path, bytes.NewReader(body))
	if body == nil {
		request.Body = nil
	}
	response, err := http.DefaultClient.Do(request)
	if err != nil {
		tr.t.Fatal(err)
	}
	defer response.Body.Close()
	content, _ := io.ReadAll(response.Body)
	return response.StatusCode, content
}

func (tr *testRunner) claim(key string) string {
	tr.t.Helper()
	status, answer := tr.call("POST", "/v1/sessions", map[string]any{"workspaceKey": key})
	if status != http.StatusCreated {
		tr.t.Fatalf("claim: %d %v", status, answer)
	}
	return answer["sessionId"].(string)
}

func (tr *testRunner) exec(session string, body map[string]any) map[string]any {
	tr.t.Helper()
	status, answer := tr.call("POST", "/v1/sessions/"+session+"/exec", body)
	if status != http.StatusOK && status != http.StatusAccepted {
		tr.t.Fatalf("exec: %d %v", status, answer)
	}
	return answer
}

func stdoutHead(answer map[string]any) string {
	return answer["stdout"].(map[string]any)["head"].(string)
}

func TestSessionLifecycle(t *testing.T) {
	tr := newTestRunner(t, nil)
	if status, answer := tr.call("GET", "/healthz", nil); status != 200 || answer["state"] != StateIdle {
		t.Fatalf("healthz: %d %v", status, answer)
	}
	session := tr.claim("space-1")
	if status, _ := tr.call("POST", "/v1/sessions", map[string]any{"workspaceKey": "space-2"}); status != http.StatusServiceUnavailable {
		t.Fatalf("second claim should be 503, got %d", status)
	}

	// cwd and exported variables persist between calls
	answer := tr.exec(session, map[string]any{"command": "mkdir -p sub && cd sub && export X=1"})
	if answer["exitCode"].(float64) != 0 {
		t.Fatalf("first exec: %v", answer)
	}
	answer = tr.exec(session, map[string]any{"command": "pwd; echo $X; id -u"})
	lines := strings.Split(strings.TrimSpace(stdoutHead(answer)), "\n")
	if len(lines) != 3 || lines[0] != filepath.Join(tr.workspace, "sub") || lines[1] != "1" {
		t.Fatalf("persistence: %q", lines)
	}
	if os.Geteuid() == 0 && lines[2] == "0" {
		t.Fatal("commands must not run as root")
	}
	if answer["cwd"] != filepath.Join(tr.workspace, "sub") {
		t.Fatalf("cwd = %v", answer["cwd"])
	}

	// exit codes, stderr, a syntax error does not break the shell
	answer = tr.exec(session, map[string]any{"command": "echo oops >&2; exit_code() { return 7; }; exit_code"})
	if answer["exitCode"].(float64) != 7 || answer["stderr"].(map[string]any)["head"] != "oops\n" {
		t.Fatalf("exit code / stderr: %v", answer)
	}
	answer = tr.exec(session, map[string]any{"command": "if then fi '"})
	if answer["exitCode"].(float64) == 0 {
		t.Fatalf("syntax error should fail: %v", answer)
	}
	answer = tr.exec(session, map[string]any{"command": "echo $X"})
	if stdoutHead(answer) != "1\n" || answer["shellRestarted"] == true {
		t.Fatalf("shell should survive a syntax error: %v", answer)
	}

	// cwd of a call
	answer = tr.exec(session, map[string]any{"command": "pwd", "cwd": "."})
	if strings.TrimSpace(stdoutHead(answer)) != tr.workspace {
		t.Fatalf("cwd param: %v", answer)
	}

	// files: put, get, overwrite, mode and mtime
	if status, content := tr.raw("PUT", "/v1/sessions/"+session+"/files/docs/a.txt?mode=0755&mtime=2026-01-02T03:04:05Z", []byte("hello")); status != 200 {
		t.Fatalf("put: %d %s", status, content)
	}
	if status, content := tr.raw("GET", "/v1/sessions/"+session+"/files/docs/a.txt", nil); status != 200 || string(content) != "hello" {
		t.Fatalf("get: %d %q", status, content)
	}
	answer = tr.exec(session, map[string]any{"command": "cat /workspace/docs/a.txt 2>/dev/null || cat \"$WORKSPACE/docs/a.txt\"; stat -c %a \"$WORKSPACE/docs/a.txt\""})
	if stdoutHead(answer) != "hello755\n" {
		t.Fatalf("file seen by the shell: %q", stdoutHead(answer))
	}
	if status, _ := tr.raw("GET", "/v1/sessions/"+session+"/files/missing.txt", nil); status != 404 {
		t.Fatalf("missing file: %d", status)
	}
	if status, _ := tr.raw("GET", "/v1/sessions/"+session+"/files/docs", nil); status != http.StatusConflict {
		t.Fatalf("directory read: %d", status)
	}

	// path confinement: .., encoded .., symlink escapes
	for _, path := range []string{"..%2Fetc%2Fpasswd", "sub%2F..%2F..%2Fx", "%2Fetc%2Fpasswd"} {
		if status, _ := tr.raw("GET", "/v1/sessions/"+session+"/files/"+path, nil); status != 400 {
			t.Fatalf("path %s: %d, want 400", path, status)
		}
	}
	outside := filepath.Join(tr.scratch, "outside.txt")
	os.WriteFile(outside, []byte("secret"), 0o644)
	tr.exec(session, map[string]any{"command": fmt.Sprintf("ln -s %s \"$WORKSPACE/escape\"; ln -s %s \"$WORKSPACE/escapedir\"; ln -s docs/a.txt \"$WORKSPACE/inside\"", outside, tr.scratch)})
	for _, path := range []string{"escape", "escapedir/outside.txt"} {
		if status, content := tr.raw("GET", "/v1/sessions/"+session+"/files/"+path, nil); status == 200 {
			t.Fatalf("symlink escape %s answered %q", path, content)
		}
		if status, _ := tr.raw("PUT", "/v1/sessions/"+session+"/files/"+path, []byte("x")); status == 200 && path != "escape" {
			t.Fatalf("symlink escape write %s accepted", path)
		}
	}
	if content, _ := os.ReadFile(outside); string(content) != "secret" {
		t.Fatal("write through a symlink changed a file outside the workspace")
	}
	if status, content := tr.raw("GET", "/v1/sessions/"+session+"/files/inside", nil); status != 200 || string(content) != "hello" {
		// a symlink that stays inside the workspace is followed by os.Root… but O_NOFOLLOW on the last segment refuses it
		if status == 200 {
			t.Fatalf("inside symlink: %d %q", status, content)
		}
	}

	// manifest
	status, manifest := tr.call("GET", "/v1/sessions/"+session+"/manifest", nil)
	if status != 200 {
		t.Fatalf("manifest: %d", status)
	}
	found := false
	for _, entry := range manifest["files"].([]any) {
		file := entry.(map[string]any)
		if strings.HasPrefix(file["path"].(string), RunnerDir) {
			t.Fatal("manifest must leave out .runner")
		}
		if file["path"] == "docs/a.txt" {
			found = true
			if file["sha256"] != "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824" || file["size"].(float64) != 5 ||
				file["mtime"] != "2026-01-02T03:04:05Z" || file["mode"] != "0755" {
				t.Fatalf("manifest entry: %v", file)
			}
		}
	}
	// the PUT to "escape" replaced the symlink itself with a regular file (rename never follows); escapedir and inside stay
	if !found || manifest["skippedSymlinks"].(float64) != 2 {
		t.Fatalf("manifest: %v", manifest)
	}

	// long output: head and tail, spill file
	answer = tr.exec(session, map[string]any{"command": "seq 1 20000"})
	stdout := answer["stdout"].(map[string]any)
	if stdout["truncated"] != true || !strings.HasPrefix(stdout["head"].(string), "1\n2\n") || !strings.HasSuffix(stdout["tail"].(string), "19999\n20000\n") {
		t.Fatalf("truncation: %v", stdout["truncated"])
	}
	spillPath := answer["spillPath"].(string)
	relativeSpill := strings.TrimPrefix(spillPath, tr.workspace+"/")
	if status, content := tr.raw("GET", "/v1/sessions/"+session+"/files/"+relativeSpill, nil); status != 200 || !strings.HasSuffix(string(content), "\n20000\n") {
		t.Fatalf("spill file: %d, %d bytes", status, len(content))
	}

	// timeout kills the command; the shell and its state survive
	started := time.Now()
	answer = tr.exec(session, map[string]any{"command": "sleep 30", "timeoutSeconds": 1})
	if answer["timedOut"] != true || time.Since(started) > 5*time.Second {
		t.Fatalf("timeout: %v after %s", answer, time.Since(started))
	}
	answer = tr.exec(session, map[string]any{"command": "echo $X"})
	if stdoutHead(answer) != "1\n" {
		t.Fatalf("shell state after timeout: %v", answer)
	}
	// a builtin loop cannot be killed apart from the shell: the shell is replaced, in its last cwd
	tr.exec(session, map[string]any{"command": "cd sub"})
	answer = tr.exec(session, map[string]any{"command": "while :; do :; done", "timeoutSeconds": 1})
	if answer["timedOut"] != true || answer["shellExited"] != true {
		t.Fatalf("builtin loop: %v", answer)
	}
	answer = tr.exec(session, map[string]any{"command": "pwd"})
	if answer["shellRestarted"] != true || strings.TrimSpace(stdoutHead(answer)) != filepath.Join(tr.workspace, "sub") {
		t.Fatalf("restart: %v", answer)
	}
	if status, _ := tr.call("POST", "/v1/sessions/"+session+"/exec", map[string]any{"command": "true", "timeoutSeconds": 181}); status != 400 {
		t.Fatalf("timeout above 180 s: %d", status)
	}

	// background job with the shell's environment
	tr.exec(session, map[string]any{"command": "export JOBVAR=from-shell"})
	answer = tr.exec(session, map[string]any{"command": "echo start $JOBVAR; pwd; sleep 1; echo done >&2; exit 3", "background": true})
	jobID := answer["jobId"].(string)
	var job map[string]any
	for attempt := 0; attempt < 50; attempt++ {
		_, job = tr.call("GET", "/v1/sessions/"+session+"/jobs/"+jobID, nil)
		if job["state"] != JobRunning {
			break
		}
		time.Sleep(100 * time.Millisecond)
	}
	if job["state"] != JobExited || job["exitCode"].(float64) != 3 ||
		stdoutHead(job) != "start from-shell\n"+filepath.Join(tr.workspace, "sub")+"\n" ||
		job["stderr"].(map[string]any)["head"] != "done\n" {
		t.Fatalf("job: %v", job)
	}
	answer = tr.exec(session, map[string]any{"command": "sleep 60", "background": true, "timeoutSeconds": 1})
	jobID = answer["jobId"].(string)
	time.Sleep(1500 * time.Millisecond)
	if _, job = tr.call("GET", "/v1/sessions/"+session+"/jobs/"+jobID, nil); job["state"] != JobTimedOut {
		t.Fatalf("job timeout: %v", job)
	}
	answer = tr.exec(session, map[string]any{"command": "sleep 60", "background": true})
	jobID = answer["jobId"].(string)
	if _, job = tr.call("DELETE", "/v1/sessions/"+session+"/jobs/"+jobID, nil); job["state"] != JobKilled {
		t.Fatalf("job kill: %v", job)
	}

	// a process that outlives everything and a file in HOME and /tmp; DELETE kills and wipes
	tr.exec(session, map[string]any{"command": "nohup sleep 300 >/dev/null 2>&1 & echo home > \"$HOME/h.txt\"; mkdir -p locked/inner && touch locked/inner/f && chmod 000 locked/inner locked", "cwd": "."})
	if status, answer := tr.call("DELETE", "/v1/sessions/"+session, nil); status != 200 {
		t.Fatalf("delete: %d %v", status, answer)
	}
	if entries, _ := os.ReadDir(tr.workspace); len(entries) != 0 {
		t.Fatalf("workspace not wiped: %d entries", len(entries))
	}
	if entries, _ := os.ReadDir(tr.scratch); len(entries) != 0 {
		names := []string{}
		for _, entry := range entries {
			names = append(names, entry.Name())
		}
		// outside.txt belongs to the test (root); in unprivileged mode it is session-owned and wiped as well
		if !(len(names) == 1 && names[0] == "outside.txt" && os.Geteuid() == 0) {
			t.Fatalf("scratch not wiped: %v", names)
		}
	}
	if status, _ := tr.call("POST", "/v1/sessions/"+session+"/exec", map[string]any{"command": "true"}); status != 404 {
		t.Fatalf("exec after delete: %d", status)
	}
	if status, answer := tr.call("GET", "/healthz", nil); status != 200 || answer["state"] != StateIdle {
		t.Fatalf("healthz after delete: %d %v", status, answer)
	}

	// the next session gets a new uid and an empty workspace
	_, first := tr.call("POST", "/v1/sessions", map[string]any{"workspaceKey": "space-2", "files": []map[string]any{
		{"path": "restored/r.txt", "contentBase64": "cmVzdG9yZWQ=", "mode": "0600"},
	}})
	next := first["sessionId"].(string)
	answer = tr.exec(next, map[string]any{"command": "cat restored/r.txt; ls -A | tr '\\n' ' '; echo; pgrep -c sleep || true"})
	// r.txt holds "restored" without a newline; then the listing (only the restored folder); then the sleep count
	if !strings.HasPrefix(stdoutHead(answer), "restoredrestored \n") {
		t.Fatalf("new session: %q", stdoutHead(answer))
	}
	if !strings.HasSuffix(stdoutHead(answer), "\n0\n") {
		t.Fatal("a sleep of the previous session survived")
	}
	tr.call("DELETE", "/v1/sessions/"+next, nil)
}

func TestIdleSessionIsReleased(t *testing.T) {
	tr := newTestRunner(t, func(config *Config) { config.SessionIdle = 100 * time.Millisecond })
	session := tr.claim("idle-space")
	tr.exec(session, map[string]any{"command": "touch f"})
	time.Sleep(200 * time.Millisecond)
	tr.runner.ReapIdle()
	if status, _ := tr.call("POST", "/v1/sessions/"+session+"/exec", map[string]any{"command": "true"}); status != 404 {
		t.Fatalf("idle session should be gone, got %d", status)
	}
	if tr.runner.State() != StateIdle {
		t.Fatalf("state = %s", tr.runner.State())
	}
}

func TestRequestValidation(t *testing.T) {
	tr := newTestRunner(t, nil)
	if status, _ := tr.call("POST", "/v1/sessions", map[string]any{}); status != 400 {
		t.Fatalf("missing workspaceKey: %d", status)
	}
	if status, _ := tr.call("POST", "/v1/sessions", map[string]any{"workspaceKey": "k", "env": map[string]string{"BASH_ENV": "/x"}}); status != 400 {
		t.Fatalf("BASH_ENV: %d", status)
	}
	if status, _ := tr.call("POST", "/v1/sessions", map[string]any{"workspaceKey": "k", "files": []map[string]any{{"path": "../x", "contentBase64": ""}}}); status != 400 {
		t.Fatalf("restore path: %d", status)
	}
	if tr.runner.State() != StateIdle {
		t.Fatal("refused claims must not bind the runner")
	}
	if status, _ := tr.call("POST", "/v1/sessions/s_nope/exec", map[string]any{"command": "true"}); status != 404 {
		t.Fatalf("unknown session: %d", status)
	}
}
