// sandbox-runner: one slot of the agent sandbox of mgamadeus/ddd-agent-sandbox (README.md).
// It serves the runner contract on PORT (8080): a session per workspace key with a fresh unprivileged uid, persistent bash
// shells, background jobs, file streaming confined to /workspace, a content-hash manifest, and kill plus wipe at
// release. It holds no credentials, calls nothing and needs no network.
package main

import (
	"context"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"
)

func main() {
	if len(os.Args) > 1 && os.Args[1] == helperArgument {
		RunHelper(os.Args[2:])
		return
	}
	if len(os.Args) > 1 && os.Args[1] == fuseHolderArgument {
		RunFuseHolder(os.Args[2:])
		return
	}
	if filepath.Base(os.Args[0]) == "drive" {
		RunDriveCLI(os.Args[1:])
		return
	}
	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	config, err := LoadConfig()
	if err != nil {
		logger.Error("configuration invalid", "error", err.Error())
		os.Exit(2)
	}
	executable, err := os.Executable()
	if err != nil {
		logger.Error("cannot resolve own executable", "error", err.Error())
		os.Exit(2)
	}
	privileged := os.Geteuid() == 0
	if !privileged && os.Getenv("RUNNER_ALLOW_UNPRIVILEGED") != "1" {
		logger.Error("the runner must run as root (with SETUID, SETGID and KILL) to give each session its own uid; " +
			"set RUNNER_ALLOW_UNPRIVILEGED=1 for local development only")
		os.Exit(2)
	}
	if err := os.MkdirAll(config.Workspace, 0o777); err != nil {
		logger.Error("workspace missing", "error", err.Error())
		os.Exit(2)
	}
	// the session uid must be able to create files in the workspace; root owns it (the volume) and has no CAP_CHOWN
	os.Chmod(config.Workspace, 0o777)
	// the workspace must be its own volume: its free space is the hard limit, and statfs measures only it
	mount := InspectMount(config.Workspace)
	if !mount.OwnMount && config.RequireWorkspaceMount {
		logger.Error("the workspace is not its own mount; mount a volume there or set RUNNER_REQUIRE_WORKSPACE_MOUNT=0 "+
			"for local development", "workspace", config.Workspace)
		os.Exit(2)
	}
	logger.Info("workspace mount", "type", mount.Type, "ownMount", mount.OwnMount, "sizeBytes", mount.SizeBytes,
		"freeBytes", mount.FreeBytes)

	runner := NewRunner(config, executable, privileged, logger)
	if runner.transfer, err = newTransferClient(config); err != nil {
		logger.Error("configuration invalid", "error", err.Error())
		os.Exit(2)
	}
	// a container restart keeps the emptyDir: wipe what an earlier process left
	if err := runner.Wipe(); err != nil {
		runner.state = StateBroken
		logger.Error("startup wipe failed; runner takes no sessions", "state", runner.state)
	}

	server := &http.Server{
		Addr:              ":" + config.Port,
		Handler:           (&API{runner: runner}).Handler(),
		ReadHeaderTimeout: 10 * time.Second,
		IdleTimeout:       120 * time.Second,
	}
	go func() {
		ticker := time.NewTicker(15 * time.Second)
		for range ticker.C {
			runner.ReapIdle()
		}
	}()
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGTERM, syscall.SIGINT)
	go func() {
		<-stop
		logger.Info("shutting down", "state", runner.State())
		runner.mutex.Lock()
		session := runner.session
		runner.mutex.Unlock()
		if session != nil {
			runner.Release(session.ID, "shutdown")
		}
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		server.Shutdown(ctx)
	}()
	logger.Info("runner started", "port", config.Port, "privileged", privileged, "state", runner.State(),
		"uidBase", config.UIDBase, "uidSpan", config.UIDSpan, "quotaBytes", config.QuotaBytes,
		"quotaWatchMode", config.QuotaWatchMode, "quotaMountsOwn", runner.quotaMountsOwn,
		"transferProxy", runner.transfer != nil, "memoryMeasure", runner.memory.mode,
		"memoryLimitBytes", config.MemoryLimitBytes, "sessionSeccomp", config.SessionSeccomp)
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		logger.Error("server failed", "error", err.Error())
		os.Exit(1)
	}
}
