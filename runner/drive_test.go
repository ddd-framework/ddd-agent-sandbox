package main

import (
	"bytes"
	"crypto/md5"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"
)

func shaOf(content string) string {
	sum := sha256.Sum256([]byte(content))
	return hex.EncodeToString(sum[:])
}

func newDriveRunner(t *testing.T, adjust func(*Config)) *testRunner {
	t.Helper()
	return newTestRunner(t, func(config *Config) {
		base := filepath.Dir(config.Workspace)
		config.DriveStateDir = filepath.Join(base, "drive-state")
		config.DriveSocket = filepath.Join(base, "drive.sock")
		config.Path = filepath.Dir(testExecutable) + ":" + config.Path
		config.DriveThresholdBytes = 1 << 10
		config.DrivePauseLimit = 20 * time.Second
		if adjust != nil {
			adjust(config)
		}
	})
}

func (tr *testRunner) claimDrive(drive map[string]any) (string, map[string]any) {
	tr.t.Helper()
	status, answer := tr.call("POST", "/v1/sessions", map[string]any{"workspaceKey": "drive-test", "mode": "drive", "drive": drive})
	if status != http.StatusCreated {
		tr.t.Fatalf("drive claim: %d %v", status, answer)
	}
	return answer["sessionId"].(string), answer
}

func (tr *testRunner) putBlob(session, content string) {
	tr.t.Helper()
	status, body := tr.raw("PUT", "/v1/sessions/"+session+"/drive/blobs/"+shaOf(content), []byte(content))
	if status != http.StatusOK {
		tr.t.Fatalf("put blob: %d %s", status, body)
	}
}

func (tr *testRunner) shellOut(session, command string) string {
	tr.t.Helper()
	answer := tr.exec(session, map[string]any{"command": command})
	return strings.TrimSpace(stdoutHead(answer))
}

func stringsOf(value any) []string {
	var list []string
	items, _ := value.([]any)
	for _, item := range items {
		switch typed := item.(type) {
		case string:
			list = append(list, typed)
		case map[string]any:
			list = append(list, fmt.Sprint(typed["path"]))
		}
	}
	return list
}

// ---------------------------------------------------------------------------------------------------------------------

func TestDriveIgnoreRules(t *testing.T) {
	rules := parseIgnoreRules(append(DefaultDriveIgnore, "*.log", "/build/"))
	cases := map[string]bool{
		"a.txt": false, "src/.venv": true, "src/.venv/lib/x.py": true, "web/node_modules/a/b.js": true,
		"lib/__pycache__/m.pyc": true, ".git/config": true, "tmp/x": true, "sub/tmp/x": false, "x.log": true,
		"build/out": true, "src/build": false, ".runner/out-1.log": true, "drivespaces/library/a": true,
		"mounts/media/a": true, ".drive-index": true, "work/c1/a.csv": false,
	}
	for relative, expected := range cases {
		if driveExcluded(rules, relative) != expected {
			t.Errorf("%s: excluded %v, expected %v", relative, !expected, expected)
		}
	}
}

func TestSymlinkClassification(t *testing.T) {
	rules := parseIgnoreRules(DefaultDriveIgnore)
	cases := []struct {
		link, target string
		base         *DriveEntry
		kind         string
	}{
		{"reports/latest.md", "2026/plan.md", nil, SymlinkLink},
		{"reports/latest.md", "/workspace/reports/plan.md", nil, SymlinkLink},
		{"pw", "/etc/passwd", nil, SymlinkRefused},
		{"a/up", "../../outside", nil, SymlinkRefused},
		{"lib/data", "../drivespaces/library/x.csv", nil, SymlinkRefused},
		{"lib/env", "../.venv/bin/python", nil, SymlinkRefused},
		{"known", "/somewhere/else", &DriveEntry{Path: "known", LinkTarget: "/somewhere/else", Local: true}, SymlinkLink},
		{"big.bin", "/drive-fuse/12", &DriveEntry{Path: "big.bin", Local: false}, SymlinkPlaceholder},
	}
	for _, entry := range cases {
		kind, _, _ := classifySymlink(rules, "/workspace", entry.link, entry.target, entry.base)
		if kind != entry.kind {
			t.Errorf("%s -> %s: %s, expected %s", entry.link, entry.target, kind, entry.kind)
		}
	}
}

func TestBudgetEvictionOrder(t *testing.T) {
	state := &DriveState{Own: newSpaceState("", 1, MountReadWrite), Mounts: map[string]*DriveSpaceState{}}
	state.Own.Pinned = []string{"keep"}
	add := func(space *DriveSpaceState, relative string, used int64) {
		space.Entries[relative] = &DriveEntry{Path: relative, SHA256: shaOf(relative), Size: 10, Local: true, LastUsedNs: used}
	}
	add(state.Own, "reports/old.csv", 1)
	add(state.Own, "reports/new.csv", 9)
	add(state.Own, "lib/tool.py", 0)
	add(state.Own, "NOTES.md", 0)
	add(state.Own, "keep/a.txt", 0)
	library := newSpaceState("library", 2, MountReadOnly)
	library.Pinned = []string{"Brand"}
	state.Mounts["library"] = library
	add(library, "Brand/logo.svg", 0)
	add(library, "notes.md", 5)
	var order []string
	for _, candidate := range state.evictionOrder() {
		order = append(order, candidate.mount+":"+candidate.entry.Path)
	}
	expected := []string{"library:notes.md", ":reports/old.csv", ":reports/new.csv"}
	if strings.Join(order, ",") != strings.Join(expected, ",") {
		t.Fatalf("eviction order %v, expected %v", order, expected)
	}
}

