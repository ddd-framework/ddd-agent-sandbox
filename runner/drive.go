package main

// Drive mode (README "Drive mode"): /workspace is the working copy of the agentSpace's own drive space, further
// spaces are read-only mounts below drivespaces/<name>/. The runner keeps the base map (node, version and hash per
// path), the pinned folders and the sync cursor per space in a root-only state directory outside /workspace, together
// with the hash cache (immutable blobs by sha256). Tree files are always independent copies of cache blobs: never a
// hardlink, a reflink only where the file system offers one.

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

// Drive layout names inside the workspace.
const (
	DriveSpacesDir  = "drivespaces"
	DriveIndexFile  = ".drive-index"
	DriveSpaceFile  = "SPACE.md"
	DriveIgnoreFile = ".driveignore"
	DriveMountsDir  = "mounts"
	// DriveConflictsDir holds the merge side files (conflict markers), below the runner folder: never synced
	DriveConflictsDir = RunnerDir + "/conflicts"
)

// DefaultDriveIgnore is the exclusion list when the claim names none (gitignore style: a leading slash anchors).
var DefaultDriveIgnore = []string{".venv", "node_modules", "__pycache__", ".cache", ".git", "/tmp"}

// Mount modes . The shell never writes below drivespaces/ in any mode.
const (
	MountReadOnly         = "READ_ONLY"
	MountReadWrite        = "READ_WRITE"
	MountWriteWithConsent = "WRITE_WITH_CONSENT"
)

// Change kinds of the status.
const (
	ChangeAdded    = "added"
	ChangeModified = "modified"
	ChangeDeleted  = "deleted"
	ChangeRenamed  = "renamed"
)

// Symlink classes.
const (
	SymlinkLink        = "link"        // relative or absolute, resolves inside the own drive: a link node
	SymlinkPlaceholder = "placeholder" // held by the base map
	SymlinkRefused     = "refused"     // unknown and pointing outside the own drive: refused at commit
)

// DriveChange is one entry of a claim's base map or of a pull: create, update, delete or move one path.
type DriveChange struct {
	Op        string `json:"op,omitempty"`
	Path      string `json:"path"`
	FromPath  string `json:"fromPath,omitempty"`
	NodeID    int64  `json:"nodeId,omitempty"`
	VersionID int64  `json:"versionId,omitempty"`
	// VersionNumber: the version's number within its node (v1, v2, …), for messages only
	VersionNumber int64  `json:"versionNumber,omitempty"`
	SHA256        string `json:"sha256,omitempty"`
	Size          int64  `json:"size,omitempty"`
	Mode          string `json:"mode,omitempty"`
	ContentType   string `json:"contentType,omitempty"`
	// URL: a presigned GET (proxy transfer); without it the blob must be in the cache (or is reported in needBlobs)
	URL string `json:"url,omitempty"`
	// LinkTarget: the node is an in-space link, materialised as a relative symlink
	LinkTarget string `json:"linkTarget,omitempty"`
}

// DriveConflict records an incoming change that met a local change; nothing was overwritten.
type DriveConflict struct {
	Kind            string `json:"kind"` // both-changed | trashed
	TheirsNodeID    int64  `json:"theirsNodeId,omitempty"`
	TheirsVersionID int64  `json:"theirsVersionId,omitempty"`
	TheirsSHA256    string `json:"theirsSha256,omitempty"`
	TheirsSize      int64  `json:"theirsSize,omitempty"`
	// SideFile: conflict markers of a failed text merge, workspace-relative below .runner/conflicts/
	SideFile string `json:"sideFile,omitempty"`
	Binary   bool   `json:"binary,omitempty"`
}

