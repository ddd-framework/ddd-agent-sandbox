package main

// Helper operations of drive mode. They run under the session uid like every other helper: the runner (root without
// CAP_DAC_OVERRIDE) cannot open the session's files, and the session cannot open the root-only cache. A blob crosses
// between the two as an open file descriptor the runner hands to the helper (ExtraFiles, fd 3 and 4): read-only for a
// source, write-only for a snapshot. The helper never sees a path into the cache.

import (
	"bufio"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/exec"
	"path"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
)

// helperExitChanged: the tree file is not in the expected state (changed concurrently); nothing was done.
const helperExitChanged = 8

var errTreeChanged = errors.New("tree file changed")

// ficlone is FICLONE (_IOW(0x94, 9, int)): a reflink copy where the file system offers one (XFS, btrfs).
const ficlone = 0x40049409

// cloneOrCopy copies source into destination: a reflink first, a plain copy where that fails (other file system,
// tmpfs, ext4). Both are independent files: a later write to one never reaches the other.
func cloneOrCopy(destination, source *os.File) (int64, error) {
	if _, _, errno := syscall.Syscall(syscall.SYS_IOCTL, destination.Fd(), ficlone, source.Fd()); errno == 0 {
		info, err := destination.Stat()
		if err != nil {
			return 0, err
		}
		return info.Size(), nil
	}
	if _, err := source.Seek(0, io.SeekStart); err != nil {
		return 0, err
	}
	return io.Copy(destination, source)
}

func hashFile(file *os.File) (string, int64, error) {
	if _, err := file.Seek(0, io.SeekStart); err != nil {
		return "", 0, err
	}
	hash := sha256.New()
	size, err := io.Copy(hash, file)
	if err != nil {
		return "", 0, err
	}
	return hex.EncodeToString(hash.Sum(nil)), size, nil
}

func statIdentity(info fs.FileInfo) (int64, uint64) {
	if stat, ok := info.Sys().(*syscall.Stat_t); ok {
		return info.ModTime().UnixNano(), stat.Ino
	}
	return info.ModTime().UnixNano(), 0
}

// RunDriveHelper dispatches the drive helper operations; false when the operation is not one of them.
func RunDriveHelper(arguments []string) (bool, error) {
	switch arguments[0] {
	case "drive-scan":
		return true, helperDriveScan(arguments[1], arguments[2])
	case "drive-put":
		return true, helperDrivePut(arguments[1], arguments[2], arguments[3], arguments[4], arguments[5])
	case "drive-link":
		return true, helperDriveLink(arguments[1], arguments[2], arguments[3], arguments[4])
	case "drive-remove":
		return true, helperDriveRemove(arguments[1], arguments[2], arguments[3])
	case "drive-rename":
		return true, helperDriveRename(arguments[1], arguments[2], arguments[3])
	case "drive-snapshot":
		return true, helperDriveSnapshot(arguments[1], arguments[2])
	case "drive-merge":
		return true, helperDriveMerge(arguments[1], arguments[2], arguments[3], arguments[4])
	case "drive-diff":
		return true, helperDriveDiff(arguments[1], arguments[2], arguments[3])
	}
	return false, nil
}

// knownStat lets the scan skip hashing a file whose size, mtime and inode are unchanged.
type knownStat struct {
	Size    int64  `json:"size"`
	MtimeNs int64  `json:"mtimeNs"`
	Inode   uint64 `json:"inode"`
	SHA256  string `json:"sha256"`
}