func TestPausableTimerStandsStill(t *testing.T) {
	timer := newPausableTimer(150 * time.Millisecond)
	timer.Pause()
	select {
	case <-timer.C:
		t.Fatal("fired while paused")
	case <-time.After(300 * time.Millisecond):
	}
	timer.Resume()
	select {
	case <-timer.C:
	case <-time.After(time.Second):
		t.Fatal("did not fire after resume")
	}
	if timer.PausedFor() < 250*time.Millisecond {
		t.Fatalf("paused for %v", timer.PausedFor())
	}
}

func TestPausableTimerRace(t *testing.T) {
	timer := newPausableTimer(time.Hour)
	var group sync.WaitGroup
	for worker := 0; worker < 8; worker++ {
		group.Add(1)
		go func() {
			defer group.Done()
			for round := 0; round < 200; round++ {
				timer.Pause()
				timer.PausedFor()
				timer.Resume()
			}
		}()
	}
	group.Wait()
	timer.Stop()
}

func TestCommandMissPatterns(t *testing.T) {
	state := &DriveState{Own: newSpaceState("", 1, MountReadWrite), Mounts: map[string]*DriveSpaceState{}}
	state.Own.Entries["data/big.bin"] = &DriveEntry{Path: "data/big.bin", Size: 5 << 20}
	world := newSpaceState("world-31", 3, MountReadOnly)
	world.Entries["Briefings/Ablauf.pdf"] = &DriveEntry{Path: "Briefings/Ablauf.pdf", Size: 1200}
	world.Entries["Media/Clip.mp4"] = &DriveEntry{Path: "Media/Clip.mp4", Size: 40 << 20}
	world.Entries["Briefings/local.md"] = &DriveEntry{Path: "Briefings/local.md", Size: 10, Local: true}
	state.Mounts["world-31"] = world
	hits := func(command, cwd string) string {
		hint := state.notLocalHint(commandMissPatterns(command, cwd, "/workspace"), "command")
		if hint == nil {
			return ""
		}
		var paths []string
		for _, hit := range hint.Paths {
			paths = append(paths, hit.Path)
		}
		return strings.Join(paths, ",")
	}
	cases := []struct{ command, cwd, expected string }{
		{"cat data/big.bin", "", "data/big.bin"},
		{"md5sum ../../data/big.bin", "work/c1", "data/big.bin"},
		{"rg Helm drivespaces/world-31", "", "drivespaces/world-31/Briefings/Ablauf.pdf,drivespaces/world-31/Media/Clip.mp4"},
		{"pdftotext 'drivespaces/world-31/Briefings/Ablauf.pdf' -", "", "drivespaces/world-31/Briefings/Ablauf.pdf"},
		{"ls drivespaces/*/Media/*.mp4", "", "drivespaces/world-31/Media/Clip.mp4"},
		{"python3 x.py --input=/workspace/data/big.bin", "", "data/big.bin"},
		{"cat Briefings/local.md", "drivespaces/world-31", ""},
		{"echo hello", "", ""},
	}
	for _, entry := range cases {
		if got := hits(entry.command, entry.cwd); got != entry.expected {
			t.Errorf("%q in %q: %q, expected %q", entry.command, entry.cwd, got, entry.expected)
		}
	}
}

// ---------------------------------------------------------------------------------------------------------------------

func driveClaimBody() map[string]any {
	big := strings.Repeat("B", 4096)
	return map[string]any{
		"spaceId": 7, "cursor": 100,
		"entries": []map[string]any{
			{"path": "notes/a.txt", "nodeId": 1, "versionId": 11, "sha256": shaOf("alpha\n"), "size": 6},
			{"path": "notes/twin.txt", "nodeId": 4, "versionId": 41, "sha256": shaOf("alpha\n"), "size": 6},
			{"path": "data/big.bin", "nodeId": 2, "versionId": 21, "sha256": shaOf(big), "size": len(big)},
			{"path": "lib/x.py", "nodeId": 3, "versionId": 31, "sha256": shaOf("print(1)\n"), "size": 9},
		},
		"mounts": []map[string]any{{
			"name": "library", "spaceId": 9, "mode": "READ_ONLY", "spaceMd": "# Library\naudience: internal\n", "cursor": 5,
			"entries": []map[string]any{
				{"path": "readme.md", "nodeId": 90, "versionId": 901, "sha256": shaOf("read me\n"), "size": 8},
				{"path": "Brand/Logo.svg", "nodeId": 91, "versionId": 911, "sha256": shaOf("<svg/>"), "size": 6},
			},
		}},
	}
}