// DriveEntry is the base of one path: the drive's node and version it was materialised from.
type DriveEntry struct {
	Path      string `json:"path"`
	NodeID    int64  `json:"nodeId"`
	VersionID int64  `json:"versionId"`
	SHA256    string `json:"sha256"`
	Size      int64  `json:"size"`
	// Local: the file exists in the tree; false: listed in the index only (placeholder)
	Local       bool   `json:"local"`
	Mode        string `json:"mode,omitempty"`
	ContentType string `json:"contentType,omitempty"`
	LinkTarget  string `json:"linkTarget,omitempty"`
	// tree stat at materialisation or at the last scan: a quick status compares these before hashing
	MtimeNs int64  `json:"mtimeNs,omitempty"`
	Inode   uint64 `json:"inode,omitempty"`
	// LastUsedNs: materialised, got or seen changed; the mirror budget drops the least recently used first
	LastUsedNs int64          `json:"lastUsedNs,omitempty"`
	Conflict   *DriveConflict `json:"conflict,omitempty"`
	// Pending: the content (sha256) that should be local but whose blob has not arrived yet (stream transfer)
	Pending     string `json:"pending,omitempty"`
	PendingMode string `json:"pendingMode,omitempty"`
}

// DriveSpaceState is the own drive (Name "") or one mount.
type DriveSpaceState struct {
	Name    string                 `json:"name"`
	SpaceID int64                  `json:"spaceId"`
	Mode    string                 `json:"mode,omitempty"`
	Cursor  int64                  `json:"cursor"`
	Pinned  []string               `json:"pinned"`
	SpaceMD string                 `json:"spaceMd,omitempty"`
	Entries map[string]*DriveEntry `json:"entries"`
}

// DriveState is everything the runner keeps for a drive-mode session.
type DriveState struct {
	mutex sync.Mutex
	// stateDir is root-only (0700): state.json and cache/<sha256>
	stateDir          string
	workspace         string
	ThresholdBytes    int64                       `json:"thresholdBytes"`
	MirrorBudgetBytes int64                       `json:"mirrorBudgetBytes"`
	Ignore            []string                    `json:"ignore"`
	Transfer          string                      `json:"transfer"`
	Own               *DriveSpaceState            `json:"own"`
	Mounts            map[string]*DriveSpaceState `json:"mounts"`
	// Staged: sha256 → paths of snapshots taken for the last save, kept in the cache until committed
	Staged map[string][]string `json:"staged"`
	// Streamed: the mounts are served by the placeholder FUSE from the cache (fuse.go); false: Plan B copies in the
	// tree. MountFilesystem says which, with the reason when the FUSE could not be mounted.
	Streamed        bool   `json:"streamed,omitempty"`
	MountFilesystem string `json:"mountFilesystem,omitempty"`
	rules           []ignoreRule
	// scanCache: the stat and hash of every file at the last scan (no re-hash of unchanged files)
	scanCache    map[string]knownStat
	mountTextMax int64
}

func newSpaceState(name string, spaceID int64, mode string) *DriveSpaceState {
	return &DriveSpaceState{Name: name, SpaceID: spaceID, Mode: mode, Pinned: []string{}, Entries: map[string]*DriveEntry{}}
}

// cachePath answers the cache file of a blob.
func (state *DriveState) cachePath(sha string) string {
	return filepath.Join(state.stateDir, "cache", sha)
}

func (state *DriveState) hasBlob(sha string) bool {
	if !validSHA256(sha) {
		return false
	}
	info, err := os.Lstat(state.cachePath(sha))
	return err == nil && info.Mode().IsRegular()
}

func validSHA256(value string) bool {
	if len(value) != 64 {
		return false
	}
	for _, character := range value {
		if !(character >= '0' && character <= '9' || character >= 'a' && character <= 'f') {
			return false
		}
	}
	return true
}

// ValidMountName: the folder name below drivespaces/.
func ValidMountName(name string) bool {
	if name == "" || len(name) > 64 || name[0] == '.' || name[0] == '-' {
		return false
	}
	for _, character := range name {
		if !(character >= 'a' && character <= 'z' || character >= 'A' && character <= 'Z' ||
			character >= '0' && character <= '9' || character == '-' || character == '_' || character == '.') {
			return false
		}
	}
	return true
}