// helperDriveScan: drive-scan <workspace> <rules JSON> — stdin: {path: knownStat}. Walks the own drive (exclusions
// and .driveignore applied), hashes what changed, lists symlinks with their target text.
func helperDriveScan(workspace, rulesJSON string) error {
	var patterns []string
	if err := json.Unmarshal([]byte(rulesJSON), &patterns); err != nil {
		return err
	}
	known := map[string]knownStat{}
	json.NewDecoder(bufio.NewReader(os.Stdin)).Decode(&known)
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	if content, err := root.ReadFile(DriveIgnoreFile); err == nil && len(content) <= 64<<10 {
		patterns = append(patterns, strings.Split(string(content), "\n")...)
	}
	rules := parseIgnoreRules(patterns)
	scan := TreeScan{Files: []ScannedFile{}, Symlinks: []ScannedFile{}, Unreadable: []string{}}
	walkErr := fs.WalkDir(root.FS(), ".", func(name string, entry fs.DirEntry, err error) error {
		if name == "." {
			return err
		}
		if driveExcluded(rules, name) {
			if entry != nil && entry.IsDir() {
				return fs.SkipDir
			}
			return nil
		}
		if err != nil {
			scan.Unreadable = append(scan.Unreadable, name)
			return nil
		}
		if entry.IsDir() {
			return nil
		}
		if entry.Type()&fs.ModeSymlink != 0 {
			target, err := root.Readlink(name)
			if err != nil {
				scan.Unreadable = append(scan.Unreadable, name)
				return nil
			}
			scan.Symlinks = append(scan.Symlinks, ScannedFile{Path: name, Target: target})
			return nil
		}
		if !entry.Type().IsRegular() {
			return nil
		}
		info, err := entry.Info()
		if err != nil {
			scan.Unreadable = append(scan.Unreadable, name)
			return nil
		}
		mtime, inode := statIdentity(info)
		file := ScannedFile{Path: name, Size: info.Size(), MtimeNs: mtime, Inode: inode,
			Mode: fmt.Sprintf("%04o", info.Mode().Perm())}
		if previous, ok := known[name]; ok && previous.SHA256 != "" && previous.Size == file.Size &&
			previous.MtimeNs == file.MtimeNs && previous.Inode == file.Inode {
			file.SHA256 = previous.SHA256
		} else {
			handle, err := root.OpenFile(name, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
			if err != nil {
				scan.Unreadable = append(scan.Unreadable, name)
				return nil
			}
			sha, size, err := hashFile(handle)
			handle.Close()
			if err != nil {
				scan.Unreadable = append(scan.Unreadable, name)
				return nil
			}
			file.SHA256, file.Size = sha, size
		}
		scan.Files = append(scan.Files, file)
		return nil
	})
	if walkErr != nil {
		return walkErr
	}
	return json.NewEncoder(os.Stdout).Encode(scan)
}

// checkExpected verifies the current state of a tree path: "-" anything, "absent" nothing there, else a regular file
// with that sha256.
func checkExpected(root *os.Root, relativePath, expect string) error {
	if expect == "-" {
		return nil
	}
	info, err := root.Lstat(relativePath)
	if expect == "absent" {
		if err == nil {
			return errTreeChanged
		}
		return nil
	}
	if err != nil || !info.Mode().IsRegular() {
		return errTreeChanged
	}
	file, err := root.OpenFile(relativePath, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return errTreeChanged
	}
	defer file.Close()
	sha, _, err := hashFile(file)
	if err != nil || sha != expect {
		return errTreeChanged
	}
	return nil
}

func treeAnswer(root *os.Root, relativePath, sha string) error {
	info, err := root.Lstat(relativePath)
	if err != nil {
		return err
	}
	mtime, inode := statIdentity(info)
	return json.NewEncoder(os.Stdout).Encode(map[string]any{"size": info.Size(), "mtimeNs": mtime, "inode": inode,
		"sha256": sha})
}

// helperDrivePut: drive-put <workspace> <path> <mode or -> <source sha256> <expect> — fd 3 is the blob (read-only).
// Writes an independent copy next to the target, checks its hash, renames it into place.
func helperDrivePut(workspace, relativePath, modeText, sourceSHA, expect string) error {
	source := os.NewFile(3, "source")
	if source == nil {
		return fmt.Errorf("no source descriptor")
	}
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	directory := path.Dir(relativePath)
	if directory != "." {
		if err := root.MkdirAll(directory, 0o755); err != nil {
			return err
		}
	}
	if err := checkExpected(root, relativePath, expect); err != nil {
		return err
	}
	mode := fs.FileMode(0o644)
	if modeText != "-" {
		if parsed, err := strconv.ParseUint(modeText, 8, 32); err == nil && parsed <= 0o777 {
			mode = fs.FileMode(parsed)
		}
	}
	temporary := path.Join(directory, helperTemporaryPrefix+randomSuffix())
	file, err := root.OpenFile(temporary, os.O_RDWR|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return err
	}
	_, copyErr := cloneOrCopy(file, source)
	sha, _, hashErr := hashFile(file)
	closeErr := file.Close()
	if err := errors.Join(copyErr, hashErr, closeErr); err != nil {
		root.Remove(temporary)
		return err
	}
	if sha != sourceSHA {
		root.Remove(temporary)
		return fmt.Errorf("copy hash mismatch")
	}
	if err := root.Chmod(temporary, mode); err != nil {
		root.Remove(temporary)
		return err
	}
	if err := root.Rename(temporary, relativePath); err != nil {
		root.Remove(temporary)
		return err
	}
	return treeAnswer(root, relativePath, sha)
}

// helperDriveLink: drive-link <workspace> <path> <target> <expect> — an in-space link node as a relative symlink.
func helperDriveLink(workspace, relativePath, target, expect string) error {
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	directory := path.Dir(relativePath)
	if directory != "." {
		if err := root.MkdirAll(directory, 0o755); err != nil {
			return err
		}
	}
	if err := checkExpected(root, relativePath, expect); err != nil {
		return err
	}
	temporary := path.Join(directory, helperTemporaryPrefix+randomSuffix())
	if err := root.Symlink(target, temporary); err != nil {
		return err
	}
	if err := root.Rename(temporary, relativePath); err != nil {
		root.Remove(temporary)
		return err
	}
	return json.NewEncoder(os.Stdout).Encode(map[string]any{"linkTarget": target})
}

// helperDriveRemove: drive-remove <workspace> <path> <expect> — removes the file only in the expected state, then
// the folders it leaves empty.
func helperDriveRemove(workspace, relativePath, expect string) error {
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	if info, err := root.Lstat(relativePath); err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return nil
		}
		return err
	} else if info.Mode()&fs.ModeSymlink == 0 {
		if err := checkExpected(root, relativePath, expect); err != nil {
			return err
		}
	}
	if err := root.Remove(relativePath); err != nil {
		return err
	}
	for directory := path.Dir(relativePath); directory != "." && directory != "/"; directory = path.Dir(directory) {
		if root.Remove(directory) != nil {
			break
		}
	}
	return nil
}