func TestDriveClaimLayoutStatusAndSymlinks(t *testing.T) {
	tr := newDriveRunner(t, nil)
	session, answer := tr.claimDrive(driveClaimBody())
	drive := answer["drive"].(map[string]any)
	if answer["transfer"] != "stream" || len(drive["needBlobs"].([]any)) != 3 {
		t.Fatalf("claim answer: %v", answer)
	}
	for _, content := range []string{"alpha\n", "print(1)\n", "read me\n"} {
		tr.putBlob(session, content)
	}
	status, materialized := tr.call("POST", "/v1/sessions/"+session+"/drive/materialize", map[string]any{"items": []any{}})
	if status != http.StatusOK || len(materialized["applied"].([]any)) != 4 || len(materialized["needBlobs"].([]any)) != 0 {
		t.Fatalf("materialize: %d %v", status, materialized)
	}
	if got := tr.shellOut(session, "cat notes/a.txt lib/x.py drivespaces/library/readme.md"); got != "alpha\nprint(1)\nread me" {
		t.Fatalf("tree content: %q", got)
	}
	if got := tr.shellOut(session, "test -e data/big.bin && echo local || echo missing; cut -f1,3,4 .drive-index | tail -n +3"); got != "missing\ndata/big.bin\t2\t21" {
		t.Fatalf("own index: %q", got)
	}
	if got := tr.shellOut(session, "tail -n +3 drivespaces/library/.drive-index | cut -f1; head -1 drivespaces/library/SPACE.md"); got != "Brand/Logo.svg\n# Library" {
		t.Fatalf("mount index and SPACE.md: %q", got)
	}
	info, err := os.Stat(filepath.Join(tr.workspace, "drivespaces/library/readme.md"))
	if err != nil || info.Mode().Perm() != 0o444 || int(info.Sys().(*syscall.Stat_t).Uid) != os.Getuid() {
		t.Fatalf("mount file must be runner-owned 0444: %v %v", info, err)
	}
	if os.Geteuid() == 0 {
		out := tr.shellOut(session, "echo x > drivespaces/library/readme.md 2>/dev/null && echo wrote || echo refused; "+
			"touch drivespaces/library/new 2>/dev/null && echo wrote || echo refused; mv drivespaces gone 2>/dev/null && echo moved || echo kept")
		if out != "refused\nrefused\nkept" {
			t.Fatalf("the session must not write into a mount: %q", out)
		}
	}
	tr.shellOut(session, "echo beta >> notes/a.txt; echo new > notes/new.txt; mv lib/x.py lib/y.py; ln -s /etc/passwd pw; ln -s ../notes/a.txt lib/a-link; mkdir -p node_modules/x && echo 1 > node_modules/x/i.js")
	status, view := tr.call("GET", "/v1/sessions/"+session+"/drive/status", nil)
	if status != http.StatusOK {
		t.Fatalf("status: %d %v", status, view)
	}
	var lines []string
	for _, item := range view["changes"].([]any) {
		change := item.(map[string]any)
		lines = append(lines, fmt.Sprintf("%s %s %v", change["kind"], change["path"], change["from"]))
	}
	expected := "added lib/a-link <nil>,renamed lib/y.py lib/x.py,modified notes/a.txt <nil>,added notes/new.txt <nil>"
	if strings.Join(lines, ",") != expected {
		t.Fatalf("changes %v, expected %s", lines, expected)
	}
	if refused := stringsOf(view["refusedSymlinks"]); strings.Join(refused, ",") != "pw" {
		t.Fatalf("refused symlinks: %v", view["refusedSymlinks"])
	}
	if view["notLocalFiles"].(float64) != 1 {
		t.Fatalf("not local: %v", view["notLocalFiles"])
	}
	// legacy manifest still works and leaves the drive's own entries out
	_, manifest := tr.call("GET", "/v1/sessions/"+session+"/manifest", nil)
	for _, file := range stringsOf(manifest["files"]) {
		if strings.HasPrefix(file, "drivespaces/") || file == ".drive-index" {
			t.Fatalf("manifest lists %s", file)
		}
	}
	// the CLI status runs locally, without a pause
	answerCLI := tr.exec(session, map[string]any{"command": "drive status"})
	if answerCLI["status"] != ExecOK || !strings.Contains(stdoutHead(answerCLI), "modified  notes/a.txt") ||
		!strings.Contains(stdoutHead(answerCLI), "refused symlink: pw") {
		t.Fatalf("drive status: %v", answerCLI)
	}
	answerCLI = tr.exec(session, map[string]any{"command": "drive diff notes/a.txt"})
	if !strings.Contains(stdoutHead(answerCLI), "+beta") {
		t.Fatalf("drive diff: %v", answerCLI)
	}
	// the hint: a command naming a not-local file
	hinted := tr.exec(session, map[string]any{"command": "cat data/big.bin drivespaces/library/Brand/Logo.svg 2>/dev/null; true"})
	if paths := stringsOf(hinted["notLocal"]); strings.Join(paths, ",") != "data/big.bin,drivespaces/library/Brand/Logo.svg" ||
		hinted["notLocalCount"].(float64) != 2 {
		t.Fatalf("notLocal hint: %v", hinted["notLocal"])
	}
	if got := tr.shellOut(session, "env | grep -c '^RUNNER_' ; echo ${DRIVE_SOCKET:+set}"); got != "0\nset" {
		t.Fatalf("session environment: %q", got)
	}
	if status, _ := tr.call("DELETE", "/v1/sessions/"+session, nil); status != http.StatusOK {
		t.Fatalf("release: %d", status)
	}
	if left, _ := os.ReadDir(filepath.Join(filepath.Dir(tr.workspace), "drive-state")); len(left) != 0 {
		t.Fatalf("drive state survived the release: %d entries", len(left))
	}
	if entries, _ := os.ReadDir(tr.workspace); len(entries) != 0 {
		t.Fatalf("workspace not wiped: %v", entries)
	}
}

func inodeOf(t *testing.T, path string) uint64 {
	t.Helper()
	info, err := os.Lstat(path)
	if err != nil {
		t.Fatal(err)
	}
	return info.Sys().(*syscall.Stat_t).Ino
}

