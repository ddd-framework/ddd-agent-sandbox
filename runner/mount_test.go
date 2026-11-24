package main

import (
	"os"
	"path/filepath"
	"testing"
)

const sampleMountInfo = `22 1 0:21 / / rw,relatime - overlay overlay rw,lowerdir=/l
31 22 0:30 / /workspace rw,nosuid,nodev,relatime - ext4 /dev/mapper/vg-pvc--1 rw
32 22 0:31 / /tmp rw,nosuid,nodev - tmpfs tmpfs rw,size=262144k
33 22 0:32 / /with\040space rw - xfs /dev/sdb rw
34 31 0:33 / /workspace rw - tmpfs tmpfs rw,size=65536k
`

func TestParseMountInfo(t *testing.T) {
	cases := []struct {
		path, fsType string
		found        bool
	}{
		{"/workspace", "tmpfs", true}, // the later mount covers the earlier one
		{"/workspace/", "tmpfs", true},
		{"/tmp", "tmpfs", true},
		{"/with space", "xfs", true},
		{"/workspace/sub", "", false},
		{"/var", "", false},
	}
	for _, testCase := range cases {
		fsType, found := parseMountInfo([]byte(sampleMountInfo), testCase.path)
		if fsType != testCase.fsType || found != testCase.found {
			t.Errorf("%s: %q %v, want %q %v", testCase.path, fsType, found, testCase.fsType, testCase.found)
		}
	}
}

func TestInspectMountOfAPlainDirectory(t *testing.T) {
	directory := t.TempDir()
	state := InspectMount(directory)
	if state.OwnMount {
		t.Fatal("a temporary directory is no mount of its own")
	}
	if state.SizeBytes <= 0 || state.FreeBytes <= 0 || state.FreeBytes > state.SizeBytes {
		t.Fatalf("statfs: %+v", state)
	}
	// the root of the container is a mount
	if root := InspectMount("/"); !root.OwnMount || root.Type == "" {
		t.Fatalf("root: %+v", root)
	}
	os.WriteFile(filepath.Join(directory, "x"), []byte("x"), 0o644)
}

func TestTheSmallerLimitWins(t *testing.T) {
	const gib = int64(1) << 30
	// quota 3.6 GiB on a 4 GiB volume: the quota decides
	if remaining, reason := remainingBytes(36*gib/10, gib, 3*gib, 16<<20); reason != QuotaLimit || remaining != 26*gib/10 {
		t.Fatalf("quota decides: %d %s", remaining, reason)
	}
	// a small volume (64 MiB tmpfs in the local test): the free space decides
	if remaining, reason := remainingBytes(36*gib/10, 10<<20, 54<<20, 16<<20); reason != QuotaNoSpace || remaining != 38<<20 {
		t.Fatalf("free space decides: %d %s", remaining, reason)
	}
	if limit := effectiveLimit(36*gib/10, gib, 3*gib, 16<<20); limit != 36*gib/10 {
		t.Fatalf("effective limit by quota: %d", limit)
	}
	if limit := effectiveLimit(36*gib/10, 10<<20, 54<<20, 16<<20); limit != 48<<20 {
		t.Fatalf("effective limit by free space: %d", limit)
	}
	if limit := effectiveLimit(gib, 60<<20, 4<<20, 16<<20); limit != 60<<20 {
		t.Fatalf("full mount: the limit is what is used: %d", limit)
	}
	for _, check := range []struct {
		usage, free int64
		want        string
	}{
		{gib, 3 * gib, ""},
		{4 * gib, 3 * gib, QuotaLimit},
		{gib, 8 << 20, QuotaNoSpace},
		{4 * gib, 0, QuotaNoSpace},
	} {
		if got := quotaExceededBy(36*gib/10, check.usage, check.free, 16<<20); got != check.want {
			t.Errorf("usage %d free %d: %q, want %q", check.usage, check.free, got, check.want)
		}
	}
}

// the free space of the workspace's file system is a limit of its own: with a reserve larger than the free space,
// every PUT is refused as NO_SPACE and the end-of-exec check reports it
func TestFreeSpaceIsASecondLimit(t *testing.T) {
	tr := newTestRunner(t, func(config *Config) {
		_, free, _, _ := statfsBytes(config.Workspace)
		config.FreeReserve = free + 1<<30
		config.QuotaDirs = []string{config.Workspace}
	})
	session := tr.claim("free-space")
	status, answer := tr.rawJSON("PUT", "/v1/sessions/"+session+"/files/a.txt", []byte("hello"))
	if status != 413 || answer["reason"] != QuotaNoSpace || answer["limitBytes"].(float64) != 0 {
		t.Fatalf("PUT on a full mount: %d %v", status, answer)
	}
	execAnswer := tr.exec(session, map[string]any{"command": "true"})
	quota, _ := execAnswer["quota"].(map[string]any)
	if execAnswer["status"] != ExecQuotaExceeded || quota["reason"] != QuotaNoSpace {
		t.Fatalf("exec on a full mount: %v", execAnswer)
	}
	if status, health := tr.call("GET", "/healthz", nil); status != 200 || health["workspaceMount"].(map[string]any)["sizeBytes"].(float64) <= 0 {
		t.Fatalf("healthz: %d %v", status, health)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}

func TestLostAndFoundIsRemovedWhenEmptyAndClosedOtherwise(t *testing.T) {
	tr := newTestRunner(t, nil)
	lostFound := filepath.Join(tr.workspace, lostAndFound)
	// empty: removed by the wipe
	if err := os.Mkdir(lostFound, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := tr.runner.Wipe(); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Lstat(lostFound); !os.IsNotExist(err) {
		t.Fatalf("an empty lost+found must go: %v", err)
	}
	// empty again (fsck recreated it while the runner was idle): removed at the claim, the session never sees it
	os.Mkdir(lostFound, 0o700)
	session := tr.claim("lost-found")
	// find fails on a closed folder like rg does (the image build's Go stage has no rg; integration.sh runs rg)
	answer := tr.exec(session, map[string]any{"command": "ls -A; find . -mindepth 1; echo rc=$?"})
	if stdoutHead(answer) != "rc=0\n" || answer["stderr"].(map[string]any)["head"] != "" {
		t.Fatalf("session sees: %v", answer)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
	// not empty: kept, closed to 0700, its content untouched, out of the manifest
	os.Mkdir(lostFound, 0o755)
	os.WriteFile(filepath.Join(lostFound, "#12345"), []byte("recovered"), 0o600)
	if err := tr.runner.Wipe(); err != nil {
		t.Fatal(err)
	}
	info, err := os.Lstat(lostFound)
	if err != nil || info.Mode().Perm() != 0o700 {
		t.Fatalf("a non-empty lost+found stays at 0700: %v %v", info, err)
	}
	if content, _ := os.ReadFile(filepath.Join(lostFound, "#12345")); string(content) != "recovered" {
		t.Fatal("the recovered file must stay")
	}
	session = tr.claim("lost-found-kept")
	_, manifest := tr.call("GET", "/v1/sessions/"+session+"/manifest", nil)
	if manifest["count"].(float64) != 0 {
		t.Fatalf("lost+found must stay out of the manifest: %v", manifest)
	}
	tr.call("DELETE", "/v1/sessions/"+session, nil)
}