// helperDriveRename: drive-rename <workspace> <from> <to> — a move in the drive; refuses to replace a file.
func helperDriveRename(workspace, from, to string) error {
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	if _, err := root.Lstat(to); err == nil {
		return errTreeChanged
	}
	if directory := path.Dir(to); directory != "." {
		if err := root.MkdirAll(directory, 0o755); err != nil {
			return err
		}
	}
	return root.Rename(from, to)
}

// helperDriveSnapshot: drive-snapshot <workspace> <path> — copies the tree file into fd 3 (the runner's cache
// temporary, write-only for this process). The runner hashes what arrived and files it under that hash.
func helperDriveSnapshot(workspace, relativePath string) error {
	destination := os.NewFile(3, "destination")
	if destination == nil {
		return fmt.Errorf("no destination descriptor")
	}
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	file, err := root.OpenFile(relativePath, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return err
	}
	defer file.Close()
	info, err := file.Stat()
	if err != nil {
		return err
	}
	if !info.Mode().IsRegular() {
		return syscall.EISDIR
	}
	size, err := cloneOrCopy(destination, file)
	if err != nil {
		return err
	}
	return json.NewEncoder(os.Stdout).Encode(map[string]any{"size": size})
}

// looksBinary: a NUL byte in the first 8,000 bytes (git's own test).
func looksBinary(content []byte) bool {
	return bytes.IndexByte(content[:min(len(content), 8000)], 0) >= 0
}

func readDescriptor(fd uintptr, name string) ([]byte, error) {
	file := os.NewFile(fd, name)
	if file == nil {
		return nil, fmt.Errorf("no %s descriptor", name)
	}
	defer file.Close()
	return io.ReadAll(file)
}

// MergeOutcome is the answer of a merge.
type MergeOutcome struct {
	Result    string `json:"result"` // merged | conflict | binary
	Conflicts int    `json:"conflicts,omitempty"`
	SideFile  string `json:"sideFile,omitempty"`
	SHA256    string `json:"sha256,omitempty"`
	Size      int64  `json:"size,omitempty"`
	MtimeNs   int64  `json:"mtimeNs,omitempty"`
	Inode     uint64 `json:"inode,omitempty"`
}