func TestDriveSnapshotSharesNoInode(t *testing.T) {
	tr := newDriveRunner(t, nil)
	session, _ := tr.claimDrive(driveClaimBody())
	tr.putBlob(session, "alpha\n")
	tr.call("POST", "/v1/sessions/"+session+"/drive/materialize", map[string]any{"items": []any{}})
	cache := filepath.Join(filepath.Dir(tr.workspace), "drive-state", "cache")
	aInode, twinInode := inodeOf(t, filepath.Join(tr.workspace, "notes/a.txt")), inodeOf(t, filepath.Join(tr.workspace, "notes/twin.txt"))
	baseInode := inodeOf(t, filepath.Join(cache, shaOf("alpha\n")))
	if aInode == twinInode || aInode == baseInode || twinInode == baseInode {
		t.Fatalf("tree files share an inode: %d %d %d", aInode, twinInode, baseInode)
	}
	tr.shellOut(session, "printf 'changed\n' > notes/a.txt")
	status, staged := tr.call("POST", "/v1/sessions/"+session+"/drive/stage", nil)
	if status != http.StatusOK || staged["snapshots"].(float64) != 1 {
		t.Fatalf("stage: %d %v", status, staged)
	}
	snapshot := filepath.Join(cache, shaOf("changed\n"))
	if inodeOf(t, snapshot) == inodeOf(t, filepath.Join(tr.workspace, "notes/a.txt")) {
		t.Fatal("the snapshot shares the tree file's inode")
	}
	info, _ := os.Stat(snapshot)
	if info.Mode().Perm() != 0o400 {
		t.Fatalf("snapshot mode %v", info.Mode())
	}
	// a write after the stage never reaches the snapshot; the upload source stays the staged bytes
	tr.shellOut(session, "printf 'later\n' >> notes/a.txt")
	code, content := tr.raw("GET", "/v1/sessions/"+session+"/drive/blobs/"+shaOf("changed\n"), nil)
	if code != http.StatusOK || string(content) != "changed\n" {
		t.Fatalf("blob: %d %q", code, content)
	}
	if code, _ := tr.raw("PUT", "/v1/sessions/"+session+"/drive/blobs/"+shaOf("other"), []byte("not other")); code != http.StatusBadRequest {
		t.Fatalf("hash mismatch must be refused: %d", code)
	}
	// commit moves the base; status is clean for the committed content
	tr.shellOut(session, "printf 'changed\n' > notes/a.txt")
	status, after := tr.call("POST", "/v1/sessions/"+session+"/drive/commit", map[string]any{
		"committed": []map[string]any{{"path": "notes/a.txt", "nodeId": 1, "versionId": 12, "sha256": shaOf("changed\n"), "size": 8}},
	})
	if status != http.StatusOK || len(after["changes"].([]any)) != 0 {
		t.Fatalf("commit: %d %v", status, after)
	}
}

