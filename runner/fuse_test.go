package main

import (
	"reflect"
	"testing"
)

func streamedState() *DriveState {
	state := &DriveState{Own: newSpaceState("", 1, MountReadWrite), Mounts: map[string]*DriveSpaceState{},
		Staged: map[string][]string{}, Streamed: true, stateDir: "/state"}
	mount := newSpaceState("library", 9, MountReadOnly)
	mount.SpaceMD = "# Library\n"
	for _, entry := range []*DriveEntry{
		{Path: "readme.md", SHA256: "aa", Size: 7, Local: true},
		{Path: "Brand/Logo.svg", SHA256: "bb", Size: 3},
		{Path: "Brand/Fonts/Sans.ttf", SHA256: "cc", Size: 30, Local: true},
		{Path: "Media/Clip.mp4", SHA256: "dd", Size: 40 << 20},
		{Path: "link", LinkTarget: "readme.md"},
	} {
		mount.Entries[entry.Path] = entry
	}
	state.Mounts["library"] = mount
	return state
}

// TestFuseViewListsEveryFolderAndFile: the snapshot a mount serves: every file with size and locality, every folder
// with its children (folders marked), links left out, SPACE.md with the streamed note.
func TestFuseViewListsEveryFolderAndFile(t *testing.T) {
	service := &fuseService{}
	service.publish(streamedState())
	view := service.view("library")
	names := func(directory string) []string {
		var list []string
		for _, child := range view.children[directory] {
			name := child.name
			if child.isDir {
				name += "/"
			}
			list = append(list, name)
		}
		return list
	}
	if got := names(""); !reflect.DeepEqual(got, []string{"Brand/", "Media/", "readme.md"}) {
		t.Fatalf("root: %v", got)
	}
	if got := names("Brand"); !reflect.DeepEqual(got, []string{"Fonts/", "Logo.svg"}) {
		t.Fatalf("Brand: %v", got)
	}
	if got := names("Brand/Fonts"); !reflect.DeepEqual(got, []string{"Sans.ttf"}) {
		t.Fatalf("Brand/Fonts: %v", got)
	}
	if file := view.files["Media/Clip.mp4"]; file.local || file.size != 40<<20 || file.sha != "dd" {
		t.Fatalf("placeholder: %+v", file)
	}
	if !view.files["readme.md"].local || view.cache("aa") != "/state/cache/aa" {
		t.Fatalf("local file: %+v", view.files["readme.md"])
	}
	if _, linked := view.files["link"]; linked {
		t.Fatal("a link node is not a file of the placeholder file system")
	}
	if string(view.spaceMD) != "# Library\n\n"+fuseStreamedNote || len(view.index) == 0 {
		t.Fatalf("SPACE.md %q", view.spaceMD)
	}
	if fuseInode("library", "readme.md", "aa") == fuseInode("library", "readme.md", "ab") {
		t.Fatal("a new version must be a new inode")
	}
}

// TestStreamedMountsKeepBlobsInTheCache: a streamed mount reads its local files from the cache, so those blobs stay
// referenced and count once (as cache, not as tree copies).
func TestStreamedMountsKeepBlobsInTheCache(t *testing.T) {
	state := streamedState()
	referenced := state.referencedBlobs()
	if !referenced["aa"] || !referenced["cc"] || referenced["bb"] || referenced["dd"] {
		t.Fatalf("referenced: %v", referenced)
	}
	if state.treeBytes() != 0 {
		t.Fatalf("tree bytes of a streamed mount: %d", state.treeBytes())
	}
	state.Streamed = false
	if referenced := state.referencedBlobs(); referenced["aa"] || state.treeBytes() != 37 {
		t.Fatalf("Plan B: referenced %v, tree %d", referenced, state.treeBytes())
	}
}

// TestStreamedHintUsesMissesForMounts: with streamed mounts the hint names exactly the files the call opened (the
// FUSE miss log); the command heuristic still covers the own drive, never the mounts.
func TestStreamedHintUsesMissesForMounts(t *testing.T) {
	state := streamedState()
	state.Own.Entries["data/big.bin"] = &DriveEntry{Path: "data/big.bin", SHA256: "ee", Size: 31 << 20}
	session := &Session{runner: &Runner{config: Config{Workspace: "/workspace"}}, drive: state}
	hint := session.callHint("cat drivespaces/library/Media/Clip.mp4 data/big.bin; ls drivespaces/library/Brand", "/workspace",
		[]string{"drivespaces/library/Media/Clip.mp4"})
	if hint == nil || hint.Source != "fuse" || hint.Count != 2 || hint.Paths[0].Path != "data/big.bin" ||
		hint.Paths[1].Path != "drivespaces/library/Media/Clip.mp4" {
		t.Fatalf("hint: %+v", hint)
	}
	// the heuristic alone would name Brand/Logo.svg (a word of the command); the miss log does not
	if hint := session.callHint("ls drivespaces/library/Brand", "/workspace", nil); hint != nil {
		t.Fatalf("no miss, no hint: %+v", hint)
	}
	state.Streamed = false
	if hint := session.callHint("ls drivespaces/library/Brand", "/workspace", nil); hint == nil || hint.Source != "command" {
		t.Fatalf("Plan B keeps the heuristic: %+v", hint)
	}
}