// clearStateDir removes everything inside the state directory and keeps the directory itself: on the cluster it is
// a mount point (a subPath of the session volume), which cannot be removed, and a RemoveAll of the root may stop at
// that refusal before the children are gone. Answers how many entries were removed. A missing directory is empty.
func clearStateDir(directory string) (int, error) {
	entries, err := os.ReadDir(directory)
	if errors.Is(err, fs.ErrNotExist) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	var errs []error
	for _, entry := range entries {
		if err := os.RemoveAll(filepath.Join(directory, entry.Name())); err != nil {
			errs = append(errs, err)
		}
	}
	if err := errors.Join(errs...); err != nil {
		return len(entries), fmt.Errorf("drive state not cleared: %w", err)
	}
	if left, err := os.ReadDir(directory); err != nil || len(left) > 0 {
		return len(entries), fmt.Errorf("drive state not cleared")
	}
	return len(entries), nil
}

// save writes state.json atomically (root-only).
func (state *DriveState) save() error {
	encoded, err := json.Marshal(state)
	if err != nil {
		return err
	}
	temporary := filepath.Join(state.stateDir, ".state-"+randomHex(6))
	if err := os.WriteFile(temporary, encoded, 0o600); err != nil {
		return err
	}
	return os.Rename(temporary, filepath.Join(state.stateDir, "state.json"))
}

// ---------------------------------------------------------------------------------------------------------------------
// Ignore rules (a small gitignore subset: names at any depth, a leading slash anchors at the root, a slash inside
// anchors too, globs as path.Match, a trailing slash is accepted; no negation)

type ignoreRule struct {
	pattern  string
	anchored bool
}

func parseIgnoreRules(lines []string) []ignoreRule {
	var rules []ignoreRule
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, "!") {
			continue
		}
		line = strings.TrimSuffix(line, "/")
		anchored := strings.HasPrefix(line, "/") || strings.Contains(line, "/")
		line = strings.TrimPrefix(line, "/")
		if line == "" {
			continue
		}
		rules = append(rules, ignoreRule{pattern: line, anchored: anchored})
	}
	return rules
}

// alwaysExcluded are root entries that are never part of the own drive.
var alwaysExcluded = map[string]bool{RunnerDir: true, DriveSpacesDir: true, DriveMountsDir: true, DriveIndexFile: true,
	lostAndFound: true}

// driveExcluded answers whether a workspace-relative path is outside the sync (checked per walked level).
func driveExcluded(rules []ignoreRule, relative string) bool {
	first, _, _ := strings.Cut(relative, "/")
	if alwaysExcluded[first] {
		return true
	}
	segments := strings.Split(relative, "/")
	for _, rule := range rules {
		if rule.anchored {
			if matched, _ := path.Match(rule.pattern, relative); matched {
				return true
			}
			// an anchored folder rule also excludes what lies below it
			for index := 1; index < len(segments); index++ {
				if matched, _ := path.Match(rule.pattern, strings.Join(segments[:index], "/")); matched {
					return true
				}
			}
			continue
		}
		for _, segment := range segments {
			if matched, _ := path.Match(rule.pattern, segment); matched {
				return true
			}
		}
	}
	return false
}

// ---------------------------------------------------------------------------------------------------------------------
// Symlinks

// classifySymlink sorts one symlink of the tree: held by the base map as a placeholder or link node, a link that
// resolves inside the own drive (a link node at commit), or refused (outside the own drive, into a mount or an
// excluded area). linkPath and the answer's target are workspace-relative.
func classifySymlink(rules []ignoreRule, workspace, linkPath, target string, base *DriveEntry) (string, string, string) {
	if base != nil && base.LinkTarget != "" && base.LinkTarget == target {
		return SymlinkLink, target, ""
	}
	if base != nil && !base.Local && base.LinkTarget == "" && base.Path == linkPath {
		return SymlinkPlaceholder, target, ""
	}
	resolved := target
	if strings.HasPrefix(target, "/") {
		prefix := strings.TrimSuffix(workspace, "/") + "/"
		if !strings.HasPrefix(target, prefix) {
			return SymlinkRefused, target, "points outside /workspace"
		}
		resolved = strings.TrimPrefix(target, prefix)
	} else {
		resolved = path.Join(path.Dir(linkPath), target)
	}
	resolved = path.Clean(resolved)
	if resolved == ".." || strings.HasPrefix(resolved, "../") || resolved == "." {
		return SymlinkRefused, target, "points outside /workspace"
	}
	if driveExcluded(rules, resolved) {
		return SymlinkRefused, target, "points into an area that is not part of the own drive"
	}
	return SymlinkLink, resolved, ""
}