func TestDrivePullMergesConflictsAndBinary(t *testing.T) {
	tr := newDriveRunner(t, nil)
	base := "one\ntwo\nthree\nfour\nfive\n"
	session, _ := tr.claimDrive(map[string]any{"spaceId": 7, "entries": []map[string]any{
		{"path": "plan.md", "nodeId": 1, "versionId": 1, "sha256": shaOf(base), "size": len(base)},
		{"path": "clash.md", "nodeId": 2, "versionId": 1, "sha256": shaOf(base), "size": len(base)},
		{"path": "image.dat", "nodeId": 3, "versionId": 1, "sha256": shaOf("a\x00b"), "size": 3},
		{"path": "untouched.md", "nodeId": 4, "versionId": 1, "sha256": shaOf(base), "size": len(base)},
		{"path": "gone.md", "nodeId": 5, "versionId": 1, "sha256": shaOf(base), "size": len(base)},
	}})
	tr.putBlob(session, base)
	tr.putBlob(session, "a\x00b")
	tr.call("POST", "/v1/sessions/"+session+"/drive/materialize", map[string]any{"items": []any{}})
	tr.shellOut(session, "sed -i 's/^one$/ONE/' plan.md; sed -i 's/^three$/MINE/' clash.md; printf 'a\\0c' > image.dat; sed -i 's/^two$/TWO/' gone.md")
	theirsPlan := strings.Replace(base, "five", "FIVE", 1)
	theirsClash := strings.Replace(base, "three", "THEIRS", 1)
	theirsUntouched := base + "six\n"
	for _, content := range []string{theirsPlan, theirsClash, "a\x00d", theirsUntouched} {
		tr.putBlob(session, content)
	}
	status, result := tr.call("POST", "/v1/sessions/"+session+"/drive/pull", map[string]any{"cursor": 7, "changes": []map[string]any{
		{"op": "update", "path": "plan.md", "nodeId": 1, "versionId": 2, "sha256": shaOf(theirsPlan), "size": len(theirsPlan)},
		{"op": "update", "path": "clash.md", "nodeId": 2, "versionId": 2, "sha256": shaOf(theirsClash), "size": len(theirsClash)},
		{"op": "update", "path": "image.dat", "nodeId": 3, "versionId": 2, "sha256": shaOf("a\x00d"), "size": 3},
		{"op": "update", "path": "untouched.md", "nodeId": 4, "versionId": 2, "sha256": shaOf(theirsUntouched), "size": len(theirsUntouched)},
		{"op": "delete", "path": "gone.md", "nodeId": 5, "versionId": 2},
	}})
	if status != http.StatusOK {
		t.Fatalf("pull: %d %v", status, result)
	}
	if merged := stringsOf(result["merged"]); strings.Join(merged, ",") != "plan.md" {
		t.Fatalf("merged: %v", result)
	}
	kinds := map[string]string{}
	for _, item := range result["conflicts"].([]any) {
		conflict := item.(map[string]any)
		kinds[conflict["path"].(string)] = fmt.Sprintf("%v|%v|%v", conflict["kind"], conflict["binary"], conflict["sideFile"] != nil)
	}
	if kinds["clash.md"] != "both-changed|<nil>|true" || kinds["image.dat"] != "both-changed|true|false" || kinds["gone.md"] != "trashed|<nil>|false" {
		t.Fatalf("conflicts: %v", kinds)
	}
	if got := tr.shellOut(session, "cat plan.md | tr '\\n' ' '"); got != "ONE two three four FIVE" {
		t.Fatalf("merged content: %q", got)
	}
	if got := tr.shellOut(session, "grep -c MINE clash.md; grep -c THEIRS clash.md; grep -c '<<<<<<<' .runner/conflicts/clash.md"); got != "1\n0\n1" {
		t.Fatalf("conflict must leave the tree alone and markers in the side file: %q", got)
	}
	if got := tr.shellOut(session, "tail -1 untouched.md; od -c image.dat | head -1 | tr -s ' '"); got != "six\n0000000 a \\0 c" {
		t.Fatalf("untouched replaced, binary kept: %q", got)
	}
	// the merge endpoint: theirs changes another line of plan.md
	theirsAgain := strings.Replace(theirsPlan, "four", "FOUR", 1)
	tr.putBlob(session, theirsAgain)
	status, merged := tr.call("POST", "/v1/sessions/"+session+"/drive/merge", map[string]any{"path": "plan.md",
		"theirs": map[string]any{"nodeId": 1, "versionId": 3, "sha256": shaOf(theirsAgain), "size": len(theirsAgain)}})
	if status != http.StatusOK || merged["result"] != "merged" {
		t.Fatalf("merge endpoint: %d %v", status, merged)
	}
	// resolve: theirs for the text conflict, mine for the binary one
	if status, resolved := tr.call("POST", "/v1/sessions/"+session+"/drive/resolve", map[string]any{"path": "clash.md", "resolution": "theirs"}); status != http.StatusOK {
		t.Fatalf("resolve: %d %v", status, resolved)
	}
	tr.call("POST", "/v1/sessions/"+session+"/drive/resolve", map[string]any{"path": "image.dat", "resolution": "mine"})
	_, view := tr.call("GET", "/v1/sessions/"+session+"/drive/status", nil)
	if len(view["conflicts"].([]any)) != 1 || tr.shellOut(session, "grep -c THEIRS clash.md; test -e .runner/conflicts/clash.md && echo side || echo clean") != "1\nclean" {
		t.Fatalf("after resolve: %v", view["conflicts"])
	}
	if view["cursor"].(float64) != 7 {
		t.Fatalf("cursor: %v", view["cursor"])
	}
}

func TestDriveMirrorBudget(t *testing.T) {
	tr := newDriveRunner(t, func(config *Config) { config.DriveThresholdBytes = 1 << 20 })
	content := map[string]string{"NOTES.md": strings.Repeat("n", 300), "lib/keep.py": strings.Repeat("k", 300),
		"reports/old.csv": strings.Repeat("o", 300), "reports/new.csv": strings.Repeat("w", 300)}
	var entries []map[string]any
	node := 1
	for relative, body := range content {
		entries = append(entries, map[string]any{"path": relative, "nodeId": node, "versionId": node, "sha256": shaOf(body), "size": len(body)})
		node++
	}
	// tree plus cache count: four files of 300 bytes are 2,400 bytes; a budget of 1,900 drops one
	session, _ := tr.claimDrive(map[string]any{"spaceId": 7, "mirrorBudgetBytes": 1 << 20, "entries": entries})
	for _, body := range content {
		tr.putBlob(session, body)
	}
	tr.call("POST", "/v1/sessions/"+session+"/drive/materialize", map[string]any{"items": []any{}})
	tr.shellOut(session, "true")
	state := tr.runner.session.drive
	state.mutex.Lock()
	state.Own.Entries["reports/old.csv"].LastUsedNs = 1
	state.Own.Entries["reports/new.csv"].LastUsedNs = time.Now().UnixNano()
	state.MirrorBudgetBytes = 1900
	state.mutex.Unlock()
	status, result := tr.call("POST", "/v1/sessions/"+session+"/drive/pull", map[string]any{"cursor": 1, "changes": []any{}})
	if status != http.StatusOK || strings.Join(stringsOf(result["evicted"]), ",") != "reports/old.csv" {
		t.Fatalf("budget: %d %v", status, result)
	}
	if got := tr.shellOut(session, "ls NOTES.md lib/keep.py reports/ | tr '\\n' ' '; grep -c old.csv .drive-index"); got != "NOTES.md lib/keep.py  reports/: new.csv 1" {
		t.Fatalf("after eviction: %q", got)
	}
	// a locally changed file is never dropped
	tr.shellOut(session, "echo x >> reports/new.csv")
	state.mutex.Lock()
	state.MirrorBudgetBytes = 1
	state.mutex.Unlock()
	_, result = tr.call("POST", "/v1/sessions/"+session+"/drive/pull", map[string]any{"cursor": 2, "changes": []any{}})
	if got := tr.shellOut(session, "test -e reports/new.csv && echo kept; test -e NOTES.md && echo kept"); got != "kept\nkept" {
		t.Fatalf("changed or kept files dropped: %q %v", got, result)
	}
}

