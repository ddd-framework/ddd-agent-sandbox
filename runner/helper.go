package main

// The helper is the runner binary started again as `sandbox-runner __helper <op> …` under the session uid. The runner
// itself is root inside gVisor but holds only SETUID, SETGID and KILL: it cannot read, write or chown the session's
// files. Every operation on session files therefore runs in a helper process with the session's uid, which also means
// the kernel's own permission checks apply to it.

import (
	"bufio"
	"crypto/rand"
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
	"strconv"
	"strings"
	"syscall"
	"time"
)

// Exit codes of the helper, mapped to HTTP statuses by the runner.
const (
	helperExitOK          = 0
	helperExitFailed      = 1
	helperExitNotFound    = 2
	helperExitRefused     = 3
	helperExitNotRegular  = 4
	helperExitTooLarge    = 5
	helperExitNoSpace     = 6
	helperExitIncomplete  = 7
	helperArgument        = "__helper"
	rlimitNproc           = 6 // RLIMIT_NPROC on linux (amd64 and arm64)
	helperTemporaryPrefix = ".rbtmp-"
	helperSyncPrefix      = "sync="
	// helperNoSeccomp: the runner runs without the session filter (RUNNER_SESSION_SECCOMP=0, development only)
	helperNoSeccomp = "noseccomp"
)

// awaitRunner reads the one byte the runner writes into the sync descriptor once the process is adopted; EOF means
// the runner gave up on the process.
func awaitRunner(argument string) bool {
	descriptor, err := strconv.Atoi(strings.TrimPrefix(argument, helperSyncPrefix))
	if err != nil || descriptor < 3 {
		return false
	}
	file := os.NewFile(uintptr(descriptor), "sync")
	defer file.Close()
	buffer := make([]byte, 1)
	count, _ := file.Read(buffer)
	return count == 1
}

func helperExitCode(err error) int {
	var errno syscall.Errno
	switch {
	case err == nil:
		return helperExitOK
	case errors.Is(err, errTreeChanged):
		return helperExitChanged
	case errors.Is(err, errIncomplete):
		return helperExitIncomplete
	case errors.Is(err, fs.ErrNotExist):
		return helperExitNotFound
	case errors.As(err, &errno) && errno == syscall.EFBIG:
		return helperExitTooLarge
	case errors.As(err, &errno) && (errno == syscall.ENOSPC || errno == syscall.EDQUOT):
		return helperExitNoSpace
	case errors.As(err, &errno) && (errno == syscall.ELOOP || errno == syscall.EISDIR || errno == syscall.ENOTDIR):
		return helperExitNotRegular
	case errors.Is(err, fs.ErrPermission), strings.Contains(err.Error(), "escapes"):
		return helperExitRefused
	}
	return helperExitFailed
}

func helperFail(err error) {
	code := helperExitCode(err)
	if code == helperExitOK {
		code = helperExitFailed
	}
	// stderr of a helper is never logged by the runner (it may name files); it helps when running by hand
	fmt.Fprintln(os.Stderr, err)
	os.Exit(code)
}

// RunHelper executes one helper operation and exits.
func RunHelper(arguments []string) {
	filter := true
	for len(arguments) > 0 {
		if strings.HasPrefix(arguments[0], helperSyncPrefix) {
			// a session process waits here until the runner placed it in the session cgroup (memory.go)
			if !awaitRunner(arguments[0]) {
				os.Exit(helperExitFailed)
			}
		} else if arguments[0] == helperNoSeccomp {
			filter = false
		} else {
			break
		}
		arguments = arguments[1:]
	}
	if len(arguments) == 0 {
		os.Exit(helperExitFailed)
	}
	// every helper runs as the session uid and may execute the session's programs (bash, git): the filter comes first
	if filter {
		if err := applySessionSeccomp(); err != nil {
			helperFail(err)
		}
	}
	var err error
	switch arguments[0] {
	case "shell", "job":
		err = helperExecBash(arguments[0], arguments[1:])
	case "read":
		err = helperRead(arguments[1], arguments[2])
	case "write":
		err = helperWrite(arguments[1:])
	case "spill":
		err = helperSpill(arguments[1], arguments[2], arguments[3])
	case "manifest":
		err = helperManifest(arguments[1], len(arguments) > 2 && arguments[2] == "drive")
	case "wipe":
		err = helperWipe(arguments[1], arguments[2:])
	case "killall":
		err = helperKillAll()
	case "usage":
		err = helperUsage(arguments[1], arguments[2:])
	case "list":
		err = helperList(arguments[1], arguments[2], arguments[3])
	default:
		var known bool
		if known, err = RunDriveHelper(arguments); !known {
			err = fmt.Errorf("unknown helper operation")
		}
	}
	if err != nil {
		helperFail(err)
	}
	os.Exit(helperExitOK)
}

