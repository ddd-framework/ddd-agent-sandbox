package main

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

// Config holds every setting of the runner. All values come from the environment (RUNNER_*), with defaults that fit
// the pod of the README.
type Config struct {
	Port string
	// Workspace is the directory that every session sees as its workspace (an emptyDir, mode 0777).
	Workspace string
	// Home is HOME of every session process; it lives on the memory disk and is wiped at release.
	Home string
	// WipeDirs are further directories whose entries a session may have created (/tmp, /dev/shm); entries owned by a
	// session uid are removed at release.
	WipeDirs []string
	// UIDBase and UIDSpan: sessions get uids UIDBase … UIDBase+UIDSpan-1, a new one per session.
	UIDBase int
	UIDSpan int
	// Rlimits per exec.
	MaxProcesses  uint64
	MaxOpenFiles  uint64
	MaxFileBytes  uint64
	HeadChars     int
	TailChars     int
	SpillMemBytes int
	// Exec timeouts.
	DefaultTimeout    time.Duration
	MaxTimeout        time.Duration
	DefaultJobTimeout time.Duration
	MaxJobTimeout     time.Duration
	// KillGrace: after a timeout the runner kills the command's processes and waits this long for the shell to answer
	// before it kills the whole shell.
	KillGrace time.Duration
	// SessionIdle: a session without any call for this long is released by the runner itself.
	SessionIdle time.Duration
	MaxJobs     int
	MaxShells   int
	// MaxRestoreBytes caps the body of POST /v1/sessions (inline files).
	MaxRestoreBytes int64
	// Path is PATH of every session process.
	Path string
	// DefaultEnv is the environment every shell starts with, before the session's own env.
	DefaultEnv map[string]string
	// SessionPassEnv names variables of the runner's own environment (the image ENV) that every session gets: the
	// session never inherits the container environment, so whatever a tool needs at run time is passed by name.
	SessionPassEnv []string
	// QuotaBytes: the limit of the disk usage below QuotaDirs, safely below the emptyDir size limit (whose excess
	// evicts the pod). Measured every QuotaInterval while an exec or a job runs, and before every PUT.
	QuotaBytes    int64
	QuotaDirs     []string
	QuotaInterval time.Duration
	// FreeReserve: the workspace mount's free space is a second limit; below this reserve it counts as full
	FreeReserve int64
	// QuotaWatchMode: auto (exact walk, statfs once a walk takes longer than QuotaExactBudget), exact or statfs.
	// statfs needs every quota directory to be its own mount.
	QuotaWatchMode   string
	QuotaExactBudget time.Duration
	// QuotaRecalibrateTicks: in statfs mode, every this many measuring ticks an exact walk resets the estimate's offset
	QuotaRecalibrateTicks int
	// RequireWorkspaceMount: the runner refuses to start when the workspace is not its own mount (a volume)
	RequireWorkspaceMount bool
	// Memory per session (memory.go): above MemoryLimitBytes every process of the session ends (0: off). Measured every
	// MemoryInterval through a cgroup of the session below CgroupRoot, or as the RSS sum of the session uid
	// (MemoryMode auto, rss or off).
	MemoryLimitBytes int64
	MemoryInterval   time.Duration
	MemoryMode       string
	CgroupRoot       string
	// SessionSeccomp: every session process runs under the seccomp filter of seccomp.go (RUNNER_SESSION_SECCOMP=0 for
	// development only)
	SessionSeccomp bool
	// Drive mode (README "Drive mode"): root-only state (base map, hash cache) outside the workspace, the socket of the
	// drive CLI, and the defaults a claim may override.
	DriveStateDir          string
	DriveSocket            string
	DriveThresholdBytes    int64
	DriveMirrorBudgetBytes int64
	DriveMountTextMaxBytes int64
	DriveMergeMaxBytes     int64
	DrivePauseLimit        time.Duration
	DriveTransferWorkers   int
	// DriveFuse: mounts are served by the placeholder FUSE in a nested user namespace (fuse.go); off or failing: Plan
	// B. FuseHolderUID is the outer uid of the namespace's root; NsenterPath starts session processes inside it.
	DriveFuse     bool
	FuseHolderUID int
	NsenterPath   string
	// FuseRoot: where the holder mounts the file system in its namespace (root-owned, outside /workspace)
	FuseRoot string
	// TransferProxyURL and TransferProxyToken: the in-cluster transfer proxy for presigned GET and PUT. The token
	// comes from a root-only file (RUNNER_TRANSFER_PROXY_TOKEN_FILE); it never reaches a session (no env, no argv).
	TransferProxyURL   string
	TransferProxyToken string
}