func TestDriveCLIPausesExecAndFailsInJobs(t *testing.T) {
	tr := newDriveRunner(t, nil)
	session, _ := tr.claimDrive(driveClaimBody())
	tr.putBlob(session, "alpha\n")
	tr.call("POST", "/v1/sessions/"+session+"/drive/materialize", map[string]any{"items": []any{}})
	// foreground: drive pull pauses; the exec timer (2 s) stands still while PHP takes 3 s
	answer := tr.exec(session, map[string]any{"command": "drive pull && echo after-pull", "timeoutSeconds": 2})
	if answer["status"] != ExecDriveRequests {
		t.Fatalf("expected a pause: %v", answer)
	}
	requests := answer["driveRequests"].([]any)
	request := requests[0].(map[string]any)
	if len(requests) != 1 || request["verb"] != "pull" {
		t.Fatalf("drive requests: %v", requests)
	}
	time.Sleep(3 * time.Second)
	seq := int(answer["seq"].(float64))
	tr.putBlob(session, "alpha\nbeta\n")
	status, final := tr.call("POST", fmt.Sprintf("/v1/sessions/%s/execs/%d/continue", session, seq), map[string]any{
		"responses": []map[string]any{{"requestId": request["requestId"], "ok": true, "pulls": []map[string]any{{
			"cursor": 101, "changes": []map[string]any{{"op": "update", "path": "notes/a.txt", "nodeId": 1, "versionId": 12,
				"sha256": shaOf("alpha\nbeta\n"), "size": 11}}}}}}})
	if status != http.StatusOK || final["status"] != ExecOK || final["timedOut"] != false ||
		!strings.Contains(stdoutHead(final), "updated 1 paths") || !strings.Contains(stdoutHead(final), "after-pull") {
		t.Fatalf("continue: %d %v", status, final)
	}
	if final["pausedMs"].(float64) < 2500 {
		t.Fatalf("pausedMs %v", final["pausedMs"])
	}
	if got := tr.shellOut(session, "cat notes/a.txt"); got != "alpha\nbeta" {
		t.Fatalf("pulled content: %q", got)
	}
	// get: the request names the not-local file; PHP answers with a materialize item (blob pushed first)
	answer = tr.exec(session, map[string]any{"command": "cd notes && drive get ../data/big.bin && wc -c < ../data/big.bin"})
	if answer["status"] != ExecDriveRequests {
		t.Fatalf("get did not pause: %v", answer)
	}
	request = answer["driveRequests"].([]any)[0].(map[string]any)
	items := request["payload"].(map[string]any)["items"].([]any)
	if request["verb"] != "get" || items[0].(map[string]any)["path"] != "data/big.bin" {
		t.Fatalf("get request: %v", request)
	}
	tr.putBlob(session, strings.Repeat("B", 4096))
	_, final = tr.call("POST", fmt.Sprintf("/v1/sessions/%s/execs/%d/continue", session, int(answer["seq"].(float64))), map[string]any{
		"responses": []map[string]any{{"requestId": request["requestId"], "ok": true, "materialize": []map[string]any{{"path": "data/big.bin"}}}}})
	if !strings.HasSuffix(strings.TrimSpace(stdoutHead(final)), "4096") {
		t.Fatalf("get result: %v", final)
	}
	// commit: the request carries the staged changes; PHP's commit result moves the base
	tr.shellOut(session, "cd \"$WORKSPACE\" && echo gamma >> notes/a.txt")
	answer = tr.exec(session, map[string]any{"command": "drive commit -m 'Zuordnung'"})
	if answer["status"] != ExecDriveRequests {
		t.Fatalf("commit did not pause: %v", answer)
	}
	request = answer["driveRequests"].([]any)[0].(map[string]any)
	payload := request["payload"].(map[string]any)
	change := payload["changes"].([]any)[0].(map[string]any)
	if payload["message"] != "Zuordnung" || change["path"] != "notes/a.txt" || change["sha256"] != shaOf("alpha\nbeta\ngamma\n") {
		t.Fatalf("commit request: %v", payload)
	}
	_, final = tr.call("POST", fmt.Sprintf("/v1/sessions/%s/execs/%d/continue", session, int(answer["seq"].(float64))), map[string]any{
		"responses": []map[string]any{{"requestId": request["requestId"], "ok": true, "commit": map[string]any{
			"committed": []map[string]any{{"path": "notes/a.txt", "nodeId": 1, "versionId": 13, "versionNumber": 3, "sha256": change["sha256"], "size": change["size"]}}}}}})
	if !strings.Contains(stdoutHead(final), "committed 1 files: notes/a.txt v3") {
		t.Fatalf("commit result: %v", final)
	}
	// a refused request reaches the CLI as its error
	answer = tr.exec(session, map[string]any{"command": "drive pin drivespaces/library/Brand; echo rc=$?"})
	request = answer["driveRequests"].([]any)[0].(map[string]any)
	_, final = tr.call("POST", fmt.Sprintf("/v1/sessions/%s/execs/%d/continue", session, int(answer["seq"].(float64))), map[string]any{
		"responses": []map[string]any{{"requestId": request["requestId"], "ok": false, "message": "pin cap reached"}}})
	if !strings.Contains(stdoutHead(final), "rc=1") || !strings.Contains(final["stderr"].(map[string]any)["head"].(string), "pin cap reached") {
		t.Fatalf("refused pin: %v", final)
	}
	// an unknown request id changes nothing
	answer = tr.exec(session, map[string]any{"command": "drive pull"})
	if status, _ := tr.call("POST", fmt.Sprintf("/v1/sessions/%s/execs/%d/continue", session, int(answer["seq"].(float64))), map[string]any{
		"responses": []map[string]any{{"requestId": "dr_nope", "ok": true}}}); status != http.StatusBadRequest {
		t.Fatalf("unknown request id: %d", status)
	}
	request = answer["driveRequests"].([]any)[0].(map[string]any)
	tr.call("POST", fmt.Sprintf("/v1/sessions/%s/execs/%d/continue", session, int(answer["seq"].(float64))), map[string]any{
		"responses": []map[string]any{{"requestId": request["requestId"], "ok": true}}})
	// background job: the verbs that need PHP fail at once with the hint
	job := tr.exec(session, map[string]any{"command": "drive commit -m x; drive status >/dev/null && echo status-ok", "background": true})
	jobID := job["jobId"].(string)
	var view map[string]any
	for attempt := 0; attempt < 100; attempt++ {
		_, view = tr.call("GET", "/v1/sessions/"+session+"/jobs/"+jobID, nil)
		if view["state"] != JobRunning {
			break
		}
		time.Sleep(50 * time.Millisecond)
	}
	if !strings.Contains(view["stderr"].(map[string]any)["head"].(string), "background job") || !strings.Contains(stdoutHead(view), "status-ok") {
		t.Fatalf("job: %v", view)
	}
}

