package main

import (
	"path/filepath"
	"testing"
)

// The agent's shell starts in work/<conversation>/; a drive path named from /workspace still resolves for drive get
// and drive pin, while a path that exists from the shell's folder keeps its meaning.
func TestCliDrivePathTriesTheWorkspace(t *testing.T) {
	workspace := t.TempDir()
	session := &Session{runner: &Runner{config: Config{Workspace: workspace}}, drive: &DriveState{
		Own: &DriveSpaceState{Entries: map[string]*DriveEntry{
			"texte/einladung.md":                 {},
			"work/conversation-7/texte/notiz.md": {},
		}},
		Mounts: map[string]*DriveSpaceState{"library": {Name: "library", Entries: map[string]*DriveEntry{
			"Templates/Brand/README.md": {},
		}}},
	}}
	shellFolder := filepath.Join(workspace, "work/conversation-7")
	cases := []struct{ argument, expected string }{
		{"texte/einladung.md", "texte/einladung.md"},
		{"texte/notiz.md", "work/conversation-7/texte/notiz.md"},
		{"drivespaces/library/Templates/Brand", "drivespaces/library/Templates/Brand"},
		{"drivespaces/library", "drivespaces/library"},
		{"/workspace-elsewhere/x", ""},
		{"texte/fehlt.md", "work/conversation-7/texte/fehlt.md"},
	}
	for _, testCase := range cases {
		got, _ := session.cliDrivePath(testCase.argument, shellFolder)
		if got != testCase.expected {
			t.Errorf("cliDrivePath(%q) = %q, expected %q", testCase.argument, got, testCase.expected)
		}
	}
	if got, ok := session.cliDrivePath(filepath.Join(workspace, "texte/einladung.md"), shellFolder); !ok || got != "texte/einladung.md" {
		t.Errorf("an absolute path stays as it is: %q %v", got, ok)
	}
}
