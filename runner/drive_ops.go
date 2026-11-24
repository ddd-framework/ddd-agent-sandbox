package main

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"time"
)

// DriveClaim is the drive block of POST /v1/sessions with mode "drive".
type DriveClaim struct {
	SpaceID           int64             `json:"spaceId"`
	Cursor            int64             `json:"cursor"`
	ThresholdBytes    int64             `json:"thresholdBytes,omitempty"`
	MirrorBudgetBytes int64             `json:"mirrorBudgetBytes,omitempty"`
	Ignore            []string          `json:"ignore,omitempty"`
	Transfer          string            `json:"transfer,omitempty"`
	Pinned            []string          `json:"pinned,omitempty"`
	Entries           []ClaimEntry      `json:"entries,omitempty"`
	Mounts            []DriveMountClaim `json:"mounts,omitempty"`
}

// ClaimEntry is one base-map entry handed over at claim; Working carries a saved, uncommitted state of the path.
type ClaimEntry struct {
	DriveChange
	Working *WorkingContent `json:"working,omitempty"`
}

// WorkingContent: the saved local content of a path (a staged blob), or its deletion.
type WorkingContent struct {
	SHA256  string `json:"sha256,omitempty"`
	Size    int64  `json:"size,omitempty"`
	Mode    string `json:"mode,omitempty"`
	URL     string `json:"url,omitempty"`
	Deleted bool   `json:"deleted,omitempty"`
}

// DriveMountClaim is one mounted space.
type DriveMountClaim struct {
	Name    string        `json:"name"`
	SpaceID int64         `json:"spaceId"`
	Mode    string        `json:"mode"`
	SpaceMD string        `json:"spaceMd"`
	Cursor  int64         `json:"cursor"`
	Pinned  []string      `json:"pinned,omitempty"`
	Entries []DriveChange `json:"entries,omitempty"`
}

// PullRequest is the body of POST …/drive/pull: the changes of one space since its cursor.
type PullRequest struct {
	// Target: "" (or "own") for the own drive, else the mount name
	Target  string        `json:"target,omitempty"`
	Cursor  int64         `json:"cursor"`
	Changes []DriveChange `json:"changes"`
	// Pinned replaces the pinned folders of the space when given
	Pinned []string `json:"pinned,omitempty"`
}

// NeedBlob names a blob the runner lacks: push it with PUT …/drive/blobs/{sha256} (stream transfer), then repeat.
type NeedBlob struct {
	SHA256 string   `json:"sha256"`
	Size   int64    `json:"size"`
	Paths  []string `json:"paths"`
}

// PathFailure is a path the runner could not apply.
type PathFailure struct {
	Path   string `json:"path"`
	Reason string `json:"reason"`
}

// PullResult answers a pull, a claim and a materialisation.
type PullResult struct {
	Target    string         `json:"target"`
	Cursor    int64          `json:"cursor"`
	Applied   []string       `json:"applied"`
	Merged    []string       `json:"merged"`
	Conflicts []ConflictView `json:"conflicts"`
	NeedBlobs []NeedBlob     `json:"needBlobs"`
	Failed    []PathFailure  `json:"failed"`
	Evicted   []string       `json:"evicted"`
	Skipped   int            `json:"skipped"`
}

func newPullResult(target string) *PullResult {
	return &PullResult{Target: target, Applied: []string{}, Merged: []string{}, Conflicts: []ConflictView{},
		NeedBlobs: []NeedBlob{}, Failed: []PathFailure{}, Evicted: []string{}}
}

func (result *PullResult) needBlob(sha string, size int64, workspacePath string) {
	for index := range result.NeedBlobs {
		if result.NeedBlobs[index].SHA256 == sha {
			result.NeedBlobs[index].Paths = append(result.NeedBlobs[index].Paths, workspacePath)
			return
		}
	}
	result.NeedBlobs = append(result.NeedBlobs, NeedBlob{SHA256: sha, Size: size, Paths: []string{workspacePath}})
}

func (result *PullResult) merge(other *PullResult) {
	result.Applied = append(result.Applied, other.Applied...)
	result.Merged = append(result.Merged, other.Merged...)
	result.Conflicts = append(result.Conflicts, other.Conflicts...)
	for _, need := range other.NeedBlobs {
		for _, workspacePath := range need.Paths {
			result.needBlob(need.SHA256, need.Size, workspacePath)
		}
	}
	result.Failed = append(result.Failed, other.Failed...)
	result.Evicted = append(result.Evicted, other.Evicted...)
	result.Skipped += other.Skipped
}

// ---------------------------------------------------------------------------------------------------------------------
// Claim

// initDrive sets up drive mode for a fresh session: the root-only state, the layout, the base map with its
// materialisation, the mounts with SPACE.md and index, the mirror budget, the CLI socket.
func (session *Session) initDrive(claim DriveClaim) (*PullResult, error) {
	config := session.runner.config
	if claim.SpaceID <= 0 {
		return nil, fmt.Errorf("drive.spaceId is required")
	}
	state := &DriveState{stateDir: config.DriveStateDir, workspace: config.Workspace,
		ThresholdBytes: config.DriveThresholdBytes, MirrorBudgetBytes: config.DriveMirrorBudgetBytes,
		Ignore: DefaultDriveIgnore, Own: newSpaceState("", claim.SpaceID, MountReadWrite),
		Mounts: map[string]*DriveSpaceState{}, Staged: map[string][]string{}, mountTextMax: config.DriveMountTextMaxBytes}
	if claim.ThresholdBytes > 0 {
		state.ThresholdBytes = claim.ThresholdBytes
	}
	if claim.MirrorBudgetBytes > 0 {
		state.MirrorBudgetBytes = claim.MirrorBudgetBytes
	}
	if claim.Ignore != nil {
		state.Ignore = claim.Ignore
	}
	state.rules = parseIgnoreRules(state.Ignore)
	state.Own.Cursor = claim.Cursor
	state.Own.Pinned = cleanFolders(claim.Pinned)
	switch claim.Transfer {
	case "", "proxy":
		state.Transfer = "stream"
		if session.runner.transfer != nil {
			state.Transfer = "proxy"
		}
	case "stream":
		state.Transfer = "stream"
	default:
		return nil, fmt.Errorf("drive.transfer must be proxy or stream")
	}
	// a claim starts from an empty state directory, whatever an earlier session or process left
	if err := os.MkdirAll(config.DriveStateDir, 0o700); err != nil {
		return nil, fmt.Errorf("drive state: %w", err)
	}
	os.Chmod(config.DriveStateDir, 0o700)
	leftovers, err := clearStateDir(config.DriveStateDir)
	if err != nil {
		return nil, err
	}
	if leftovers > 0 {
		session.runner.logger.Warn("drive state leftovers removed at claim", "session", session.ID, "entries", leftovers)
	}
	if err := os.Mkdir(filepath.Join(config.DriveStateDir, "cache"), 0o700); err != nil {
		return nil, fmt.Errorf("drive state: %w", err)
	}
	// sticky: the session cannot rename or replace the runner's entries (drivespaces/, .drive-index) at the root
	os.Chmod(config.Workspace, 0o777|os.ModeSticky)
	if err := session.rootMkdir(DriveSpacesDir); err != nil {
		return nil, err
	}
	session.drive = state
	state.mutex.Lock()
	defer state.mutex.Unlock()
	result := newPullResult("own")
	// mounts first: their names are fixed before any file lands
	for _, mountClaim := range claim.Mounts {
		if !ValidMountName(mountClaim.Name) || state.Mounts[mountClaim.Name] != nil {
			return nil, fmt.Errorf("drive.mounts: invalid or duplicate name")
		}
		mode := mountClaim.Mode
		if mode == "" {
			mode = MountReadOnly
		}
		if mode != MountReadOnly && mode != MountReadWrite && mode != MountWriteWithConsent {
			return nil, fmt.Errorf("drive.mounts: invalid mode")
		}
		mount := newSpaceState(mountClaim.Name, mountClaim.SpaceID, mode)
		mount.Cursor, mount.Pinned, mount.SpaceMD = mountClaim.Cursor, cleanFolders(mountClaim.Pinned), mountClaim.SpaceMD
		state.Mounts[mountClaim.Name] = mount
		if err := session.rootMkdir(path.Join(DriveSpacesDir, mountClaim.Name)); err != nil {
			return nil, err
		}
	}
	session.mountFilesystem(state)
	if !state.Streamed {
		for _, mountClaim := range claim.Mounts {
			if err := session.writeRootFile(path.Join(DriveSpacesDir, mountClaim.Name, DriveSpaceFile), []byte(mountClaim.SpaceMD)); err != nil {
				return nil, err
			}
		}
	}
	session.prefetch(state, claimDownloads(state, claim), result)
	for _, entry := range claim.Entries {
		session.claimOwnEntry(state, entry, result)
	}
	for _, mountClaim := range claim.Mounts {
		mount := state.Mounts[mountClaim.Name]
		mountResult := newPullResult(mount.Name)
		for _, change := range mountClaim.Entries {
			change.Op = "create"
			session.applyMountChange(state, mount, change, mountResult)
		}
		result.merge(mountResult)
	}
	session.finishSync(state, result)
	return result, nil
}