func TestDriveConcurrentOperationsRace(t *testing.T) {
	tr := newDriveRunner(t, nil)
	session, _ := tr.claimDrive(driveClaimBody())
	tr.putBlob(session, "alpha\n")
	tr.call("POST", "/v1/sessions/"+session+"/drive/materialize", map[string]any{"items": []any{}})
	var group sync.WaitGroup
	for worker := 0; worker < 4; worker++ {
		group.Add(1)
		go func(worker int) {
			defer group.Done()
			for round := 0; round < 5; round++ {
				switch (worker + round) % 4 {
				case 0:
					tr.call("GET", "/v1/sessions/"+session+"/drive/status", nil)
				case 1:
					tr.call("POST", "/v1/sessions/"+session+"/drive/stage", nil)
				case 2:
					tr.call("POST", "/v1/sessions/"+session+"/drive/pull", map[string]any{"cursor": round, "changes": []any{}})
				default:
					tr.runner.session.commandHint("cat data/big.bin", tr.workspace)
				}
			}
		}(worker)
	}
	tr.exec(session, map[string]any{"command": "for i in 1 2 3; do echo $i > notes/n$i.txt; drive status >/dev/null; done", "shell": "other"})
	group.Wait()
	_, view := tr.call("GET", "/v1/sessions/"+session+"/drive/status", nil)
	if len(view["changes"].([]any)) != 3 {
		t.Fatalf("changes after concurrent operations: %v", view["changes"])
	}
}

func TestDriveProxyTransfer(t *testing.T) {
	content := "from the store\n"
	var mutex sync.Mutex
	var uploaded []byte
	var uploadMD5, token, ifNoneMatch string
	proxy := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		mutex.Lock()
		defer mutex.Unlock()
		// a forward proxy gets the absolute form
		if request.URL.Host != "bucket.example" {
			http.Error(writer, "host", http.StatusForbidden)
			return
		}
		token, ifNoneMatch = request.Header.Get("X-Transfer-Token"), request.Header.Get("If-None-Match")
		switch request.Method {
		case http.MethodGet:
			io.WriteString(writer, content)
		case http.MethodPut:
			uploaded, _ = io.ReadAll(request.Body)
			uploadMD5 = request.Header.Get("Content-MD5")
			writer.Header().Set("ETag", `"e1"`)
		}
	}))
	defer proxy.Close()
	tr := newDriveRunner(t, func(config *Config) {
		config.TransferProxyURL = proxy.URL
		config.TransferProxyToken = "test-credential"
	})
	client, err := newTransferClient(tr.runner.config)
	if err != nil {
		t.Fatal(err)
	}
	tr.runner.transfer = client
	session, answer := tr.claimDrive(map[string]any{"spaceId": 7, "entries": []map[string]any{
		{"path": "store.txt", "nodeId": 1, "versionId": 1, "sha256": shaOf(content), "size": len(content),
			"url": "https://bucket.example/blob?X-Amz-Signature=abc"},
	}})
	if answer["transfer"] != "proxy" || tr.shellOut(session, "cat store.txt") != strings.TrimSpace(content) {
		t.Fatalf("proxy claim: %v", answer)
	}
	if token != "test-credential" {
		t.Fatalf("proxy token header: %q", token)
	}
	if got := tr.shellOut(session, "env | grep -c test-credential"); got != "0" {
		t.Fatal("the proxy credential reached the session")
	}
	tr.shellOut(session, "echo more >> store.txt")
	_, staged := tr.call("POST", "/v1/sessions/"+session+"/drive/stage", nil)
	sha := staged["changes"].([]any)[0].(map[string]any)["sha256"].(string)
	// PHP's signed headers go out exactly as given: If-None-Match and Content-MD5
	expectedMD5 := md5.Sum([]byte(content + "more\n"))
	wholeMD5 := base64.StdEncoding.EncodeToString(expectedMD5[:])
	status, result := tr.call("POST", "/v1/sessions/"+session+"/drive/upload", map[string]any{"uploads": []map[string]any{
		{"sha256": sha, "url": "https://bucket.example/new?X-Amz-Signature=def", "headers": map[string]string{"If-None-Match": "*", "Content-MD5": wholeMD5}}}})
	outcome := result["uploads"].([]any)[0].(map[string]any)
	if status != http.StatusOK || outcome["ok"] != true || outcome["etag"] != `"e1"` || !bytes.Equal(uploaded, []byte(content+"more\n")) ||
		uploadMD5 != wholeMD5 || ifNoneMatch != "*" || token != "test-credential" {
		t.Fatalf("upload: %d %v md5=%s ifNoneMatch=%q", status, result, uploadMD5, ifNoneMatch)
	}
	// a Content-MD5 that does not match the snapshot is refused before anything is sent
	uploaded = nil
	_, result = tr.call("POST", "/v1/sessions/"+session+"/drive/upload", map[string]any{"uploads": []map[string]any{
		{"sha256": sha, "url": "https://bucket.example/new", "headers": map[string]string{"Content-MD5": "AAAAAAAAAAAAAAAAAAAAAA=="}}}})
	if result["uploads"].([]any)[0].(map[string]any)["error"] != "CONTENT_MD5_MISMATCH" || uploaded != nil {
		t.Fatalf("md5 mismatch: %v", result)
	}
	// a part of a multipart upload, with the part's Content-MD5
	partMD5 := md5.Sum([]byte((content + "more\n")[5:9]))
	status, result = tr.call("POST", "/v1/sessions/"+session+"/drive/upload", map[string]any{"uploads": []map[string]any{
		{"sha256": sha, "url": "https://bucket.example/new?partNumber=2", "offset": 5, "length": 4,
			"headers": map[string]string{"Content-MD5": base64.StdEncoding.EncodeToString(partMD5[:])}}}})
	if status != http.StatusOK || string(uploaded) != (content + "more\n")[5:9] || result["uploads"].([]any)[0].(map[string]any)["ok"] != true {
		t.Fatalf("part upload: %v %q", result, uploaded)
	}
	encoded, _ := json.Marshal(result)
	if strings.Contains(string(encoded), "test-credential") {
		t.Fatal("the credential appears in an answer")
	}
}