func splitList(value string) []string {
	var items []string
	for _, item := range strings.Split(value, ",") {
		if item = strings.TrimSpace(item); item != "" {
			items = append(items, item)
		}
	}
	return items
}

func envString(name, fallback string) string {
	if value, ok := os.LookupEnv(name); ok && value != "" {
		return value
	}
	return fallback
}

func envInt(name string, fallback int) (int, error) {
	value, ok := os.LookupEnv(name)
	if !ok || value == "" {
		return fallback, nil
	}
	parsed, err := strconv.Atoi(value)
	if err != nil || parsed < 0 {
		return 0, fmt.Errorf("%s must be a non-negative integer", name)
	}
	return parsed, nil
}

// LoadConfig reads the configuration from the environment.
func LoadConfig() (Config, error) {
	config := Config{
		Port:      envString("PORT", "8080"),
		Workspace: envString("RUNNER_WORKSPACE", "/workspace"),
		Home:      envString("RUNNER_HOME", "/tmp/home"),
		Path:      envString("RUNNER_SESSION_PATH", "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"),
	}
	config.WipeDirs = splitList(envString("RUNNER_WIPE_DIRS", "/tmp,/dev/shm"))
	config.QuotaDirs = splitList(envString("RUNNER_QUOTA_DIRS", config.Workspace+",/tmp"))
	config.SessionPassEnv = splitList(envString("RUNNER_SESSION_PASS_ENV", "PLAYWRIGHT_BROWSERS_PATH,BROWSER_PATH"))
	integers := []struct {
		name     string
		fallback int
		target   *int
	}{
		{"RUNNER_UID_BASE", 20000, &config.UIDBase},
		{"RUNNER_UID_SPAN", 40000, &config.UIDSpan},
		{"RUNNER_OUTPUT_HEAD_CHARS", 2000, &config.HeadChars},
		{"RUNNER_OUTPUT_TAIL_CHARS", 2000, &config.TailChars},
		{"RUNNER_SPILL_MEMORY_BYTES", 1 << 20, &config.SpillMemBytes},
		{"RUNNER_MAX_JOBS", 8, &config.MaxJobs},
		{"RUNNER_MAX_SHELLS", 4, &config.MaxShells},
	}
	for _, entry := range integers {
		value, err := envInt(entry.name, entry.fallback)
		if err != nil {
			return config, err
		}
		*entry.target = value
	}
	unsigned := []struct {
		name     string
		fallback int
		target   *uint64
	}{
		{"RUNNER_MAX_PROCESSES", 512, &config.MaxProcesses},
		{"RUNNER_MAX_OPEN_FILES", 4096, &config.MaxOpenFiles},
		{"RUNNER_MAX_FILE_BYTES", 1 << 30, &config.MaxFileBytes},
	}
	for _, entry := range unsigned {
		value, err := envInt(entry.name, entry.fallback)
		if err != nil {
			return config, err
		}
		*entry.target = uint64(value)
	}
	durations := []struct {
		name     string
		fallback int
		target   *time.Duration
	}{
		{"RUNNER_DEFAULT_TIMEOUT_SECONDS", 60, &config.DefaultTimeout},
		{"RUNNER_MAX_TIMEOUT_SECONDS", 180, &config.MaxTimeout},
		{"RUNNER_DEFAULT_JOB_TIMEOUT_SECONDS", 600, &config.DefaultJobTimeout},
		{"RUNNER_MAX_JOB_TIMEOUT_SECONDS", 1800, &config.MaxJobTimeout},
		{"RUNNER_KILL_GRACE_SECONDS", 3, &config.KillGrace},
		{"RUNNER_SESSION_IDLE_SECONDS", 7200, &config.SessionIdle},
	}
	for _, entry := range durations {
		value, err := envInt(entry.name, entry.fallback)
		if err != nil {
			return config, err
		}
		*entry.target = time.Duration(value) * time.Second
	}
	quotaBytes, err := envInt("RUNNER_WORKSPACE_LIMIT_BYTES", 3865470566) // 3.6 GiB, of a 4Gi workspace volume
	if err != nil {
		return config, err
	}
	config.QuotaBytes = int64(quotaBytes)
	quotaInterval, err := envInt("RUNNER_QUOTA_INTERVAL_MS", 1000)
	if err != nil || quotaInterval < 50 {
		return config, fmt.Errorf("RUNNER_QUOTA_INTERVAL_MS must be at least 50")
	}
	config.QuotaInterval = time.Duration(quotaInterval) * time.Millisecond
	freeReserve, err := envInt("RUNNER_WORKSPACE_FREE_RESERVE_BYTES", 16<<20)
	if err != nil {
		return config, err
	}
	config.FreeReserve = int64(freeReserve)
	config.QuotaWatchMode = envString("RUNNER_QUOTA_WATCH_MODE", "auto")
	if config.QuotaWatchMode != "auto" && config.QuotaWatchMode != "exact" && config.QuotaWatchMode != "statfs" {
		return config, fmt.Errorf("RUNNER_QUOTA_WATCH_MODE must be auto, exact or statfs")
	}
	exactBudget, err := envInt("RUNNER_QUOTA_EXACT_BUDGET_MS", 500)
	if err != nil {
		return config, err
	}
	config.QuotaExactBudget = time.Duration(exactBudget) * time.Millisecond
	if config.QuotaRecalibrateTicks, err = envInt("RUNNER_QUOTA_RECALIBRATE_TICKS", 10); err != nil {
		return config, err
	}
	config.RequireWorkspaceMount = envString("RUNNER_REQUIRE_WORKSPACE_MOUNT", "1") != "0"
	// 2 GiB of the pod's 3Gi: the rest holds the runner, the Sentry and the /tmp memory disk (256Mi)
	memoryLimit, err := envInt("RUNNER_SESSION_MEMORY_LIMIT_BYTES", 2<<30)
	if err != nil {
		return config, err
	}
	config.MemoryLimitBytes = int64(memoryLimit)
	memoryInterval, err := envInt("RUNNER_SESSION_MEMORY_INTERVAL_MS", 50)
	if err != nil || memoryInterval < 20 {
		return config, fmt.Errorf("RUNNER_SESSION_MEMORY_INTERVAL_MS must be at least 20")
	}
	config.MemoryInterval = time.Duration(memoryInterval) * time.Millisecond
	config.MemoryMode = envString("RUNNER_SESSION_MEMORY_MODE", "auto")
	if config.MemoryMode != "auto" && config.MemoryMode != MemoryRSS && config.MemoryMode != MemoryOff {
		return config, fmt.Errorf("RUNNER_SESSION_MEMORY_MODE must be auto, rss or off")
	}
	config.CgroupRoot = envString("RUNNER_CGROUP_ROOT", "/sys/fs/cgroup")
	config.SessionSeccomp = envString("RUNNER_SESSION_SECCOMP", "1") != "0"
	restoreBytes, err := envInt("RUNNER_MAX_RESTORE_BYTES", 64<<20)
	if err != nil {
		return config, err
	}
	config.MaxRestoreBytes = int64(restoreBytes)
	if config.UIDBase < 1000 || config.UIDSpan < 1 {
		return config, fmt.Errorf("RUNNER_UID_BASE must be at least 1000 and RUNNER_UID_SPAN at least 1")
	}
	config.DefaultEnv = map[string]string{
		"LANG":   envString("RUNNER_SESSION_LANG", "C.UTF-8"),
		"TZ":     envString("RUNNER_SESSION_TZ", "UTC"),
		"SHELL":  "/bin/bash",
		"TMPDIR": "/tmp",
	}
	for _, name := range config.SessionPassEnv {
		if strings.HasPrefix(name, "RUNNER_") {
			return config, fmt.Errorf("RUNNER_SESSION_PASS_ENV must not name runner settings")
		}
		if value := os.Getenv(name); value != "" && ValidEnvName(name) {
			config.DefaultEnv[name] = value
		}
	}
	return config, loadDriveConfig(&config)
}

