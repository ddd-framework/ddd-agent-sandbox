package main

import (
	"bufio"
	"bytes"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
)

// lostAndFound is created by mkfs.ext4 at the root of the workspace volume; it belongs to the file system, not to a
// session: the wipe keeps it, the manifest and the usage leave it out.
const lostAndFound = "lost+found"

// TidyLostAndFound removes the empty lost+found that mkfs.ext4 leaves at the root of the workspace volume: it is
// root-owned and closed (0700), so every recursive tool of the session (rg, find, du) failed on it. ext4 needs it only
// for fsck, and mkfs and fsck create it again when needed. A non-empty one (fsck recovered something) stays, closed to
// 0700; the wipe, the manifest and the usage leave it out. Answers whether a lost+found remains.
func TidyLostAndFound(workspace string, ownUID int) bool {
	target := filepath.Join(workspace, lostAndFound)
	info, err := os.Lstat(target)
	if err != nil || !info.IsDir() {
		return false
	}
	if stat, ok := info.Sys().(*syscall.Stat_t); !ok || int(stat.Uid) != ownUID {
		return true // not the file system's folder: the wipe treats it like any session entry
	}
	if os.Remove(target) == nil {
		return false
	}
	os.Chmod(target, 0o700)
	return true
}

// MountState describes the file system of one directory, for /healthz and the quota.
type MountState struct {
	MountPoint string `json:"mountPoint"`
	Type       string `json:"type"`
	// OwnMount: the directory is a mount point itself (its own volume), so statfs measures only it
	OwnMount  bool  `json:"ownMount"`
	SizeBytes int64 `json:"sizeBytes"`
	FreeBytes int64 `json:"freeBytes"`
	UsedBytes int64 `json:"usedBytes"`
}

// unescapeMountPath decodes the octal escapes of /proc/self/mountinfo (\040 for a space, \011, \012, \134).
func unescapeMountPath(value string) string {
	if !strings.Contains(value, `\`) {
		return value
	}
	var out strings.Builder
	for index := 0; index < len(value); index++ {
		if value[index] == '\\' && index+3 < len(value) {
			if code, err := strconv.ParseUint(value[index+1:index+4], 8, 8); err == nil {
				out.WriteByte(byte(code))
				index += 3
				continue
			}
		}
		out.WriteByte(value[index])
	}
	return out.String()
}

// parseMountInfo answers the file system type of the mount whose mount point is path (the last one wins: a later
// mount covers an earlier one), and whether there is one.
func parseMountInfo(data []byte, path string) (string, bool) {
	path = filepath.Clean(path)
	fsType, found := "", false
	scanner := bufio.NewScanner(bytes.NewReader(data))
	scanner.Buffer(make([]byte, 64<<10), 1<<20)
	for scanner.Scan() {
		// "36 35 98:0 /root /mount/point rw,noatime shared:1 - ext4 /dev/sda1 rw"
		line := scanner.Text()
		left, right, ok := strings.Cut(line, " - ")
		if !ok {
			continue
		}
		fields := strings.Fields(left)
		after := strings.Fields(right)
		if len(fields) < 5 || len(after) < 1 {
			continue
		}
		if filepath.Clean(unescapeMountPath(fields[4])) == path {
			fsType, found = after[0], true
		}
	}
	return fsType, found
}

// statfsBytes answers size, free (available to unprivileged writers) and used bytes of the file system of path.
func statfsBytes(path string) (size, free, used int64, err error) {
	var stat syscall.Statfs_t
	if err := syscall.Statfs(path, &stat); err != nil {
		return 0, 0, 0, err
	}
	blockSize := int64(stat.Bsize)
	return int64(stat.Blocks) * blockSize, int64(stat.Bavail) * blockSize,
		int64(stat.Blocks-stat.Bfree) * blockSize, nil
}

// InspectMount answers the mount state of directory.
func InspectMount(directory string) MountState {
	state := MountState{MountPoint: filepath.Clean(directory)}
	if data, err := os.ReadFile("/proc/self/mountinfo"); err == nil {
		state.Type, state.OwnMount = parseMountInfo(data, directory)
	}
	state.SizeBytes, state.FreeBytes, state.UsedBytes, _ = statfsBytes(directory)
	return state
}

// remainingBytes is what may still be written: the smaller of the quota's rest and the free space of the mount minus
// the reserve. reason names the limit that decides.
func remainingBytes(limit, usage, free, reserve int64) (int64, string) {
	byQuota := limit - usage
	bySpace := free - reserve
	if bySpace < byQuota {
		return bySpace, QuotaNoSpace
	}
	return byQuota, QuotaLimit
}

// effectiveLimit is the limit reported to the caller: the quota, or less when the mount cannot hold that much.
func effectiveLimit(limit, usage, free, reserve int64) int64 {
	return min(limit, usage+max(free-reserve, 0))
}

// quotaExceededBy answers the reason when usage or free space is past a limit, or "".
func quotaExceededBy(limit, usage, free, reserve int64) string {
	switch {
	case free < reserve:
		return QuotaNoSpace
	case usage > limit:
		return QuotaLimit
	}
	return ""
}