// ---------------------------------------------------------------------------------------------------------------------
// Status

// ScannedFile is one tree entry as the scan helper saw it.
type ScannedFile struct {
	Path    string `json:"path"`
	Size    int64  `json:"size"`
	MtimeNs int64  `json:"mtimeNs"`
	Inode   uint64 `json:"inode"`
	Mode    string `json:"mode"`
	SHA256  string `json:"sha256,omitempty"`
	// Target: set for symlinks (the link text)
	Target string `json:"target,omitempty"`
}

// TreeScan is the helper's answer.
type TreeScan struct {
	Files      []ScannedFile `json:"files"`
	Symlinks   []ScannedFile `json:"symlinks"`
	Unreadable []string      `json:"unreadable"`
}

// StatusChange is one uncommitted change of the own drive.
type StatusChange struct {
	Path          string `json:"path"`
	Kind          string `json:"kind"`
	From          string `json:"from,omitempty"`
	Type          string `json:"type"` // file | link
	Size          int64  `json:"size,omitempty"`
	SHA256        string `json:"sha256,omitempty"`
	Mode          string `json:"mode,omitempty"`
	LinkTarget    string `json:"linkTarget,omitempty"`
	LinkResolved  string `json:"linkResolved,omitempty"`
	NodeID        int64  `json:"nodeId,omitempty"`
	BaseVersionID int64  `json:"baseVersionId,omitempty"`
	// WasNotLocal: the path was a placeholder and the session wrote a file there
	WasNotLocal bool `json:"wasNotLocal,omitempty"`
}

// RefusedSymlink is a symlink a commit refuses.
type RefusedSymlink struct {
	Path   string `json:"path"`
	Target string `json:"target"`
	Reason string `json:"reason"`
}

// DriveStatus is the answer of GET …/drive/status and the base of a stage.
type DriveStatus struct {
	Mode            string           `json:"mode"`
	SpaceID         int64            `json:"spaceId"`
	Cursor          int64            `json:"cursor"`
	Changes         []StatusChange   `json:"changes"`
	Conflicts       []ConflictView   `json:"conflicts"`
	RefusedSymlinks []RefusedSymlink `json:"refusedSymlinks"`
	Unreadable      []string         `json:"unreadable,omitempty"`
	NotLocalFiles   int              `json:"notLocalFiles"`
	NotLocalBytes   int64            `json:"notLocalBytes"`
	Mounts          []MountView      `json:"mounts"`
	ThresholdBytes  int64            `json:"thresholdBytes"`
	Budget          BudgetView       `json:"budget"`
	Transfer        string           `json:"transfer"`
	Pinned          []string         `json:"pinned"`
	// MountFilesystem: "fuse" (placeholders, streamed), "plain" (Plan B) or "plain: <why the FUSE failed>"
	MountFilesystem string `json:"mountFilesystem,omitempty"`
}

// ConflictView names a conflicted path.
type ConflictView struct {
	Path string `json:"path"`
	DriveConflict
	BaseVersionID int64 `json:"baseVersionId"`
}

// MountView is the sync state of one mount.
type MountView struct {
	Name          string   `json:"name"`
	SpaceID       int64    `json:"spaceId"`
	Mode          string   `json:"mode"`
	Cursor        int64    `json:"cursor"`
	Pinned        []string `json:"pinned"`
	Files         int      `json:"files"`
	LocalFiles    int      `json:"localFiles"`
	NotLocalFiles int      `json:"notLocalFiles"`
	NotLocalBytes int64    `json:"notLocalBytes"`
	// Streamed: every file is listed in the mount (placeholder FUSE); false: only local files exist there (Plan B)
	Streamed bool `json:"streamed"`
}

// BudgetView: what the local mirror holds (tree copies plus cache blobs) against its budget.
type BudgetView struct {
	BudgetBytes int64 `json:"budgetBytes"`
	TreeBytes   int64 `json:"treeBytes"`
	CacheBytes  int64 `json:"cacheBytes"`
}

