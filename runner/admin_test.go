package main

import (
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const testSecret = "s3cr3t-token-4711"

func listOf(t *testing.T, answer map[string]any, key string) []map[string]any {
	t.Helper()
	raw, ok := answer[key].([]any)
	if !ok {
		t.Fatalf("%s missing: %v", key, answer)
	}
	items := make([]map[string]any, 0, len(raw))
	for _, item := range raw {
		items = append(items, item.(map[string]any))
	}
	return items
}

// TestAdministrationReadsMaskSecretsAndLeaveActivityAlone: the session summary, processes with their job, the
// command history and events, the disk usage; env values never appear, the reads do not count as activity.
func TestAdministrationReadsMaskSecretsAndLeaveActivityAlone(t *testing.T) {
	tr := newTestRunner(t, nil)
	status, answer := tr.call("GET", "/v1/sessions", nil)
	if status != http.StatusOK || len(listOf(t, answer, "sessions")) != 0 {
		t.Fatalf("idle runner: %d %v", status, answer)
	}
	status, answer = tr.call("POST", "/v1/sessions", map[string]any{"workspaceKey": "admin-space",
		"env": map[string]string{"API_TOKEN": testSecret, "SHORT": "de"}})
	if status != http.StatusCreated {
		t.Fatalf("claim: %d %v", status, answer)
	}
	session := answer["sessionId"].(string)
	defer tr.call("DELETE", "/v1/sessions/"+session, nil)

	tr.exec(session, map[string]any{"command": "echo token=$API_TOKEN; echo de; echo oops >&2; head -c 3000 /dev/zero > blob"})
	// the job's bash keeps the secret in its argv (two commands: no exec of the last one)
	started := tr.exec(session, map[string]any{"command": "bash -c 'sleep 30; :' carrier " + testSecret, "background": true})
	jobID := started["jobId"].(string)
	time.Sleep(300 * time.Millisecond)

	_, answer = tr.call("GET", "/v1/sessions", nil)
	sessions := listOf(t, answer, "sessions")
	if len(sessions) != 1 {
		t.Fatalf("sessions: %v", answer)
	}
	summary := sessions[0]
	if summary["sessionId"] != session || summary["workspaceKey"] != "admin-space" || summary["runningJobCount"] != 1.0 ||
		summary["mountMode"] != "PLAIN" || summary["mode"] != "legacy" || summary["diskQuotaBytes"].(float64) <= 0 {
		t.Fatalf("summary: %v", summary)
	}
	if _, found := summary["env"]; found {
		t.Fatal("the summary must not carry the env")
	}
	lastActivity := summary["lastActivityAt"]

	_, answer = tr.call("GET", "/v1/sessions/"+session+"/processes", nil)
	processes := listOf(t, answer, "processes")
	var jobProcess map[string]any
	for _, process := range processes {
		argv := process["argv"].(string)
		if strings.Contains(argv, testSecret) {
			t.Fatalf("argv shows the secret: %q", argv)
		}
		if strings.HasPrefix(argv, "bash -c sleep 30; : carrier") && process["jobId"] == jobID {
			jobProcess = process
		}
	}
	if tr.runner.privileged && jobProcess == nil {
		t.Fatalf("no process of the job: %v", processes)
	}
	if jobProcess != nil && (!strings.Contains(jobProcess["argv"].(string), SecretMask) || jobProcess["startedAt"] == nil) {
		t.Fatalf("job process: %v", jobProcess)
	}

	_, answer = tr.call("GET", "/v1/sessions/"+session+"/execs", nil)
	execs := listOf(t, answer, "execs")
	if len(execs) != 2 {
		t.Fatalf("execs: %v", execs)
	}
	job, exec := execs[0], execs[1]
	if job["jobId"] != jobID || job["status"] != "running" || job["exitCode"] != nil ||
		job["command"] != "bash -c 'sleep 30; :' carrier "+SecretMask {
		t.Fatalf("job entry: %v", job)
	}
	if exec["status"] != ExecOK || exec["exitCode"] != 0.0 || exec["stdoutTail"] != "token="+SecretMask+"\nde\n" ||
		exec["stderrTail"] != "oops\n" || exec["jobId"] != nil || exec["seq"].(float64) >= job["seq"].(float64) {
		t.Fatalf("exec entry: %v", exec)
	}
	if status, _ := tr.call("GET", "/v1/sessions/"+session+"/execs?limit=1", nil); status != http.StatusOK {
		t.Fatalf("limit 1: %d", status)
	}
	if status, _ := tr.call("GET", "/v1/sessions/"+session+"/execs?limit=0", nil); status != http.StatusBadRequest {
		t.Fatalf("limit 0: %d", status)
	}

	_, answer = tr.call("GET", "/v1/sessions/"+session+"/disk", nil)
	if answer["usedBytes"].(float64) < 3000 || answer["quotaBytes"].(float64) <= 0 {
		t.Fatalf("disk: %v", answer)
	}

	_, answer = tr.call("GET", "/v1/sessions", nil)
	if after := listOf(t, answer, "sessions")[0]["lastActivityAt"]; after != lastActivity {
		t.Fatalf("an admin read counted as activity: %v → %v", lastActivity, after)
	}

	// kill the job and time out a command: both are events, newest first
	if status, _ := tr.call("DELETE", "/v1/sessions/"+session+"/jobs/"+jobID, nil); status != http.StatusOK {
		t.Fatalf("kill: %d", status)
	}
	tr.exec(session, map[string]any{"command": "sleep 5", "timeoutSeconds": 1})
	_, answer = tr.call("GET", "/v1/sessions/"+session+"/events", nil)
	events := listOf(t, answer, "events")
	types := []string{}
	for _, event := range events {
		types = append(types, event["type"].(string))
		if strings.Contains(event["detail"].(string), testSecret) {
			t.Fatalf("event detail shows the secret: %v", event)
		}
	}
	if strings.Join(types, ",") != EventTimeout+","+EventJobKilled+","+EventSessionClaimed {
		t.Fatalf("events: %v", events)
	}
	_, answer = tr.call("GET", "/v1/sessions/"+session+"/execs?limit=2", nil)
	execs = listOf(t, answer, "execs")
	if len(execs) != 2 || execs[0]["status"] != ExecTimedOut || execs[1]["status"] != JobKilled {
		t.Fatalf("execs after kill and timeout: %v", execs)
	}

	for _, path := range []string{"processes", "execs", "events", "disk", "files"} {
		if status, _ := tr.call("GET", "/v1/sessions/s_nope/"+path, nil); status != http.StatusNotFound {
			t.Fatalf("unknown session %s: %d", path, status)
		}
	}
}

// TestDirectoryListingStaysInTheWorkspace: one folder per call, folders first; .. and symlinks out are refused.
func TestDirectoryListingStaysInTheWorkspace(t *testing.T) {
	tr := newTestRunner(t, nil)
	session := tr.claim("listing-space")
	defer tr.call("DELETE", "/v1/sessions/"+session, nil)
	tr.exec(session, map[string]any{"command": "mkdir -p sub/deeper && printf hello > sub/a.txt && printf x > top.md && " +
		"ln -s / escape && ln -s sub inside"})

	status, answer := tr.call("GET", "/v1/sessions/"+session+"/files", nil)
	if status != http.StatusOK {
		t.Fatalf("root: %d %v", status, answer)
	}
	names := []string{}
	for _, entry := range listOf(t, answer, "entries") {
		names = append(names, entry["name"].(string)+":"+entry["type"].(string))
	}
	if strings.Join(names, ",") != "sub:dir,escape:symlink,inside:symlink,top.md:file" {
		t.Fatalf("root entries: %v", names)
	}
	_, answer = tr.call("GET", "/v1/sessions/"+session+"/files?path=sub", nil)
	entries := listOf(t, answer, "entries")
	if len(entries) != 2 || entries[0]["name"] != "deeper" || entries[1]["name"] != "a.txt" || entries[1]["size"] != 5.0 ||
		entries[1]["mtime"] == "" {
		t.Fatalf("sub entries: %v", entries)
	}
	if status, _ := tr.call("GET", "/v1/sessions/"+session+"/files?path=inside", nil); status != http.StatusOK {
		t.Fatalf("a link inside the workspace: %d", status)
	}
	for path, want := range map[string]int{
		"escape": http.StatusBadRequest, "escape/etc": http.StatusBadRequest, "../": http.StatusBadRequest,
		"sub/../../x": http.StatusBadRequest, "sub/a.txt": http.StatusConflict, "missing": http.StatusNotFound,
	} {
		if status, answer := tr.call("GET", "/v1/sessions/"+session+"/files?path="+path, nil); status != want {
			t.Fatalf("path %q: %d %v (want %d)", path, status, answer, want)
		}
	}
}

func TestRunnerFolderIsMaskedAndNeverListed(t *testing.T) {
	tr := newTestRunner(t, nil)
	status, answer := tr.call("POST", "/v1/sessions", map[string]any{"workspaceKey": "spill-space",
		"env": map[string]string{"API_TOKEN": testSecret}})
	if status != http.StatusCreated {
		t.Fatalf("claim: %d %v", status, answer)
	}
	session := answer["sessionId"].(string)
	defer tr.call("DELETE", "/v1/sessions/"+session, nil)

	// more than head + tail: the whole output goes to the spill file below .runner/
	result := tr.exec(session, map[string]any{"command": "for i in $(seq 1 600); do echo line $i $API_TOKEN; done; " +
		"ln -s .runner hidden"})
	spillPath, _ := result["spillPath"].(string)
	index := strings.Index(spillPath, RunnerDir+"/")
	if index < 0 {
		t.Fatalf("no spill file: %v", result)
	}
	spillFile := spillPath[index:]
	if raw, err := os.ReadFile(filepath.Join(tr.workspace, spillFile)); err != nil || !strings.Contains(string(raw), testSecret) {
		t.Fatalf("the spill file on disk holds the output as printed: %v", err)
	}
	for _, path := range []string{spillFile, "hidden/" + strings.TrimPrefix(spillFile, RunnerDir+"/")} {
		status, content := tr.raw("GET", "/v1/sessions/"+session+"/files/"+path, nil)
		if status != http.StatusOK || strings.Contains(string(content), testSecret) ||
			!strings.Contains(string(content), "line 600 "+SecretMask+"\n") {
			t.Fatalf("read %s: %d, secret masked: %v", path, status, !strings.Contains(string(content), testSecret))
		}
	}

	status, answer = tr.call("GET", "/v1/sessions/"+session+"/files", nil)
	if status != http.StatusOK {
		t.Fatalf("root: %d %v", status, answer)
	}
	for _, entry := range listOf(t, answer, "entries") {
		if entry["name"] == RunnerDir {
			t.Fatalf("the root listing shows the runner's folder: %v", answer)
		}
	}
	for _, path := range []string{RunnerDir, spillFile, "hidden"} {
		if status, answer := tr.call("GET", "/v1/sessions/"+session+"/files?path="+path, nil); status != http.StatusBadRequest {
			t.Fatalf("listing %s: %d %v", path, status, answer)
		}
	}
}

func TestSecretMask(t *testing.T) {
	mask := newSecretMask(map[string]string{"A": "abcd", "B": "abcdef", "C": "xy", "D": ""})
	if got := mask.apply("abcdef abcd xy"); got != "*** *** xy" {
		t.Fatalf("mask: %q", got)
	}
	if got := newSecretMask(nil).apply("abc"); got != "abc" {
		t.Fatalf("no env: %q", got)
	}
}

func TestHistoryCutsAtCharacterBoundariesAndKeepsTheLastHundred(t *testing.T) {
	if head, cut := headBytes("aä", 2); head != "a" || !cut {
		t.Fatalf("head: %q %v", head, cut)
	}
	if tail, cut := tailBytes("äb", 2); tail != "b" || !cut {
		t.Fatalf("tail: %q %v", tail, cut)
	}
	history := newSessionHistory(map[string]string{"TOKEN": testSecret})
	for index := 0; index < HistoryExecs+5; index++ {
		entry := history.start("default", "echo "+testSecret, "", nil)
		history.finish(entry, ExecOK, nil, time.Millisecond, RecordedOutput{
			Stdout: OutputView{Head: strings.Repeat("x", 9000) + testSecret}})
	}
	records := history.Execs(HistoryExecs)
	if len(records) != HistoryExecs || records[0].Seq != HistoryExecs+5 || records[HistoryExecs-1].Seq != 6 {
		t.Fatalf("ring: %d entries, newest %d", len(records), records[0].Seq)
	}
	if records[0].Command != "echo "+SecretMask || len(records[0].StdoutTail) > HistoryTailBytes ||
		!strings.HasSuffix(records[0].StdoutTail, SecretMask) || !records[0].Truncated {
		t.Fatalf("record: %+v", records[0])
	}
}

func TestStreamedMountListing(t *testing.T) {
	state := streamedState()
	listing, found := state.streamedListing(DriveSpacesDir)
	if !found || len(listing.Entries) != 1 || listing.Entries[0].Name != "library" {
		t.Fatalf("mounts: %+v", listing)
	}
	listing, _ = state.streamedListing(DriveSpacesDir + "/library")
	names := []string{}
	for _, entry := range listing.Entries {
		names = append(names, entry.Name)
	}
	if strings.Join(names, ",") != "Brand,Media,.drive-index,SPACE.md,link,readme.md" {
		t.Fatalf("mount root: %v", names)
	}
	listing, _ = state.streamedListing(DriveSpacesDir + "/library/Brand")
	if len(listing.Entries) != 2 || listing.Entries[0].Name != "Fonts" || listing.Entries[1].Name != "Logo.svg" ||
		!listing.Entries[1].NotLocal {
		t.Fatalf("Brand: %+v", listing.Entries)
	}
	if _, found := state.streamedListing(DriveSpacesDir + "/library/Nope"); found {
		t.Fatal("a missing folder must not be found")
	}
	if _, found := state.streamedListing(DriveSpacesDir + "/other"); found {
		t.Fatal("a missing mount must not be found")
	}
}

func TestMountModeOf(t *testing.T) {
	for _, test := range []struct{ filesystem, mode, reason string }{
		{"fuse", "FUSE", ""},
		{"plain: no /dev/fuse", "PLAIN", "no /dev/fuse"},
	} {
		if mode, reason := mountModeOf(test.filesystem, 1); mode != test.mode || reason != test.reason {
			t.Fatalf("%s: %s %s", test.filesystem, mode, reason)
		}
	}
	if mode, reason := mountModeOf("plain", 0); mode != "PLAIN" || reason == "" {
		t.Fatalf("plain without mounts: %s %s", mode, reason)
	}
}