func cleanFolders(folders []string) []string {
	cleaned := []string{}
	for _, folder := range folders {
		if folder == "" || folder == "." || folder == "/" {
			cleaned = append(cleaned, "")
			continue
		}
		if clean, err := CleanRelativePath(strings.TrimSuffix(folder, "/")); err == nil {
			cleaned = append(cleaned, clean)
		}
	}
	sort.Strings(cleaned)
	return cleaned
}

// claimDownloads lists the blobs a claim will materialise and that come with a URL.
func claimDownloads(state *DriveState, claim DriveClaim) []DriveChange {
	var downloads []DriveChange
	for _, entry := range claim.Entries {
		if entry.Working != nil && entry.Working.URL != "" && !entry.Working.Deleted {
			downloads = append(downloads, DriveChange{Path: entry.Path, SHA256: entry.Working.SHA256,
				Size: entry.Working.Size, URL: entry.Working.URL})
		} else if entry.Working == nil && entry.URL != "" && state.ownShouldBeLocal(entry.Path, entry.Size) {
			downloads = append(downloads, entry.DriveChange)
		}
	}
	for _, mountClaim := range claim.Mounts {
		mount := state.Mounts[mountClaim.Name]
		for _, change := range mountClaim.Entries {
			if change.URL != "" && mountShouldBeLocal(state, mount, change) {
				downloads = append(downloads, change)
			}
		}
	}
	return downloads
}

// claimOwnEntry records one base entry and materialises its content: the working content when the claim carries a
// saved uncommitted state, else the version itself (below the threshold or pinned).
func (session *Session) claimOwnEntry(state *DriveState, claimEntry ClaimEntry, result *PullResult) {
	relative, err := CleanRelativePath(claimEntry.Path)
	if err != nil || driveExcluded(state.rules, relative) {
		result.Failed = append(result.Failed, PathFailure{Path: claimEntry.Path, Reason: "not a path of the own drive"})
		return
	}
	entry := entryFromChange(claimEntry.DriveChange, relative)
	state.Own.Entries[relative] = entry
	if claimEntry.LinkTarget != "" {
		if _, err := session.linkTree(relative, claimEntry.LinkTarget, "absent"); err != nil {
			result.Failed = append(result.Failed, PathFailure{Path: relative, Reason: "the link could not be created"})
			return
		}
		entry.Local = true
		result.Applied = append(result.Applied, relative)
		return
	}
	content, size, mode := claimEntry.SHA256, claimEntry.Size, claimEntry.Mode
	if working := claimEntry.Working; working != nil {
		entry.Local = true
		if working.Deleted {
			result.Applied = append(result.Applied, relative)
			return
		}
		content, size = working.SHA256, working.Size
		if working.Mode != "" {
			mode = working.Mode
		}
	} else if !state.ownShouldBeLocal(relative, claimEntry.Size) {
		entry.Local = false
		result.Applied = append(result.Applied, relative)
		return
	}
	if !state.hasBlob(content) {
		entry.Local = false
		entry.Pending, entry.PendingMode = content, mode
		result.needBlob(content, size, relative)
		return
	}
	if err := session.materializeOwn(state, entry, content, mode, "absent"); err != nil {
		entry.Local = false
		result.Failed = append(result.Failed, PathFailure{Path: relative, Reason: reasonOf(err)})
		return
	}
	result.Applied = append(result.Applied, relative)
}

func entryFromChange(change DriveChange, relative string) *DriveEntry {
	return &DriveEntry{Path: relative, NodeID: change.NodeID, VersionID: change.VersionID, SHA256: change.SHA256,
		Size: change.Size, Mode: change.Mode, ContentType: change.ContentType, LinkTarget: change.LinkTarget,
		LastUsedNs: time.Now().UnixNano()}
}

// ownShouldBeLocal: an own file is mirrored unless it is above the threshold and outside the pinned folders.
func (state *DriveState) ownShouldBeLocal(relative string, size int64) bool {
	return size <= state.ThresholdBytes || underPinned(state.Own.Pinned, relative)
}

// textExtensions are the small files a Plan B mount materialises without a pin.
var textExtensions = map[string]bool{".md": true, ".txt": true, ".csv": true, ".tsv": true, ".json": true,
	".yaml": true, ".yml": true, ".xml": true, ".html": true, ".htm": true, ".py": true, ".js": true, ".ts": true,
	".sql": true, ".ini": true, ".toml": true, ".log": true, ".css": true, ".sh": true}

func mountShouldBeLocal(state *DriveState, mount *DriveSpaceState, change DriveChange) bool {
	if change.LinkTarget != "" {
		return false
	}
	if underPinned(mount.Pinned, change.Path) {
		return true
	}
	if change.Size > state.mountTextMax {
		return false
	}
	if strings.HasPrefix(change.ContentType, "text/") || change.ContentType == "application/json" {
		return true
	}
	return textExtensions[strings.ToLower(path.Ext(change.Path))]
}

// ---------------------------------------------------------------------------------------------------------------------
// Pull