// computeStatus compares a tree scan with the base map of the own drive (the caller holds the state mutex). Renames
// are recognised by inode, else by equal hash.
func (state *DriveState) computeStatus(scan TreeScan) DriveStatus {
	own := state.Own
	status := DriveStatus{Mode: "drive", SpaceID: own.SpaceID, Cursor: own.Cursor, Changes: []StatusChange{},
		Conflicts: []ConflictView{}, RefusedSymlinks: []RefusedSymlink{}, Unreadable: scan.Unreadable,
		Mounts: []MountView{}, ThresholdBytes: state.ThresholdBytes, Transfer: state.Transfer,
		Pinned: append([]string{}, own.Pinned...)}
	present := map[string]ScannedFile{}
	for _, file := range scan.Files {
		present[file.Path] = file
	}
	links := map[string]ScannedFile{}
	for _, link := range scan.Symlinks {
		links[link.Path] = link
	}
	var added []StatusChange
	deleted := map[string]*DriveEntry{}
	for _, file := range scan.Files {
		entry := own.Entries[file.Path]
		switch {
		case entry == nil:
			added = append(added, StatusChange{Path: file.Path, Kind: ChangeAdded, Type: "file", Size: file.Size,
				SHA256: file.SHA256, Mode: file.Mode})
		case entry.LinkTarget != "":
			// a link node replaced by a file
			status.Changes = append(status.Changes, StatusChange{Path: file.Path, Kind: ChangeModified, Type: "file",
				Size: file.Size, SHA256: file.SHA256, Mode: file.Mode, NodeID: entry.NodeID, BaseVersionID: entry.VersionID})
		case !entry.Local:
			status.Changes = append(status.Changes, StatusChange{Path: file.Path, Kind: ChangeModified, Type: "file",
				Size: file.Size, SHA256: file.SHA256, Mode: file.Mode, NodeID: entry.NodeID, BaseVersionID: entry.VersionID,
				WasNotLocal: true})
		case file.SHA256 != entry.SHA256:
			status.Changes = append(status.Changes, StatusChange{Path: file.Path, Kind: ChangeModified, Type: "file",
				Size: file.Size, SHA256: file.SHA256, Mode: file.Mode, NodeID: entry.NodeID, BaseVersionID: entry.VersionID})
		}
	}
	for _, link := range scan.Symlinks {
		entry := own.Entries[link.Path]
		kind, target, reason := classifySymlink(state.rules, state.workspace, link.Path, link.Target, entry)
		switch kind {
		case SymlinkRefused:
			status.RefusedSymlinks = append(status.RefusedSymlinks, RefusedSymlink{Path: link.Path, Target: link.Target, Reason: reason})
		case SymlinkLink:
			if entry != nil && entry.LinkTarget == link.Target {
				continue
			}
			change := StatusChange{Path: link.Path, Kind: ChangeAdded, Type: "link", LinkTarget: link.Target}
			// LinkResolved: the workspace path the link points to (the target node of a link node)
			if entry != nil {
				change.Kind, change.NodeID, change.BaseVersionID = ChangeModified, entry.NodeID, entry.VersionID
			}
			change.LinkResolved = target
			status.Changes = append(status.Changes, change)
		}
	}
	paths := make([]string, 0, len(own.Entries))
	for entryPath := range own.Entries {
		paths = append(paths, entryPath)
	}
	sort.Strings(paths)
	for _, entryPath := range paths {
		entry := own.Entries[entryPath]
		if entry.Conflict != nil {
			status.Conflicts = append(status.Conflicts, ConflictView{Path: entryPath, DriveConflict: *entry.Conflict,
				BaseVersionID: entry.VersionID})
		}
		_, isFile := present[entryPath]
		_, isLink := links[entryPath]
		if !entry.Local && entry.LinkTarget == "" {
			if !isFile {
				status.NotLocalFiles++
				status.NotLocalBytes += entry.Size
			}
			continue
		}
		if !isFile && !isLink {
			deleted[entryPath] = entry
		}
	}
	// renames: an added file with the inode of a deleted one, else with its hash
	byInode := map[uint64]string{}
	byHash := map[string]string{}
	for deletedPath, entry := range deleted {
		if entry.Inode != 0 {
			byInode[entry.Inode] = deletedPath
		}
		if _, taken := byHash[entry.SHA256]; !taken || deletedPath < byHash[entry.SHA256] {
			byHash[entry.SHA256] = deletedPath
		}
	}
	for _, change := range added {
		from, found := byInode[present[change.Path].Inode]
		if !found || deleted[from] == nil {
			from, found = byHash[change.SHA256]
		}
		if found && deleted[from] != nil {
			entry := deleted[from]
			delete(deleted, from)
			change.Kind, change.From, change.NodeID, change.BaseVersionID = ChangeRenamed, from, entry.NodeID, entry.VersionID
		}
		status.Changes = append(status.Changes, change)
	}
	for deletedPath, entry := range deleted {
		status.Changes = append(status.Changes, StatusChange{Path: deletedPath, Kind: ChangeDeleted, Type: "file",
			NodeID: entry.NodeID, BaseVersionID: entry.VersionID, SHA256: entry.SHA256, Size: entry.Size})
	}
	sort.Slice(status.Changes, func(left, right int) bool { return status.Changes[left].Path < status.Changes[right].Path })
	for _, name := range state.mountNames() {
		mount := state.Mounts[name]
		view := MountView{Name: name, SpaceID: mount.SpaceID, Mode: mount.Mode, Cursor: mount.Cursor,
			Pinned: append([]string{}, mount.Pinned...), Streamed: state.Streamed}
		for _, entry := range mount.Entries {
			view.Files++
			if entry.Local {
				view.LocalFiles++
			} else {
				view.NotLocalFiles++
				view.NotLocalBytes += entry.Size
			}
		}
		status.Mounts = append(status.Mounts, view)
	}
	status.Budget = state.budgetView()
	status.MountFilesystem = state.MountFilesystem
	return status
}