func parseUint(value string) uint64 {
	parsed, _ := strconv.ParseUint(value, 10, 64)
	return parsed
}

// setLimit lowers one rlimit (soft and hard); it never raises above the current hard limit.
func setLimit(resource int, value uint64) error {
	var current syscall.Rlimit
	if err := syscall.Getrlimit(resource, &current); err != nil {
		return err
	}
	if value > current.Max {
		value = current.Max
	}
	return syscall.Setrlimit(resource, &syscall.Rlimit{Cur: value, Max: value})
}

// applyLimits sets the per-exec rlimits: processes (per uid), open files, file size, no core files.
func applyLimits(processes, openFiles, fileBytes uint64) error {
	limits := []struct {
		resource int
		value    uint64
	}{
		{rlimitNproc, processes},
		{syscall.RLIMIT_NOFILE, openFiles},
		{syscall.RLIMIT_FSIZE, fileBytes},
		{syscall.RLIMIT_CORE, 0},
	}
	for _, limit := range limits {
		if err := setLimit(limit.resource, limit.value); err != nil {
			return fmt.Errorf("rlimit %d: %w", limit.resource, err)
		}
	}
	return nil
}

// helperExecBash: shell|job <workspace> <home> <cwd> <processes> <openFiles> <fileBytes> [command]
func helperExecBash(mode string, arguments []string) error {
	workspace, home, cwd := arguments[0], arguments[1], arguments[2]
	if err := applyLimits(parseUint(arguments[3]), parseUint(arguments[4]), parseUint(arguments[5])); err != nil {
		return err
	}
	syscall.Umask(0o022)
	if err := os.MkdirAll(home, 0o700); err != nil {
		return err
	}
	if err := os.Chdir(cwd); err != nil {
		if err := os.Chdir(workspace); err != nil {
			return err
		}
	}
	bashArguments := []string{"bash", "--noprofile", "--norc", "+m"}
	if mode == "job" {
		bashArguments = append(bashArguments, "-c", arguments[6])
	} else {
		bashArguments = append(bashArguments, "-s")
	}
	return syscall.Exec("/bin/bash", bashArguments, os.Environ())
}

// helperRead streams one regular file: a header line "OK <size>\n", then the bytes.
func helperRead(workspace, relativePath string) error {
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	file, err := root.OpenFile(relativePath, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return err
	}
	info, err := file.Stat()
	if err != nil {
		return err
	}
	if !info.Mode().IsRegular() {
		return syscall.EISDIR
	}
	output := bufio.NewWriterSize(os.Stdout, 256<<10)
	fmt.Fprintf(output, "OK %d\n", info.Size())
	if _, err := io.Copy(output, file); err != nil {
		return err
	}
	return output.Flush()
}

func randomSuffix() string {
	buffer := make([]byte, 8)
	rand.Read(buffer)
	return hex.EncodeToString(buffer)
}

// errIncomplete: the body was shorter or longer than announced; nothing is committed.
var errIncomplete = errors.New("incomplete body")