// Pull applies a change list of one space (the caller does not hold the state mutex).
func (session *Session) Pull(request PullRequest) (*PullResult, error) {
	state := session.drive
	if state == nil {
		return nil, ErrDriveOff
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	return session.pullLocked(state, request)
}

func (session *Session) pullLocked(state *DriveState, request PullRequest) (*PullResult, error) {
	target := request.Target
	if target == "own" {
		target = ""
	}
	result := newPullResult(map[bool]string{true: "own", false: target}[target == ""])
	if target != "" {
		mount := state.Mounts[target]
		if mount == nil {
			return nil, fmt.Errorf("no such mount")
		}
		if request.Pinned != nil {
			mount.Pinned = cleanFolders(request.Pinned)
		}
		var downloads []DriveChange
		for _, change := range request.Changes {
			if change.URL != "" && change.Op != "delete" && mountShouldBeLocal(state, mount, change) {
				downloads = append(downloads, change)
			}
		}
		session.prefetch(state, downloads, result)
		for _, change := range request.Changes {
			session.applyMountChange(state, mount, change, result)
		}
		mount.Cursor = max(mount.Cursor, request.Cursor)
		result.Cursor = mount.Cursor
		session.finishSync(state, result)
		return result, nil
	}
	if request.Pinned != nil {
		state.Own.Pinned = cleanFolders(request.Pinned)
	}
	scan, err := session.scanTree(state)
	if err != nil {
		return nil, err
	}
	present := map[string]ScannedFile{}
	for _, file := range scan.Files {
		present[file.Path] = file
	}
	links := map[string]ScannedFile{}
	for _, link := range scan.Symlinks {
		links[link.Path] = link
	}
	var downloads []DriveChange
	for _, change := range request.Changes {
		if change.URL != "" && change.Op != "delete" && change.LinkTarget == "" {
			downloads = append(downloads, change)
		}
	}
	session.prefetch(state, downloads, result)
	for _, change := range request.Changes {
		session.applyOwnChange(state, change, present, links, result)
	}
	state.Own.Cursor = max(state.Own.Cursor, request.Cursor)
	result.Cursor = state.Own.Cursor
	session.finishSync(state, result)
	return result, nil
}

// finishSync rewrites the index files, enforces the mirror budget, drops unreferenced blobs and saves the state.
func (session *Session) finishSync(state *DriveState, result *PullResult) {
	result.Evicted = append(result.Evicted, session.enforceBudget(state)...)
	state.collectGarbage()
	session.writeIndexes(state)
	if session.fuse != nil && session.fuse.session.Load() == session {
		session.fuse.publish(state)
	}
	if err := state.save(); err != nil {
		session.runner.logger.Warn("drive state not saved", "session", session.ID)
	}
}

func reasonOf(err error) string {
	switch {
	case errors.Is(err, errTreeChanged):
		return "the file changed while the runner worked on it; nothing was overwritten"
	case errors.Is(err, errHashMismatch):
		return "the downloaded content does not match its sha256"
	case errors.Is(err, errTransfer):
		return "the download failed"
	}
	return "the file could not be written"
}

// applyOwnChange applies one incoming change to the own drive. A locally changed path is never overwritten: text is
// merged when the changes do not overlap, everything else becomes a conflict.
func (session *Session) applyOwnChange(state *DriveState, change DriveChange, present, links map[string]ScannedFile, result *PullResult) {
	relative, err := CleanRelativePath(change.Path)
	if err != nil || driveExcluded(state.rules, relative) {
		result.Failed = append(result.Failed, PathFailure{Path: change.Path, Reason: "not a path of the own drive"})
		return
	}
	own := state.Own
	if change.Op == "move" {
		from, err := CleanRelativePath(change.FromPath)
		if entry := own.Entries[from]; err == nil && entry != nil && from != relative {
			_, hasFile := present[from]
			_, hasLink := links[from]
			if hasFile || hasLink {
				if err := session.renameTree(from, relative); err != nil {
					result.Failed = append(result.Failed, PathFailure{Path: relative, Reason: "the move target exists locally"})
					return
				}
				if file, ok := present[from]; ok {
					file.Path = relative
					present[relative] = file
					delete(present, from)
				}
				if link, ok := links[from]; ok {
					link.Path = relative
					links[relative] = link
					delete(links, from)
				}
			}
			delete(own.Entries, from)
			entry.Path = relative
			own.Entries[relative] = entry
		}
	}
	entry := own.Entries[relative]
	file, hasFile := present[relative]
	link, hasLink := links[relative]
	untouched := false
	switch {
	case entry == nil:
		untouched = !hasFile && !hasLink
	case entry.LinkTarget != "":
		untouched = hasLink && link.Target == entry.LinkTarget
	case entry.Local:
		untouched = hasFile && file.SHA256 == entry.SHA256
	default:
		untouched = !hasFile && !hasLink
	}
	if change.Op == "delete" {
		if entry == nil {
			result.Skipped++
			return
		}
		if untouched || entry.Local && !hasFile && !hasLink {
			if hasFile || hasLink {
				if err := session.removeTree(relative, map[bool]string{true: entry.SHA256, false: "-"}[hasFile]); err != nil {
					result.Failed = append(result.Failed, PathFailure{Path: relative, Reason: reasonOf(err)})
					return
				}
			}
			delete(own.Entries, relative)
			result.Applied = append(result.Applied, relative)
			return
		}
		entry.Conflict = &DriveConflict{Kind: "trashed", TheirsNodeID: change.NodeID, TheirsVersionID: change.VersionID}
		result.Conflicts = append(result.Conflicts, ConflictView{Path: relative, DriveConflict: *entry.Conflict, BaseVersionID: entry.VersionID})
		return
	}
	if entry != nil && entry.VersionID == change.VersionID && entry.SHA256 == change.SHA256 && entry.Conflict == nil {
		result.Skipped++
		return
	}
	if change.LinkTarget != "" {
		if !untouched && !(hasLink && link.Target == change.LinkTarget) {
			session.recordConflict(state, relative, entry, change, "", false, result)
			return
		}
		if _, err := session.linkTree(relative, change.LinkTarget, map[bool]string{true: "-", false: "absent"}[hasLink]); err != nil {
			result.Failed = append(result.Failed, PathFailure{Path: relative, Reason: reasonOf(err)})
			return
		}
		next := entryFromChange(change, relative)
		next.Local = true
		own.Entries[relative] = next
		result.Applied = append(result.Applied, relative)
		return
	}
	if hasFile && file.SHA256 == change.SHA256 {
		// the same content on both sides: only the base moves
		next := entryFromChange(change, relative)
		next.Local, next.MtimeNs, next.Inode = true, file.MtimeNs, file.Inode
		own.Entries[relative] = next
		result.Applied = append(result.Applied, relative)
		return
	}
	if untouched {
		next := entryFromChange(change, relative)
		if !state.ownShouldBeLocal(relative, change.Size) {
			if hasFile {
				if err := session.removeTree(relative, file.SHA256); err != nil {
					result.Failed = append(result.Failed, PathFailure{Path: relative, Reason: reasonOf(err)})
					return
				}
			}
			own.Entries[relative] = next
			result.Applied = append(result.Applied, relative)
			return
		}
		if !state.hasBlob(change.SHA256) {
			result.needBlob(change.SHA256, change.Size, relative)
			return
		}
		expect := "absent"
		if hasFile {
			expect = file.SHA256
		} else if hasLink {
			expect = "-"
		}
		if err := session.materializeOwn(state, next, change.SHA256, change.Mode, expect); err != nil {
			result.Failed = append(result.Failed, PathFailure{Path: relative, Reason: reasonOf(err)})
			return
		}
		own.Entries[relative] = next
		result.Applied = append(result.Applied, relative)
		return
	}
	// both sides changed
	mergeable := entry != nil && entry.Local && entry.LinkTarget == "" && hasFile && state.hasBlob(entry.SHA256) &&
		max(file.Size, entry.Size, change.Size) <= session.runner.config.DriveMergeMaxBytes
	if !mergeable {
		session.recordConflict(state, relative, entry, change, "", hasFile && !isTextPath(relative), result)
		return
	}
	if !state.hasBlob(change.SHA256) {
		result.needBlob(change.SHA256, change.Size, relative)
		return
	}
	outcome, err := session.mergeTree(state, relative, file.SHA256, entry.SHA256, change.SHA256)
	if err != nil {
		result.Failed = append(result.Failed, PathFailure{Path: relative, Reason: reasonOf(err)})
		return
	}
	switch outcome.Result {
	case "merged":
		// the merge result is a local change on top of the new base
		next := entryFromChange(change, relative)
		next.Local, next.MtimeNs, next.Inode = true, outcome.MtimeNs, outcome.Inode
		own.Entries[relative] = next
		result.Merged = append(result.Merged, relative)
	case "conflict":
		session.recordConflict(state, relative, entry, change, outcome.SideFile, false, result)
	default:
		session.recordConflict(state, relative, entry, change, "", true, result)
	}
}

func isTextPath(relative string) bool {
	return textExtensions[strings.ToLower(path.Ext(relative))]
}

// recordConflict marks a path as conflicted: nothing is overwritten, the incoming version's facts are kept.
func (session *Session) recordConflict(state *DriveState, relative string, entry *DriveEntry, change DriveChange, sideFile string, binary bool, result *PullResult) {
	if entry == nil {
		// added on both sides: the base is the empty file of no version
		entry = &DriveEntry{Path: relative, NodeID: change.NodeID, Local: true, LastUsedNs: time.Now().UnixNano()}
		state.Own.Entries[relative] = entry
	}
	entry.Conflict = &DriveConflict{Kind: "both-changed", TheirsNodeID: change.NodeID, TheirsVersionID: change.VersionID,
		TheirsSHA256: change.SHA256, TheirsSize: change.Size, SideFile: sideFile, Binary: binary}
	result.Conflicts = append(result.Conflicts, ConflictView{Path: relative, DriveConflict: *entry.Conflict, BaseVersionID: entry.VersionID})
}

// applyMountChange applies one change to a read-only mount: materialised (pinned or small text) or indexed.
func (session *Session) applyMountChange(state *DriveState, mount *DriveSpaceState, change DriveChange, result *PullResult) {
	relative, err := CleanRelativePath(change.Path)
	if err != nil || relative == DriveSpaceFile || relative == DriveIndexFile {
		result.Failed = append(result.Failed, PathFailure{Path: change.Path, Reason: "not a path of the mount"})
		return
	}
	workspacePath := path.Join(DriveSpacesDir, mount.Name, relative)
	if change.Op == "move" {
		if from, err := CleanRelativePath(change.FromPath); err == nil && mount.Entries[from] != nil {
			if mount.Entries[from].Local {
				session.removeRootFile(path.Join(DriveSpacesDir, mount.Name, from))
			}
			delete(mount.Entries, from)
		}
	}
	entry := mount.Entries[relative]
	if change.Op == "delete" {
		if entry == nil {
			result.Skipped++
			return
		}
		if entry.Local {
			session.removeRootFile(workspacePath)
		}
		delete(mount.Entries, relative)
		result.Applied = append(result.Applied, workspacePath)
		return
	}
	local := mountShouldBeLocal(state, mount, change)
	if entry != nil && entry.VersionID == change.VersionID && entry.SHA256 == change.SHA256 && entry.Local == local {
		result.Skipped++
		return
	}
	next := entryFromChange(change, relative)
	mount.Entries[relative] = next
	if !local {
		if entry != nil && entry.Local {
			session.removeRootFile(workspacePath)
		}
		result.Applied = append(result.Applied, workspacePath)
		return
	}
	if !state.hasBlob(change.SHA256) {
		next.Pending = change.SHA256
		if entry != nil && entry.Local {
			session.removeRootFile(workspacePath)
		}
		result.needBlob(change.SHA256, change.Size, workspacePath)
		return
	}
	if err := session.writeMountFile(state, workspacePath, change.SHA256); err != nil {
		result.Failed = append(result.Failed, PathFailure{Path: workspacePath, Reason: reasonOf(err)})
		return
	}
	next.Local = true
	result.Applied = append(result.Applied, workspacePath)
}

// ---------------------------------------------------------------------------------------------------------------------
// Materialise (drive get, pin, pending claim entries)

// MaterializeItem names one path to make local; URL is a presigned GET (proxy transfer).
type MaterializeItem struct {
	Path   string `json:"path"`
	URL    string `json:"url,omitempty"`
	SHA256 string `json:"sha256,omitempty"`
}

// resolveWorkspacePath splits a workspace-relative path into its space ("" own, else the mount) and the space path.
func (state *DriveState) resolveWorkspacePath(workspacePath string) (*DriveSpaceState, string, bool) {
	relative, err := CleanRelativePath(workspacePath)
	if err != nil {
		return nil, "", false
	}
	if rest, found := strings.CutPrefix(relative, DriveSpacesDir+"/"); found {
		name, inside, _ := strings.Cut(rest, "/")
		mount := state.Mounts[name]
		if mount == nil || inside == "" {
			return nil, "", false
		}
		return mount, inside, true
	}
	if relative == DriveSpacesDir {
		return nil, "", false
	}
	return state.Own, relative, true
}

func spaceWorkspacePath(space *DriveSpaceState, relative string) string {
	if space.Name == "" {
		return relative
	}
	return path.Join(DriveSpacesDir, space.Name, relative)
}

// Materialize makes the named paths local; no items: every pending entry (claim in stream transfer).
func (session *Session) Materialize(items []MaterializeItem) (*PullResult, error) {
	state := session.drive
	if state == nil {
		return nil, ErrDriveOff
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	return session.materializeLocked(state, items), nil
}

func (session *Session) materializeLocked(state *DriveState, items []MaterializeItem) *PullResult {
	result := newPullResult("materialize")
	if len(items) == 0 {
		for _, space := range append([]*DriveSpaceState{state.Own}, state.spacesOfMounts()...) {
			for _, entry := range space.Entries {
				if entry.Pending != "" {
					items = append(items, MaterializeItem{Path: spaceWorkspacePath(space, entry.Path)})
				}
			}
		}
		sort.Slice(items, func(left, right int) bool { return items[left].Path < items[right].Path })
	}
	var downloads []DriveChange
	for _, item := range items {
		if space, relative, ok := state.resolveWorkspacePath(item.Path); ok && item.URL != "" {
			if entry := space.Entries[relative]; entry != nil {
				downloads = append(downloads, DriveChange{Path: item.Path, SHA256: contentOf(entry), Size: entry.Size, URL: item.URL})
			}
		}
	}
	session.prefetch(state, downloads, result)
	for _, item := range items {
		space, relative, ok := state.resolveWorkspacePath(item.Path)
		entry := (*DriveEntry)(nil)
		if ok {
			entry = space.Entries[relative]
		}
		if entry == nil || entry.LinkTarget != "" {
			result.Failed = append(result.Failed, PathFailure{Path: item.Path, Reason: "not a drive file"})
			continue
		}
		if entry.Local {
			result.Skipped++
			continue
		}
		sha := contentOf(entry)
		if !state.hasBlob(sha) {
			result.needBlob(sha, entry.Size, item.Path)
			continue
		}
		var err error
		if space.Name == "" {
			err = session.materializeOwn(state, entry, sha, map[bool]string{true: entry.PendingMode, false: entry.Mode}[entry.Pending != ""], "absent")
		} else {
			err = session.writeMountFile(state, item.Path, sha)
		}
		if err != nil {
			result.Failed = append(result.Failed, PathFailure{Path: item.Path, Reason: reasonOf(err)})
			continue
		}
		entry.Local, entry.Pending, entry.PendingMode = true, "", ""
		entry.LastUsedNs = time.Now().UnixNano()
		result.Applied = append(result.Applied, item.Path)
	}
	session.finishSync(state, result)
	return result
}

func contentOf(entry *DriveEntry) string {
	if entry.Pending != "" {
		return entry.Pending
	}
	return entry.SHA256
}

func (state *DriveState) spacesOfMounts() []*DriveSpaceState {
	spaces := []*DriveSpaceState{}
	for _, name := range state.mountNames() {
		spaces = append(spaces, state.Mounts[name])
	}
	return spaces
}

// expandToFiles turns workspace paths (files or folders) into the not-local drive files they name.
func (state *DriveState) expandToFiles(workspacePaths []string) ([]string, []PathFailure) {
	var files []string
	var failures []PathFailure
	seen := map[string]bool{}
	for _, workspacePath := range workspacePaths {
		space, relative, ok := state.resolveWorkspacePath(workspacePath)
		if !ok {
			failures = append(failures, PathFailure{Path: workspacePath, Reason: "not a path of the drive"})
			continue
		}
		found := false
		for entryPath, entry := range space.Entries {
			if entryPath == relative || strings.HasPrefix(entryPath, relative+"/") {
				found = true
				full := spaceWorkspacePath(space, entryPath)
				if !entry.Local && entry.LinkTarget == "" && !seen[full] {
					seen[full] = true
					files = append(files, full)
				}
			}
		}
		if !found {
			failures = append(failures, PathFailure{Path: workspacePath, Reason: "no drive file at this path"})
		}
	}
	sort.Strings(files)
	return files, failures
}

// ---------------------------------------------------------------------------------------------------------------------
// Stage, commit, resolve

// StageResult is the status with every changed file snapshotted into the cache under its sha256.
type StageResult struct {
	DriveStatus
	Snapshots int `json:"snapshots"`
}

// Status computes the local status (no snapshot).
func (session *Session) Status() (DriveStatus, error) {
	state := session.drive
	if state == nil {
		return DriveStatus{}, ErrDriveOff
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	scan, err := session.scanTree(state)
	if err != nil {
		return DriveStatus{}, err
	}
	return state.computeStatus(scan), nil
}

// Stage snapshots every changed file: uploads read only from these snapshots, so hash and bytes never diverge.
func (session *Session) Stage() (StageResult, error) {
	state := session.drive
	if state == nil {
		return StageResult{}, ErrDriveOff
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	return session.stageLocked(state)
}

func (session *Session) stageLocked(state *DriveState) (StageResult, error) {
	scan, err := session.scanTree(state)
	if err != nil {
		return StageResult{}, err
	}
	result := StageResult{DriveStatus: state.computeStatus(scan)}
	staged := map[string][]string{}
	for index := range result.Changes {
		change := &result.Changes[index]
		if change.Type != "file" || change.Kind == ChangeDeleted {
			continue
		}
		sha, size, err := session.snapshot(state, change.Path)
		if err != nil {
			result.Unreadable = append(result.Unreadable, change.Path)
			change.SHA256 = ""
			continue
		}
		change.SHA256, change.Size = sha, size
		staged[sha] = append(staged[sha], change.Path)
		result.Snapshots++
	}
	state.Staged = staged
	state.collectGarbage()
	state.save()
	return result, nil
}

// CommitResult is the body of POST …/drive/commit: what PHP turned into versions. The base moves for these paths.
type CommitResult struct {
	Committed []DriveChange   `json:"committed"`
	Deleted   []string        `json:"deleted"`
	Conflicts []ConflictInput `json:"conflicts"`
}

// ConflictInput: a commit found a newer version than the base.
type ConflictInput struct {
	Path            string `json:"path"`
	TheirsNodeID    int64  `json:"theirsNodeId,omitempty"`
	TheirsVersionID int64  `json:"theirsVersionId"`
	TheirsSHA256    string `json:"theirsSha256,omitempty"`
	TheirsSize      int64  `json:"theirsSize,omitempty"`
}

// ApplyCommit moves the base of committed and deleted paths and records conflicts.
func (session *Session) ApplyCommit(commit CommitResult) (DriveStatus, error) {
	state := session.drive
	if state == nil {
		return DriveStatus{}, ErrDriveOff
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	return session.applyCommitLocked(state, commit)
}

func (session *Session) applyCommitLocked(state *DriveState, commit CommitResult) (DriveStatus, error) {
	own := state.Own
	for _, change := range commit.Committed {
		relative, err := CleanRelativePath(change.Path)
		if err != nil {
			continue
		}
		// a rename: the node keeps its id, the old path leaves the base map
		for entryPath, entry := range own.Entries {
			if entry.NodeID == change.NodeID && change.NodeID != 0 && entryPath != relative {
				delete(own.Entries, entryPath)
			}
		}
		next := entryFromChange(change, relative)
		next.Local = true
		own.Entries[relative] = next
		if paths := state.Staged[change.SHA256]; paths != nil {
			remaining := []string{}
			for _, stagedPath := range paths {
				if stagedPath != relative {
					remaining = append(remaining, stagedPath)
				}
			}
			if len(remaining) == 0 {
				delete(state.Staged, change.SHA256)
			} else {
				state.Staged[change.SHA256] = remaining
			}
		}
	}
	for _, deleted := range commit.Deleted {
		if relative, err := CleanRelativePath(deleted); err == nil {
			delete(own.Entries, relative)
		}
	}
	for _, conflict := range commit.Conflicts {
		relative, err := CleanRelativePath(conflict.Path)
		if err != nil {
			continue
		}
		entry := own.Entries[relative]
		if entry == nil {
			entry = &DriveEntry{Path: relative, NodeID: conflict.TheirsNodeID, Local: true}
			own.Entries[relative] = entry
		}
		entry.Conflict = &DriveConflict{Kind: "both-changed", TheirsNodeID: conflict.TheirsNodeID,
			TheirsVersionID: conflict.TheirsVersionID, TheirsSHA256: conflict.TheirsSHA256, TheirsSize: conflict.TheirsSize}
	}
	scan, err := session.scanTree(state)
	if err != nil {
		return DriveStatus{}, err
	}
	state.collectGarbage()
	session.writeIndexes(state)
	state.save()
	return state.computeStatus(scan), nil
}

// ResolveRequest settles one conflict: mine (keep the local content on top of their version), theirs (take their
// version), both (mine under Name, theirs at the path).
type ResolveRequest struct {
	Path       string `json:"path"`
	Resolution string `json:"resolution"`
	Name       string `json:"name,omitempty"`
	URL        string `json:"url,omitempty"`
}

// Resolve applies a conflict resolution.
func (session *Session) Resolve(request ResolveRequest) (*PullResult, error) {
	state := session.drive
	if state == nil {
		return nil, ErrDriveOff
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	result := newPullResult("resolve")
	relative, err := CleanRelativePath(request.Path)
	entry := state.Own.Entries[relative]
	if err != nil || entry == nil || entry.Conflict == nil {
		return nil, fmt.Errorf("no conflict at this path")
	}
	conflict := entry.Conflict
	theirs := DriveChange{Path: relative, NodeID: conflict.TheirsNodeID, VersionID: conflict.TheirsVersionID,
		SHA256: conflict.TheirsSHA256, Size: conflict.TheirsSize, Mode: entry.Mode, URL: request.URL}
	if theirs.NodeID == 0 {
		theirs.NodeID = entry.NodeID
	}
	switch request.Resolution {
	case "mine":
		if conflict.Kind == "trashed" {
			// restored with the change: the commit creates a new version on a restored node (PHP's part)
			entry.Conflict = nil
		} else {
			next := entryFromChange(theirs, relative)
			next.Local = true
			state.Own.Entries[relative] = next
		}
	case "theirs", "both":
		scan, err := session.scanTree(state)
		if err != nil {
			return nil, err
		}
		var current *ScannedFile
		for index := range scan.Files {
			if scan.Files[index].Path == relative {
				current = &scan.Files[index]
			}
		}
		if request.Resolution == "both" {
			name, err := CleanRelativePath(request.Name)
			if err != nil || name == relative || driveExcluded(state.rules, name) || current == nil {
				return nil, fmt.Errorf("both needs a new free name for the local file")
			}
			if err := session.renameTree(relative, name); err != nil {
				return nil, fmt.Errorf("the new name is taken")
			}
			current = nil
		}
		if conflict.Kind == "trashed" {
			if current != nil {
				if err := session.removeTree(relative, current.SHA256); err != nil {
					return nil, err
				}
			}
			delete(state.Own.Entries, relative)
			break
		}
		session.prefetch(state, []DriveChange{theirs}, result)
		if !state.hasBlob(theirs.SHA256) {
			result.needBlob(theirs.SHA256, theirs.Size, relative)
			return result, nil
		}
		next := entryFromChange(theirs, relative)
		expect := "absent"
		if current != nil {
			expect = current.SHA256
		}
		if err := session.materializeOwn(state, next, theirs.SHA256, theirs.Mode, expect); err != nil {
			return nil, err
		}
		state.Own.Entries[relative] = next
	default:
		return nil, fmt.Errorf("resolution must be mine, theirs or both")
	}
	if conflict.SideFile != "" {
		session.removeTree(conflict.SideFile, "-")
	}
	result.Applied = append(result.Applied, relative)
	session.finishSync(state, result)
	return result, nil
}

// SetPinned pins or unpins a folder; unpinning a mount folder drops its contents to the index.
func (session *Session) SetPinned(folder string, pinned bool, items []MaterializeItem) (*PullResult, error) {
	state := session.drive
	if state == nil {
		return nil, ErrDriveOff
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	space, relative, ok := state.resolveWorkspacePath(folder)
	if !ok && strings.HasPrefix(folder, DriveSpacesDir+"/") {
		// the mount root itself
		name := strings.TrimSuffix(strings.TrimPrefix(folder, DriveSpacesDir+"/"), "/")
		if space = state.Mounts[name]; space != nil {
			relative, ok = "", true
		}
	}
	if !ok {
		return nil, fmt.Errorf("not a folder of the drive")
	}
	folders := []string{}
	for _, existing := range space.Pinned {
		if existing != relative {
			folders = append(folders, existing)
		}
	}
	if pinned {
		folders = append(folders, relative)
	}
	sort.Strings(folders)
	space.Pinned = folders
	if pinned {
		result := session.materializeLocked(state, items)
		return result, nil
	}
	result := newPullResult("unpin")
	if space.Name != "" {
		for entryPath, entry := range space.Entries {
			if entry.Local && underPinned([]string{relative}, entryPath) && !underPinned(space.Pinned, entryPath) &&
				!mountShouldBeLocal(state, space, DriveChange{Path: entryPath, Size: entry.Size, ContentType: entry.ContentType}) {
				workspacePath := path.Join(DriveSpacesDir, space.Name, entryPath)
				session.removeRootFile(workspacePath)
				entry.Local = false
				result.Evicted = append(result.Evicted, workspacePath)
			}
		}
	}
	session.finishSync(state, result)
	return result, nil
}

// ---------------------------------------------------------------------------------------------------------------------
// Mirror budget and index

// enforceBudget turns the least recently used local files into index entries while tree plus cache exceed the
// budget. A locally changed own file is never dropped (the helper checks its hash).
func (session *Session) enforceBudget(state *DriveState) []string {
	if state.MirrorBudgetBytes <= 0 {
		return nil
	}
	total := state.treeBytes() + state.cacheBytes()
	if total <= state.MirrorBudgetBytes {
		return nil
	}
	var evicted []string
	for _, candidate := range state.evictionOrder() {
		if total <= state.MirrorBudgetBytes {
			break
		}
		entry := candidate.entry
		if candidate.mount != "" {
			workspacePath := path.Join(DriveSpacesDir, candidate.mount, entry.Path)
			session.removeRootFile(workspacePath)
			entry.Local = false
			total -= entry.Size
			evicted = append(evicted, workspacePath)
			continue
		}
		if err := session.removeTree(entry.Path, entry.SHA256); err != nil {
			continue
		}
		entry.Local = false
		total -= entry.Size
		if !state.referencedBlobs()[entry.SHA256] && state.hasBlob(entry.SHA256) {
			total -= entry.Size
		}
		evicted = append(evicted, entry.Path)
	}
	return evicted
}

// writeIndexes writes /workspace/.drive-index and drivespaces/<name>/.drive-index (root-owned, read-only).
func (session *Session) writeIndexes(state *DriveState) {
	session.writeRootFile(DriveIndexFile, indexLines(state.Own))
	if state.Streamed {
		// the placeholder file system serves each mount's index itself
		return
	}
	for _, name := range state.mountNames() {
		session.writeRootFile(path.Join(DriveSpacesDir, name, DriveIndexFile), indexLines(state.Mounts[name]))
	}
}

// ---------------------------------------------------------------------------------------------------------------------
// Tree operations through the helper (session uid)

func (session *Session) driveHelper(stdin io.Reader, extra []*os.File, arguments ...string) ([]byte, error) {
	command := session.runner.helperCommand(session.UID, session.env, arguments...)
	var stdout bytes.Buffer
	command.Stdin = stdin
	command.Stdout = &stdout
	command.ExtraFiles = extra
	err := command.Run()
	for _, file := range extra {
		file.Close()
	}
	var exitErr interface{ ExitCode() int }
	if errors.As(err, &exitErr) {
		if exitErr.ExitCode() == helperExitChanged {
			return nil, errTreeChanged
		}
		return nil, fmt.Errorf("drive helper %s failed (exit %d)", arguments[0], exitErr.ExitCode())
	}
	if err != nil {
		return nil, err
	}
	return stdout.Bytes(), nil
}

// scanTree walks the own drive as the session uid; files whose size, mtime and inode match the last scan or the
// base are not hashed again.
func (session *Session) scanTree(state *DriveState) (TreeScan, error) {
	known := map[string]knownStat{}
	for entryPath, entry := range state.Own.Entries {
		if entry.Local && entry.MtimeNs != 0 {
			known[entryPath] = knownStat{Size: entry.Size, MtimeNs: entry.MtimeNs, Inode: entry.Inode, SHA256: entry.SHA256}
		}
	}
	for entryPath, stat := range state.scanCache {
		known[entryPath] = stat
	}
	encodedKnown, _ := json.Marshal(known)
	encodedRules, _ := json.Marshal(state.Ignore)
	output, err := session.driveHelper(bytes.NewReader(encodedKnown), nil, "drive-scan", state.workspace, string(encodedRules))
	if err != nil {
		return TreeScan{}, err
	}
	var scan TreeScan
	if err := json.Unmarshal(output, &scan); err != nil {
		return TreeScan{}, err
	}
	state.scanCache = map[string]knownStat{}
	for _, file := range scan.Files {
		state.scanCache[file.Path] = knownStat{Size: file.Size, MtimeNs: file.MtimeNs, Inode: file.Inode, SHA256: file.SHA256}
	}
	return scan, nil
}

type treeWrite struct {
	Size    int64  `json:"size"`
	MtimeNs int64  `json:"mtimeNs"`
	Inode   uint64 `json:"inode"`
	SHA256  string `json:"sha256"`
}

// materializeOwn writes the blob sha into the own tree as an independent copy and records the tree stat on entry.
func (session *Session) materializeOwn(state *DriveState, entry *DriveEntry, sha, mode, expect string) error {
	source, err := os.Open(state.cachePath(sha))
	if err != nil {
		return err
	}
	if mode == "" {
		mode = "-"
	}
	output, err := session.driveHelper(nil, []*os.File{source}, "drive-put", state.workspace, entry.Path, mode, sha, expect)
	if err != nil {
		return err
	}
	var written treeWrite
	if err := json.Unmarshal(output, &written); err != nil {
		return err
	}
	entry.Local, entry.MtimeNs, entry.Inode = true, written.MtimeNs, written.Inode
	entry.LastUsedNs = time.Now().UnixNano()
	return nil
}

func (session *Session) linkTree(relative, target, expect string) ([]byte, error) {
	return session.driveHelper(nil, nil, "drive-link", session.runner.config.Workspace, relative, target, expect)
}

func (session *Session) removeTree(relative, expect string) error {
	_, err := session.driveHelper(nil, nil, "drive-remove", session.runner.config.Workspace, relative, expect)
	return err
}

func (session *Session) renameTree(from, to string) error {
	_, err := session.driveHelper(nil, nil, "drive-rename", session.runner.config.Workspace, from, to)
	return err
}

// snapshot copies a tree file into the cache: the helper writes into a fresh root-owned temporary (passed as a
// write-only descriptor), the runner hashes what arrived and files it read-only under that hash.
func (session *Session) snapshot(state *DriveState, relative string) (string, int64, error) {
	cacheDir := filepath.Join(state.stateDir, "cache")
	temporary := filepath.Join(cacheDir, ".snap-"+randomHex(8))
	destination, err := os.OpenFile(temporary, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return "", 0, err
	}
	if _, err := session.driveHelper(nil, []*os.File{destination}, "drive-snapshot", state.workspace, relative); err != nil {
		os.Remove(temporary)
		return "", 0, err
	}
	return fileIntoCache(state, temporary)
}

// fileIntoCache hashes a root-owned temporary of the cache and renames it to its sha256 (read-only).
func fileIntoCache(state *DriveState, temporary string) (string, int64, error) {
	file, err := os.Open(temporary)
	if err != nil {
		return "", 0, err
	}
	sha, size, err := hashFile(file)
	file.Close()
	if err != nil {
		os.Remove(temporary)
		return "", 0, err
	}
	if state.hasBlob(sha) {
		os.Remove(temporary)
		return sha, size, nil
	}
	os.Chmod(temporary, 0o400)
	if err := os.Rename(temporary, state.cachePath(sha)); err != nil {
		os.Remove(temporary)
		return "", 0, err
	}
	return sha, size, nil
}

// errHashMismatch: received bytes do not match the announced sha256.
var errHashMismatch = errors.New("hash mismatch")

// receiveBlob stores bytes from reader in the cache when they hash to sha.
func receiveBlob(state *DriveState, sha string, reader io.Reader, limit int64) error {
	temporary := filepath.Join(state.stateDir, "cache", ".recv-"+randomHex(8))
	file, err := os.OpenFile(temporary, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return err
	}
	hash := sha256.New()
	_, copyErr := io.Copy(io.MultiWriter(file, hash), io.LimitReader(reader, limit+1))
	closeErr := file.Close()
	if err := errors.Join(copyErr, closeErr); err != nil {
		os.Remove(temporary)
		return err
	}
	if hex.EncodeToString(hash.Sum(nil)) != sha {
		os.Remove(temporary)
		return errHashMismatch
	}
	if _, _, err := fileIntoCache(state, temporary); err != nil {
		return err
	}
	return nil
}

func (session *Session) mergeTree(state *DriveState, relative, mineSHA, baseSHA, theirsSHA string) (MergeOutcome, error) {
	base, err := os.Open(state.cachePath(baseSHA))
	if err != nil {
		return MergeOutcome{}, err
	}
	theirs, err := os.Open(state.cachePath(theirsSHA))
	if err != nil {
		base.Close()
		return MergeOutcome{}, err
	}
	sideFile := path.Join(DriveConflictsDir, relative)
	output, err := session.driveHelper(nil, []*os.File{base, theirs}, "drive-merge", state.workspace, relative, mineSHA, sideFile)
	if err != nil {
		return MergeOutcome{}, err
	}
	var outcome MergeOutcome
	if err := json.Unmarshal(output, &outcome); err != nil {
		return MergeOutcome{}, err
	}
	return outcome, nil
}

// Merge runs a three-way merge of the local file with another version (POST …/drive/merge).
func (session *Session) Merge(relative string, theirs DriveChange) (MergeOutcome, *PullResult, error) {
	state := session.drive
	if state == nil {
		return MergeOutcome{}, nil, ErrDriveOff
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	result := newPullResult("merge")
	entry := state.Own.Entries[relative]
	if entry == nil || !entry.Local || !state.hasBlob(entry.SHA256) {
		return MergeOutcome{Result: "binary"}, result, nil
	}
	session.prefetch(state, []DriveChange{theirs}, result)
	if !state.hasBlob(theirs.SHA256) {
		result.needBlob(theirs.SHA256, theirs.Size, relative)
		return MergeOutcome{}, result, nil
	}
	scan, err := session.scanTree(state)
	if err != nil {
		return MergeOutcome{}, nil, err
	}
	present := map[string]ScannedFile{}
	for _, file := range scan.Files {
		present[file.Path] = file
	}
	file, ok := present[relative]
	if !ok {
		return MergeOutcome{}, nil, fmt.Errorf("the local file is missing")
	}
	outcome, err := session.mergeTree(state, relative, file.SHA256, entry.SHA256, theirs.SHA256)
	if err != nil {
		return MergeOutcome{}, nil, err
	}
	switch outcome.Result {
	case "merged":
		theirs.Path = relative
		next := entryFromChange(theirs, relative)
		next.Local, next.MtimeNs, next.Inode = true, outcome.MtimeNs, outcome.Inode
		state.Own.Entries[relative] = next
	case "conflict":
		session.recordConflict(state, relative, entry, theirs, outcome.SideFile, false, result)
	default:
		session.recordConflict(state, relative, entry, theirs, "", true, result)
	}
	state.save()
	return outcome, result, nil
}

// Diff answers `git diff --no-index` between the base and the local file.
func (session *Session) Diff(relative string) (string, error) {
	state := session.drive
	if state == nil {
		return "", ErrDriveOff
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	entry := state.Own.Entries[relative]
	var extra []*os.File
	hasBase := "0"
	if entry != nil && entry.SHA256 != "" {
		if entry.Size > session.runner.config.DriveMergeMaxBytes {
			return fmt.Sprintf("%s: %d bytes, too large for a diff; base sha256 %s\n", relative, entry.Size, entry.SHA256), nil
		}
		base, err := os.Open(state.cachePath(entry.SHA256))
		if err != nil {
			return fmt.Sprintf("%s: the base (version %d) is not cached; only the hash is known: %s\n", relative,
				entry.VersionID, entry.SHA256), nil
		}
		extra, hasBase = []*os.File{base}, "1"
	}
	output, err := session.driveHelper(nil, extra, "drive-diff", state.workspace, relative, hasBase)
	if err != nil {
		return "", err
	}
	return string(output), nil
}

// ---------------------------------------------------------------------------------------------------------------------
// Root-owned files inside the workspace (mounts, SPACE.md, index): written by the runner itself through os.Root, so
// a symlink the session planted cannot redirect them.

func (session *Session) workspaceRoot() (*os.Root, error) {
	return os.OpenRoot(session.runner.config.Workspace)
}

// rootOwnedDirectory checks that the drivespaces folder is still the runner's.
func (session *Session) rootOwnedDirectory(root *os.Root, relative string) error {
	info, err := root.Lstat(relative)
	if err != nil {
		return err
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !info.IsDir() || !ok || int(stat.Uid) != session.runner.ownUID {
		return fmt.Errorf("drive folder is not the runner's")
	}
	return nil
}

func (session *Session) rootMkdir(relative string) error {
	root, err := session.workspaceRoot()
	if err != nil {
		return err
	}
	defer root.Close()
	current := ""
	for _, segment := range strings.Split(relative, "/") {
		current = path.Join(current, segment)
		if err := root.Mkdir(current, 0o755); err != nil && !errors.Is(err, fs.ErrExist) {
			return err
		}
		if err := session.rootOwnedDirectory(root, current); err != nil {
			return err
		}
		root.Chmod(current, 0o755)
	}
	return nil
}

func (session *Session) writeRootFile(relative string, content []byte) error {
	return session.writeRootFileFrom(relative, bytes.NewReader(content))
}

func (session *Session) writeRootFileFrom(relative string, reader io.Reader) error {
	if directory := path.Dir(relative); directory != "." {
		if err := session.rootMkdir(directory); err != nil {
			return err
		}
	}
	root, err := session.workspaceRoot()
	if err != nil {
		return err
	}
	defer root.Close()
	temporary := path.Join(path.Dir(relative), helperTemporaryPrefix+randomSuffix())
	file, err := root.OpenFile(temporary, os.O_WRONLY|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0o644)
	if err != nil {
		return err
	}
	_, copyErr := io.Copy(file, reader)
	closeErr := file.Close()
	if err := errors.Join(copyErr, closeErr, root.Chmod(temporary, 0o444)); err != nil {
		root.Remove(temporary)
		return err
	}
	if err := root.Rename(temporary, relative); err != nil {
		root.Remove(temporary)
		return err
	}
	return nil
}

// writeMountFile copies a cache blob into a mount (root-owned, 0444); a streamed mount serves the blob from the
// cache, so it only has to be there.
func (session *Session) writeMountFile(state *DriveState, workspacePath, sha string) error {
	if state.Streamed {
		if !state.hasBlob(sha) {
			return fs.ErrNotExist
		}
		return nil
	}
	source, err := os.Open(state.cachePath(sha))
	if err != nil {
		return err
	}
	defer source.Close()
	return session.writeRootFileFrom(workspacePath, source)
}

func (session *Session) removeRootFile(relative string) {
	if session.drive != nil && session.drive.Streamed && strings.HasPrefix(relative, DriveSpacesDir+"/") {
		// streamed mounts have no files in the tree; the blob goes with the cache's garbage collection
		return
	}
	root, err := session.workspaceRoot()
	if err != nil {
		return
	}
	defer root.Close()
	root.Remove(relative)
	for directory := path.Dir(relative); strings.Count(directory, "/") >= 2; directory = path.Dir(directory) {
		if root.Remove(directory) != nil {
			break
		}
	}
}

// closeDrive ends drive mode at release: the socket goes and the state directory is emptied (state.json, cache);
// the directory itself stays (a mount point on the cluster). The wipe removes the tree. An error breaks the runner
// like a failed wipe: no next session may see this one's base map or blobs.
func (session *Session) closeDrive() error {
	if session.driveListener != nil {
		session.driveListener.Close()
	}
	if session.drive == nil {
		return nil
	}
	config := session.runner.config
	// an operation still in flight finishes before the state goes, and none starts on it afterwards
	session.drive.mutex.Lock()
	defer session.drive.mutex.Unlock()
	os.Remove(config.DriveSocket)
	os.Chmod(config.Workspace, 0o777)
	_, err := clearStateDir(config.DriveStateDir)
	return err
}