func (state *DriveState) mountNames() []string {
	names := make([]string, 0, len(state.Mounts))
	for name := range state.Mounts {
		names = append(names, name)
	}
	sort.Strings(names)
	return names
}

// ---------------------------------------------------------------------------------------------------------------------
// Mirror budget

// referencedBlobs: the cache blobs that must stay: bases of local own files (merge and diff bases), staged
// snapshots, and the local files of streamed mounts. Plan B drops mount blobs after copying them into the tree.
func (state *DriveState) referencedBlobs() map[string]bool {
	referenced := map[string]bool{}
	for _, entry := range state.Own.Entries {
		if entry.Local && entry.SHA256 != "" {
			referenced[entry.SHA256] = true
		}
		if entry.Conflict != nil && entry.Conflict.TheirsSHA256 != "" {
			referenced[entry.Conflict.TheirsSHA256] = true
		}
	}
	for sha := range state.Staged {
		referenced[sha] = true
	}
	// streamed mounts read their local files straight from the cache
	if state.Streamed {
		for _, mount := range state.Mounts {
			for _, entry := range mount.Entries {
				if entry.Local && entry.SHA256 != "" {
					referenced[entry.SHA256] = true
				}
			}
		}
	}
	return referenced
}

func (state *DriveState) cacheBytes() int64 {
	entries, err := os.ReadDir(filepath.Join(state.stateDir, "cache"))
	if err != nil {
		return 0
	}
	var total int64
	for _, entry := range entries {
		if info, err := entry.Info(); err == nil && info.Mode().IsRegular() {
			total += info.Size()
		}
	}
	return total
}

func (state *DriveState) treeBytes() int64 {
	var total int64
	for _, entry := range state.Own.Entries {
		if entry.Local {
			total += entry.Size
		}
	}
	// local files of streamed mounts are cache blobs (counted there), not tree copies
	for _, mount := range state.Mounts {
		for _, entry := range mount.Entries {
			if entry.Local && !state.Streamed {
				total += entry.Size
			}
		}
	}
	return total
}

func (state *DriveState) budgetView() BudgetView {
	return BudgetView{BudgetBytes: state.MirrorBudgetBytes, TreeBytes: state.treeBytes(), CacheBytes: state.cacheBytes()}
}