func loadDriveConfig(config *Config) error {
	config.DriveStateDir = envString("RUNNER_DRIVE_STATE_DIR", "/tmp/.drive-state")
	config.DriveSocket = envString("RUNNER_DRIVE_SOCKET", "/tmp/.drive.sock")
	sizes := []struct {
		name     string
		fallback int
		target   *int64
	}{
		{"RUNNER_DRIVE_THRESHOLD_BYTES", 20 << 20, &config.DriveThresholdBytes},
		{"RUNNER_DRIVE_MIRROR_BUDGET_BYTES", 1536 << 20, &config.DriveMirrorBudgetBytes},
		{"RUNNER_DRIVE_MOUNT_TEXT_MAX_BYTES", 256 << 10, &config.DriveMountTextMaxBytes},
		{"RUNNER_DRIVE_MERGE_MAX_BYTES", 4 << 20, &config.DriveMergeMaxBytes},
	}
	for _, entry := range sizes {
		value, err := envInt(entry.name, entry.fallback)
		if err != nil {
			return err
		}
		*entry.target = int64(value)
	}
	pause, err := envInt("RUNNER_DRIVE_PAUSE_SECONDS", 300)
	if err != nil {
		return err
	}
	config.DrivePauseLimit = time.Duration(pause) * time.Second
	if config.DriveTransferWorkers, err = envInt("RUNNER_DRIVE_TRANSFER_WORKERS", 4); err != nil {
		return err
	}
	config.DriveTransferWorkers = max(config.DriveTransferWorkers, 1)
	config.DriveFuse = envString("RUNNER_DRIVE_FUSE", "1") != "0"
	if config.FuseHolderUID, err = envInt("RUNNER_FUSE_HOLDER_UID", 100000); err != nil {
		return err
	}
	config.NsenterPath = envString("RUNNER_NSENTER", "/usr/bin/nsenter")
	config.FuseRoot = envString("RUNNER_FUSE_ROOT", "/tmp/.sandbox-fuse")
	if config.FuseHolderUID >= config.UIDBase && config.FuseHolderUID < config.UIDBase+config.UIDSpan || config.FuseHolderUID == 0 {
		return fmt.Errorf("RUNNER_FUSE_HOLDER_UID must lie outside the session uid range and must not be 0")
	}
	config.TransferProxyURL = envString("RUNNER_TRANSFER_PROXY_URL", "")
	// the token: a root-only file (RUNNER_TRANSFER_PROXY_TOKEN_FILE, root:root 0400 on the cluster), read into the
	// runner's memory only; RUNNER_TRANSFER_PROXY_TOKEN is the local-development fallback
	if file := envString("RUNNER_TRANSFER_PROXY_TOKEN_FILE", ""); file != "" {
		content, err := os.ReadFile(file)
		if err != nil {
			return fmt.Errorf("RUNNER_TRANSFER_PROXY_TOKEN_FILE cannot be read")
		}
		config.TransferProxyToken = strings.TrimSpace(string(content))
	} else {
		config.TransferProxyToken = envString("RUNNER_TRANSFER_PROXY_TOKEN", "")
	}
	os.Unsetenv("RUNNER_TRANSFER_PROXY_TOKEN")
	return nil
}