// The state directory as on the cluster: a pre-existing directory (a subPath mount of the session volume) that the
// runner must empty but cannot remove.
func TestDriveStateDirIsEmptiedNotRemoved(t *testing.T) {
	var stateDir string
	tr := newDriveRunner(t, func(config *Config) {
		stateDir = filepath.Join(filepath.Dir(config.Workspace), "var-lib-sandbox-drive")
		config.DriveStateDir = stateDir
	})
	if err := os.Mkdir(stateDir, 0o700); err != nil {
		t.Fatal(err)
	}
	before := inodeOf(t, stateDir)
	session, _ := tr.claimDrive(driveClaimBody())
	tr.putBlob(session, "alpha\n")
	tr.call("POST", "/v1/sessions/"+session+"/drive/materialize", map[string]any{"items": []any{}})
	tr.shellOut(session, "echo more >> notes/a.txt")
	tr.call("POST", "/v1/sessions/"+session+"/drive/stage", nil)
	if blobs, _ := os.ReadDir(filepath.Join(stateDir, "cache")); len(blobs) < 2 {
		t.Fatalf("expected cached blobs before the release, found %d", len(blobs))
	}
	if status, _ := tr.call("DELETE", "/v1/sessions/"+session, nil); status != http.StatusOK {
		t.Fatalf("release: %d", status)
	}
	left, err := os.ReadDir(stateDir)
	if err != nil || len(left) != 0 || inodeOf(t, stateDir) != before {
		t.Fatalf("release must empty the state directory and keep it: %d entries, err %v", len(left), err)
	}
	// leftovers (as if a release had failed half-way) are removed by the next claim
	os.MkdirAll(filepath.Join(stateDir, "cache"), 0o700)
	os.WriteFile(filepath.Join(stateDir, "cache", shaOf("alpha\n")), []byte("alpha\n"), 0o400)
	os.WriteFile(filepath.Join(stateDir, "state.json"), []byte(`{"own":{"entries":{"notes/a.txt":{}}}}`), 0o600)
	status, answer := tr.call("POST", "/v1/sessions", map[string]any{"workspaceKey": "drive-test-2", "mode": "drive",
		"drive": map[string]any{"spaceId": 8}})
	if status != http.StatusCreated {
		t.Fatalf("second claim: %d %v", status, answer)
	}
	second := answer["sessionId"].(string)
	state := tr.runner.session.drive
	state.mutex.Lock()
	entries, mounts := len(state.Own.Entries), len(state.Mounts)
	state.mutex.Unlock()
	if entries != 0 || mounts != 0 || state.hasBlob(shaOf("alpha\n")) {
		t.Fatalf("the second claim sees the first session: %d entries, %d mounts", entries, mounts)
	}
	if blobs, _ := os.ReadDir(filepath.Join(stateDir, "cache")); len(blobs) != 0 {
		t.Fatalf("the second claim found %d cached blobs", len(blobs))
	}
	_, view := tr.call("GET", "/v1/sessions/"+second+"/drive/status", nil)
	if len(view["changes"].([]any)) != 0 || view["notLocalFiles"].(float64) != 0 || view["spaceId"].(float64) != 8 {
		t.Fatalf("second session status: %v", view)
	}
	tr.call("DELETE", "/v1/sessions/"+second, nil)
	if left, _ := os.ReadDir(stateDir); len(left) != 0 {
		t.Fatalf("second release left %d entries", len(left))
	}
}