// helperDriveMerge: drive-merge <workspace> <path> <expected sha256 of mine> <side file> — fd 3 base, fd 4 theirs.
// Runs `git merge-file` without a repository in a temporary folder below TMPDIR. Clean: the result replaces the tree
// file (mode kept). Overlapping: the tree stays as it is, the result with conflict markers goes to the side file.
func helperDriveMerge(workspace, relativePath, expectMine, sideFile string) error {
	base, err := readDescriptor(3, "base")
	if err != nil {
		return err
	}
	theirs, err := readDescriptor(4, "theirs")
	if err != nil {
		return err
	}
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	mineFile, err := root.OpenFile(relativePath, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return err
	}
	mine, err := io.ReadAll(mineFile)
	info, statErr := mineFile.Stat()
	mineFile.Close()
	if err := errors.Join(err, statErr); err != nil {
		return err
	}
	mineHash := sha256.Sum256(mine)
	if hex.EncodeToString(mineHash[:]) != expectMine {
		return errTreeChanged
	}
	encoder := json.NewEncoder(os.Stdout)
	if looksBinary(base) || looksBinary(mine) || looksBinary(theirs) {
		return encoder.Encode(MergeOutcome{Result: "binary"})
	}
	scratch, err := os.MkdirTemp(os.Getenv("TMPDIR"), "drive-merge-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(scratch)
	for name, content := range map[string][]byte{"mine": mine, "base": base, "theirs": theirs} {
		if err := os.WriteFile(filepath.Join(scratch, name), content, 0o600); err != nil {
			return err
		}
	}
	command := exec.Command("git", "merge-file", "-p", "-L", "mine", "-L", "base", "-L", "theirs", "mine", "base", "theirs")
	command.Dir = scratch
	var merged bytes.Buffer
	command.Stdout = &merged
	runErr := command.Run()
	conflicts := 0
	var exitErr *exec.ExitError
	if errors.As(runErr, &exitErr) {
		conflicts = exitErr.ExitCode()
		if conflicts < 0 || conflicts > 127 {
			return encoder.Encode(MergeOutcome{Result: "binary"})
		}
	} else if runErr != nil {
		return runErr
	}
	if conflicts > 0 {
		if err := root.MkdirAll(path.Dir(sideFile), 0o755); err != nil {
			return err
		}
		if err := root.WriteFile(sideFile, merged.Bytes(), 0o644); err != nil {
			return err
		}
		return encoder.Encode(MergeOutcome{Result: "conflict", Conflicts: conflicts, SideFile: sideFile})
	}
	temporary := path.Join(path.Dir(relativePath), helperTemporaryPrefix+randomSuffix())
	if err := root.WriteFile(temporary, merged.Bytes(), info.Mode().Perm()); err != nil {
		return err
	}
	if err := checkExpected(root, relativePath, expectMine); err != nil {
		root.Remove(temporary)
		return err
	}
	if err := root.Rename(temporary, relativePath); err != nil {
		root.Remove(temporary)
		return err
	}
	resultHash := sha256.Sum256(merged.Bytes())
	after, err := root.Lstat(relativePath)
	if err != nil {
		return err
	}
	mtime, inode := statIdentity(after)
	return encoder.Encode(MergeOutcome{Result: "merged", SHA256: hex.EncodeToString(resultHash[:]),
		Size: int64(merged.Len()), MtimeNs: mtime, Inode: inode})
}

// helperDriveDiff: drive-diff <workspace> <path> <has base: 1|0> — fd 3 the base when there is one. Prints the
// output of `git diff --no-index` between base and the tree file (either side may be missing).
func helperDriveDiff(workspace, relativePath, hasBase string) error {
	scratch, err := os.MkdirTemp(os.Getenv("TMPDIR"), "drive-diff-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(scratch)
	left, right := "/dev/null", "/dev/null"
	if hasBase == "1" {
		base, err := readDescriptor(3, "base")
		if err != nil {
			return err
		}
		left = filepath.Join("base", relativePath)
		if err := os.MkdirAll(filepath.Join(scratch, filepath.Dir(left)), 0o700); err != nil {
			return err
		}
		if err := os.WriteFile(filepath.Join(scratch, left), base, 0o600); err != nil {
			return err
		}
	}
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	if mine, err := root.ReadFile(relativePath); err == nil {
		right = filepath.Join("local", relativePath)
		if err := os.MkdirAll(filepath.Join(scratch, filepath.Dir(right)), 0o700); err != nil {
			return err
		}
		if err := os.WriteFile(filepath.Join(scratch, right), mine, 0o600); err != nil {
			return err
		}
	}
	command := exec.Command("git", "-c", "core.quotepath=off", "diff", "--no-index", "--no-color",
		"--src-prefix=", "--dst-prefix=", left, right)
	command.Dir = scratch
	command.Stdout = os.Stdout
	command.Stderr = os.Stderr
	if err := command.Run(); err != nil {
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) && exitErr.ExitCode() == 1 {
			return nil // differences found
		}
		return err
	}
	return nil
}