// helperWrite: write <workspace> <path> <mode or -> <mtime unix nanos or -> <fileBytes> <expected size> — stdin to a temporary file in the target
// folder, then renamed over the target. Answers {"size":…,"sha256":"…"} on stdout.
func helperWrite(arguments []string) error {
	workspace, relativePath, modeText, mtimeText := arguments[0], arguments[1], arguments[2], arguments[3]
	if err := setLimit(syscall.RLIMIT_FSIZE, parseUint(arguments[4])); err != nil {
		return err
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
	if info, err := root.Lstat(relativePath); err == nil && info.IsDir() {
		return syscall.EISDIR
	}
	temporary := path.Join(directory, helperTemporaryPrefix+randomSuffix())
	file, err := root.OpenFile(temporary, os.O_WRONLY|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0o644)
	if err != nil {
		return err
	}
	hash := sha256.New()
	size, copyErr := io.Copy(io.MultiWriter(file, hash), bufio.NewReaderSize(os.Stdin, 256<<10))
	closeErr := file.Close()
	if err := errors.Join(copyErr, closeErr); err != nil {
		root.Remove(temporary)
		return err
	}
	if expected, err := strconv.ParseInt(arguments[5], 10, 64); err != nil || expected != size {
		root.Remove(temporary)
		return errIncomplete
	}
	if modeText != "-" {
		mode, err := strconv.ParseUint(modeText, 8, 32)
		if err != nil || mode > 0o777 {
			root.Remove(temporary)
			return fmt.Errorf("mode: %w", fs.ErrInvalid)
		}
		if err := root.Chmod(temporary, fs.FileMode(mode)); err != nil {
			root.Remove(temporary)
			return err
		}
	}
	if mtimeText != "-" {
		nanos, err := strconv.ParseInt(mtimeText, 10, 64)
		if err != nil {
			root.Remove(temporary)
			return fmt.Errorf("mtime: %w", fs.ErrInvalid)
		}
		moment := time.Unix(0, nanos)
		if err := root.Chtimes(temporary, moment, moment); err != nil {
			root.Remove(temporary)
			return err
		}
	}
	if err := root.Rename(temporary, relativePath); err != nil {
		root.Remove(temporary)
		return err
	}
	return json.NewEncoder(os.Stdout).Encode(map[string]any{"size": size, "sha256": hex.EncodeToString(hash.Sum(nil))})
}

// helperSpill: spill <workspace> <path> <fileBytes> — stdin to the file (created or truncated); the folder is created.
func helperSpill(workspace, relativePath, fileBytes string) error {
	if err := setLimit(syscall.RLIMIT_FSIZE, parseUint(fileBytes)); err != nil {
		return err
	}
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	if err := root.MkdirAll(path.Dir(relativePath), 0o755); err != nil {
		return err
	}
	file, err := root.OpenFile(relativePath, os.O_WRONLY|os.O_CREATE|os.O_TRUNC|syscall.O_NOFOLLOW, 0o644)
	if err != nil {
		return err
	}
	_, copyErr := io.Copy(file, os.Stdin)
	return errors.Join(copyErr, file.Close())
}

// ManifestEntry is one regular file of the workspace.
type ManifestEntry struct {
	Path   string `json:"path"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256"`
	Mtime  string `json:"mtime"`
	Mode   string `json:"mode"`
}

// Manifest lists every regular file below the workspace, without the runner's folder.
type Manifest struct {
	Files           []ManifestEntry `json:"files"`
	Count           int             `json:"count"`
	TotalBytes      int64           `json:"totalBytes"`
	SkippedSymlinks int             `json:"skippedSymlinks"`
	SkippedOther    int             `json:"skippedOther"`
	Unreadable      []string        `json:"unreadable,omitempty"`
}

// helperManifest lists the workspace; in drive mode without the drive's own root entries (mounts, index).
func helperManifest(workspace string, drive bool) error {
	root, err := os.OpenRoot(workspace)
	if err != nil {
		return err
	}
	manifest := Manifest{Files: []ManifestEntry{}}
	walkErr := fs.WalkDir(root.FS(), ".", func(name string, entry fs.DirEntry, err error) error {
		if err != nil {
			if name != "." {
				manifest.Unreadable = append(manifest.Unreadable, name)
				return nil
			}
			return err
		}
		if entry.IsDir() {
			if name == RunnerDir || name == lostAndFound || drive && name == DriveSpacesDir {
				return fs.SkipDir
			}
			return nil
		}
		if drive && name == DriveIndexFile {
			return nil
		}
		switch {
		case entry.Type()&fs.ModeSymlink != 0:
			manifest.SkippedSymlinks++
			return nil
		case !entry.Type().IsRegular():
			manifest.SkippedOther++
			return nil
		}
		file, err := root.OpenFile(name, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
		if err != nil {
			manifest.Unreadable = append(manifest.Unreadable, name)
			return nil
		}
		defer file.Close()
		info, err := file.Stat()
		if err != nil || !info.Mode().IsRegular() {
			manifest.Unreadable = append(manifest.Unreadable, name)
			return nil
		}
		hash := sha256.New()
		size, err := io.Copy(hash, file)
		if err != nil {
			manifest.Unreadable = append(manifest.Unreadable, name)
			return nil
		}
		manifest.Files = append(manifest.Files, ManifestEntry{
			Path:   name,
			Size:   size,
			SHA256: hex.EncodeToString(hash.Sum(nil)),
			Mtime:  info.ModTime().UTC().Format(time.RFC3339Nano),
			Mode:   fmt.Sprintf("%04o", info.Mode().Perm()),
		})
		manifest.TotalBytes += size
		return nil
	})
	if walkErr != nil {
		return walkErr
	}
	manifest.Count = len(manifest.Files)
	return json.NewEncoder(os.Stdout).Encode(manifest)
}

// helperWipe removes the named entries of one directory. Directories the session made unreadable are opened up first
// (the owner may always chmod).
func helperWipe(directory string, names []string) error {
	var errs []error
	for _, name := range names {
		if name == "" || strings.Contains(name, "/") || name == "." || name == ".." {
			continue
		}
		target := filepath.Join(directory, name)
		filepath.WalkDir(target, func(current string, entry fs.DirEntry, err error) error {
			if err == nil && entry.IsDir() {
				os.Chmod(current, 0o700)
			}
			return nil
		})
		// a second pass: chmod above may have made folders readable that WalkDir could not enter
		filepath.WalkDir(target, func(current string, entry fs.DirEntry, err error) error {
			if err == nil && entry.IsDir() {
				os.Chmod(current, 0o700)
			}
			return nil
		})
		if err := os.RemoveAll(target); err != nil {
			errs = append(errs, err)
		}
	}
	return errors.Join(errs...)
}

// helperKillAll sends SIGKILL to every process of the helper's uid (kill(-1) as that uid) until /proc shows none
// alive. kill(-1) itself cannot tell: Linux answers success as long as any other process exists, even one it may not
// signal. Zombies count as gone (their parent or the init process reaps them).
func helperKillAll() error {
	for round := 0; round < 200; round++ {
		err := syscall.Kill(-1, syscall.SIGKILL)
		if errors.Is(err, syscall.ESRCH) {
			return nil
		}
		if err != nil && !errors.Is(err, syscall.EPERM) {
			return err
		}
		if len(livingProcessesOfUID(os.Getuid(), os.Getpid())) == 0 {
			return nil
		}
		time.Sleep(10 * time.Millisecond)
	}
	return fmt.Errorf("processes of the uid survived 200 kill rounds")
}

// livingProcessesOfUID lists the pids whose real uid is uid and that are not zombies, without self.
func livingProcessesOfUID(uid, self int) []int {
	var pids []int
	entries, err := os.ReadDir("/proc")
	if err != nil {
		return nil
	}
	uidPrefix := "Uid:\t" + strconv.Itoa(uid) + "\t"
	for _, entry := range entries {
		pid, err := strconv.Atoi(entry.Name())
		if err != nil || pid == self {
			continue
		}
		data, err := os.ReadFile("/proc/" + entry.Name() + "/status")
		if err != nil {
			continue
		}
		status := string(data)
		if !strings.Contains(status, "\n"+uidPrefix) || strings.Contains(status, "\nState:\tZ") {
			continue
		}
		pids = append(pids, pid)
	}
	return pids
}

// UsagePath is one of the largest files of a usage measurement.
type UsagePath struct {
	Path  string `json:"path"`
	Bytes int64  `json:"bytes"`
}

// Usage is the disk usage below the quota directories, as the quota counts it.
type Usage struct {
	TotalBytes  int64       `json:"totalBytes"`
	Largest     []UsagePath `json:"largest"`
	TargetBytes int64       `json:"targetBytes"`
	Unreadable  int         `json:"unreadable"`
	// ByDirectory: the usage per quota directory (HOME with its caches lives below /tmp and counts there)
	ByDirectory map[string]int64 `json:"byDirectory"`
}

// usageLargestCount is how many of the largest files a measurement names.
const usageLargestCount = 5

// fileUsage counts a file as the larger of its allocated blocks and its apparent size: written data counts even
// where a file system reports no blocks, and a sparse file counts at its full size (conservative towards eviction).
func fileUsage(info fs.FileInfo) int64 {
	usage := info.Size()
	if stat, ok := info.Sys().(*syscall.Stat_t); ok && stat.Blocks*512 > usage {
		usage = stat.Blocks * 512
	}
	return usage
}

// helperUsage: usage <target absolute path or -> <directory>… — sums every file below the directories once per inode
// (hard links count once) and names the largest. A folder the session closed for itself (no r or x for the owner)
// is opened for the walk and its mode restored afterwards.
func helperUsage(target string, directories []string) error {
	usage := Usage{Largest: []UsagePath{}, ByDirectory: map[string]int64{}}
	seen := map[[2]uint64]bool{}
	type restore struct {
		path string
		mode fs.FileMode
	}
	var restores []restore
	defer func() {
		for index := len(restores) - 1; index >= 0; index-- {
			os.Chmod(restores[index].path, restores[index].mode)
		}
	}()
	uid := uint32(os.Getuid())
	for _, directory := range directories {
		filepath.WalkDir(directory, func(current string, entry fs.DirEntry, err error) error {
			if err != nil {
				usage.Unreadable++
				return nil
			}
			info, err := entry.Info()
			if err != nil {
				usage.Unreadable++
				return nil
			}
			stat, _ := info.Sys().(*syscall.Stat_t)
			if entry.IsDir() && current == filepath.Join(directory, lostAndFound) && stat != nil && stat.Uid != uid {
				return fs.SkipDir
			}
			if entry.IsDir() {
				if stat != nil && stat.Uid == uid && info.Mode().Perm()&0o500 != 0o500 && current != directory {
					if os.Chmod(current, info.Mode().Perm()|0o700) == nil {
						restores = append(restores, restore{current, info.Mode().Perm()})
					}
				}
				return nil
			}
			if stat != nil {
				key := [2]uint64{uint64(stat.Dev), uint64(stat.Ino)}
				if seen[key] {
					return nil
				}
				seen[key] = true
			}
			bytes := fileUsage(info)
			usage.TotalBytes += bytes
			usage.ByDirectory[directory] += bytes
			if current == target {
				usage.TargetBytes = bytes
			}
			if !info.Mode().IsRegular() || bytes == 0 {
				return nil
			}
			usage.Largest = append(usage.Largest, UsagePath{Path: current, Bytes: bytes})
			sort.Slice(usage.Largest, func(left, right int) bool { return usage.Largest[left].Bytes > usage.Largest[right].Bytes })
			if len(usage.Largest) > usageLargestCount {
				usage.Largest = usage.Largest[:usageLargestCount]
			}
			return nil
		})
	}
	return json.NewEncoder(os.Stdout).Encode(usage)
}