// collectGarbage removes cache blobs nobody references any more.
func (state *DriveState) collectGarbage() {
	referenced := state.referencedBlobs()
	entries, err := os.ReadDir(filepath.Join(state.stateDir, "cache"))
	if err != nil {
		return
	}
	for _, entry := range entries {
		if strings.HasPrefix(entry.Name(), ".") {
			if info, err := entry.Info(); err == nil && time.Since(info.ModTime()) > time.Hour {
				os.Remove(filepath.Join(state.stateDir, "cache", entry.Name()))
			}
			continue
		}
		if !referenced[entry.Name()] {
			os.Remove(filepath.Join(state.stateDir, "cache", entry.Name()))
		}
	}
}

// evictionCandidate is a local file the budget may turn into an index entry.
type evictionCandidate struct {
	mount string // "" for the own drive
	entry *DriveEntry
}

// evictionOrder answers the candidates in eviction order: unpinned mount files first (oldest use first), then own
// files outside lib/ and NOTES.md and outside pinned folders (oldest use first). Locally changed own files are
// skipped by the caller (it checks the hash before removing).
func (state *DriveState) evictionOrder() []evictionCandidate {
	var mounts, own []evictionCandidate
	for _, name := range state.mountNames() {
		mount := state.Mounts[name]
		for _, entry := range mount.Entries {
			if entry.Local && !underPinned(mount.Pinned, entry.Path) {
				mounts = append(mounts, evictionCandidate{mount: name, entry: entry})
			}
		}
	}
	for _, entry := range state.Own.Entries {
		if !entry.Local || entry.LinkTarget != "" || entry.Conflict != nil || isMirrorKept(entry.Path) ||
			underPinned(state.Own.Pinned, entry.Path) {
			continue
		}
		own = append(own, evictionCandidate{entry: entry})
	}
	byUse := func(list []evictionCandidate) {
		sort.SliceStable(list, func(left, right int) bool {
			if list[left].entry.LastUsedNs != list[right].entry.LastUsedNs {
				return list[left].entry.LastUsedNs < list[right].entry.LastUsedNs
			}
			return list[left].entry.Path < list[right].entry.Path
		})
	}
	byUse(mounts)
	byUse(own)
	return append(mounts, own...)
}

// isMirrorKept: lib/ and NOTES.md stay local whatever the budget says.
func isMirrorKept(relative string) bool {
	return relative == "NOTES.md" || relative == "lib" || strings.HasPrefix(relative, "lib/")
}

func underPinned(pinned []string, relative string) bool {
	for _, folder := range pinned {
		if folder == "" || folder == "." || relative == folder || strings.HasPrefix(relative, folder+"/") {
			return true
		}
	}
	return false
}

// ---------------------------------------------------------------------------------------------------------------------
// Index files

// indexLines answers the TSV of the not-local entries of one space, sorted by path: path, size, node id, version
// id, sha256. The index is the one list of not-local files: the hints read it, and a placeholder file system can
// later serve exactly these rows without a protocol change.
func indexLines(space *DriveSpaceState) []byte {
	var lines []string
	for _, entry := range space.Entries {
		if !entry.Local && entry.LinkTarget == "" {
			lines = append(lines, fmt.Sprintf("%s\t%d\t%d\t%d\t%s", tsvField(entry.Path), entry.Size, entry.NodeID,
				entry.VersionID, contentOf(entry)))
		}
	}
	sort.Strings(lines)
	header := "# not local (fetch with: drive get <path>)\n# path\tsize\tnodeId\tversionId\tsha256\n"
	if len(lines) == 0 {
		return []byte(header)
	}
	return []byte(header + strings.Join(lines, "\n") + "\n")
}

func tsvField(value string) string {
	return strings.NewReplacer("\t", " ", "\n", " ", "\r", " ").Replace(value)
}

// ---------------------------------------------------------------------------------------------------------------------
// Errors

var (
	// ErrDriveOff: the session was claimed without drive mode
	ErrDriveOff = errors.New("drive mode is off for this session")
)
