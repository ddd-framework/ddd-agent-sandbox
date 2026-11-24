package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// API serves the runner contract (README; the version is the package version, contractVersion).
type API struct {
	runner *Runner
}

// Handler answers the HTTP handler with every route.
func (api *API) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", api.health)
	mux.HandleFunc("POST /v1/sessions", api.createSession)
	mux.HandleFunc("DELETE /v1/sessions/{id}", api.deleteSession)
	mux.HandleFunc("POST /v1/sessions/{id}/exec", api.exec)
	mux.HandleFunc("GET /v1/sessions/{id}/jobs/{job}", api.getJob)
	mux.HandleFunc("DELETE /v1/sessions/{id}/jobs/{job}", api.deleteJob)
	mux.HandleFunc("GET /v1/sessions/{id}/files/{path...}", api.readFile)
	mux.HandleFunc("PUT /v1/sessions/{id}/files/{path...}", api.writeFile)
	mux.HandleFunc("GET /v1/sessions/{id}/manifest", api.manifest)
	// administration reads (admin.go)
	mux.HandleFunc("GET /v1/sessions", api.listSessions)
	mux.HandleFunc("GET /v1/sessions/{id}/processes", api.listProcesses)
	mux.HandleFunc("GET /v1/sessions/{id}/execs", api.listExecs)
	mux.HandleFunc("GET /v1/sessions/{id}/events", api.listEvents)
	mux.HandleFunc("GET /v1/sessions/{id}/disk", api.readDisk)
	mux.HandleFunc("GET /v1/sessions/{id}/files", api.listFiles)
	api.driveRoutes(mux)
	return mux
}

func writeJSON(writer http.ResponseWriter, status int, value any) {
	writer.Header().Set("Content-Type", "application/json")
	writer.WriteHeader(status)
	json.NewEncoder(writer).Encode(value)
}

// writeError answers {"error": CODE, "message": …}. Messages never contain commands, output or file names.
func writeError(writer http.ResponseWriter, status int, code, message string) {
	writeJSON(writer, status, map[string]string{"error": code, "message": message})
}

func (api *API) health(writer http.ResponseWriter, _ *http.Request) {
	state := api.runner.State()
	status := http.StatusOK
	if state == StateBroken {
		status = http.StatusServiceUnavailable
	}
	mount := api.runner.WorkspaceMount()
	config := api.runner.config
	writeJSON(writer, status, map[string]any{
		"status": map[bool]string{true: "ok", false: "broken"}[status == http.StatusOK], "state": state,
		// the package version this binary was built for; PHP compares it (SandboxRunnerContract)
		"contractVersion": contractVersion,
		"toolkit":         readImageFact(toolkitFile, "unknown"),
		// the hash of the project's image.yaml the image was built from ("" for the module's own image)
		"imageConfigHash": readImageFact(imageConfigHashFile, ""),
		"capabilities": map[string]bool{"drive": true, "fuse": api.runner.fuseAvailable(),
			"transferProxy": api.runner.transfer != nil},
		"limits": map[string]any{"quotaBytes": config.QuotaBytes, "memoryBytes": config.MemoryLimitBytes,
			"maxProcesses": config.MaxProcesses, "maxShells": config.MaxShells, "maxJobs": config.MaxJobs,
			"defaultTimeoutSeconds": int(config.DefaultTimeout.Seconds()), "maxTimeoutSeconds": int(config.MaxTimeout.Seconds()),
			"maxJobTimeoutSeconds": int(config.MaxJobTimeout.Seconds()), "sessionIdleSeconds": int(config.SessionIdle.Seconds())},
		"workspaceMount": map[string]any{"type": mount.Type, "ownMount": mount.OwnMount, "sizeBytes": mount.SizeBytes,
			"freeBytes": mount.FreeBytes},
		"memory": map[string]any{"measure": api.runner.memory.mode, "limitBytes": config.MemoryLimitBytes},
	})
}

// session resolves {id} and marks the call in flight; on failure it has answered already.
func (api *API) session(writer http.ResponseWriter, request *http.Request) (*Session, func(), bool) {
	session, err := api.runner.Session(request.PathValue("id"))
	if err != nil {
		writeError(writer, http.StatusNotFound, "SESSION_NOT_FOUND", "no such session on this runner")
		return nil, nil, false
	}
	return session, session.Begin(), true
}

type createSessionRequest struct {
	WorkspaceKey string            `json:"workspaceKey"`
	Env          map[string]string `json:"env,omitempty"`
	Files        []RestoreFile     `json:"files,omitempty"`
	// Mode: "legacy" (default: manifest and restore) or "drive" (README "Drive mode"), with its Drive block
	Mode  string      `json:"mode,omitempty"`
	Drive *DriveClaim `json:"drive,omitempty"`
}

func (api *API) createSession(writer http.ResponseWriter, request *http.Request) {
	var body createSessionRequest
	decoder := json.NewDecoder(http.MaxBytesReader(writer, request.Body, api.runner.config.MaxRestoreBytes))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&body); err != nil {
		writeError(writer, http.StatusBadRequest, "INVALID_BODY", "body must be JSON {workspaceKey, env?, files?}")
		return
	}
	driveMode := body.Mode == "drive"
	if body.Mode != "" && body.Mode != "legacy" && !driveMode || driveMode && (body.Drive == nil || len(body.Files) > 0) {
		writeError(writer, http.StatusBadRequest, "INVALID_MODE", "mode is legacy or drive; drive needs the drive block and takes no inline files")
		return
	}
	if body.WorkspaceKey == "" || len(body.WorkspaceKey) > 256 {
		writeError(writer, http.StatusBadRequest, "INVALID_WORKSPACE_KEY", "workspaceKey is required (at most 256 characters)")
		return
	}
	for name, value := range body.Env {
		if !ValidEnvName(name) || strings.ContainsRune(value, 0) {
			writeError(writer, http.StatusBadRequest, "INVALID_ENV", "env names must be plain identifiers; BASH_ENV, ENV, HOME, PATH, LD_* and similar are refused")
			return
		}
	}
	paths := make([]string, len(body.Files))
	var restoreBytes int64
	for _, file := range body.Files {
		restoreBytes += int64(len(file.ContentBase64))
	}
	if restoreBytes > api.runner.config.QuotaBytes {
		writeJSON(writer, http.StatusRequestEntityTooLarge, map[string]any{
			"error": "QUOTA_EXCEEDED", "message": "the inline files exceed the workspace limit",
			"totalBytes": restoreBytes, "limitBytes": api.runner.config.QuotaBytes,
		})
		return
	}
	for index, file := range body.Files {
		clean, err := CleanRelativePath(file.Path)
		if err != nil {
			writeError(writer, http.StatusBadRequest, "PATH_REFUSED", "files["+strconv.Itoa(index)+"].path is not a relative workspace path")
			return
		}
		paths[index] = clean
	}
	started := time.Now()
	session, err := api.runner.Claim(body.WorkspaceKey, body.Env)
	if err != nil {
		code := "RUNNER_BUSY"
		if errors.Is(err, ErrRunnerBroken) {
			code = "RUNNER_BROKEN"
		}
		writer.Header().Set("Retry-After", "2")
		writeError(writer, http.StatusServiceUnavailable, code, "this runner holds another session")
		return
	}
	for index, file := range body.Files {
		status, code, _ := api.writeSessionFile(session, paths[index], bytes.NewReader(file.ContentBase64), int64(len(file.ContentBase64)), file.Mode, file.Mtime)
		if code == "QUOTA_EXCEEDED" {
			report := session.CheckQuotaAfter(true)
			api.runner.Release(session.ID, "restore failed")
			writeJSON(writer, status, map[string]any{"error": code, "message": "the workspace is full; the session was released",
				"reason": report.Reason, "totalBytes": report.TotalBytes, "limitBytes": report.LimitBytes,
				"byDirectory": report.ByDirectory, "largest": report.Largest})
			return
		}
		if status != http.StatusOK {
			api.runner.Release(session.ID, "restore failed")
			writeError(writer, status, code, "restoring files["+strconv.Itoa(index)+"] failed; the session was released")
			return
		}
	}
	answer := map[string]any{
		"sessionId": session.ID, "uid": session.UID, "workspace": api.runner.config.Workspace,
		"restoredFiles": len(body.Files), "mode": "legacy",
	}
	if driveMode {
		session.env = append(session.env, "DRIVE_SOCKET="+api.runner.config.DriveSocket)
		result, err := session.initDrive(*body.Drive)
		if err == nil {
			err = session.serveDriveSocket()
		}
		if err != nil {
			api.runner.Release(session.ID, "drive setup failed")
			message := "the drive block was refused; the session was released"
			if strings.HasPrefix(err.Error(), "drive.") {
				message = err.Error() + "; the session was released"
			}
			writeError(writer, http.StatusBadRequest, "DRIVE_SETUP_FAILED", message)
			return
		}
		answer["mode"], answer["drive"], answer["transfer"] = "drive", result, session.drive.Transfer
	}
	claimDetail := "mode " + answer["mode"].(string)
	if session.drive != nil {
		claimDetail += ", mounts " + strconv.Itoa(len(session.drive.Mounts)) + ", " + session.drive.MountFilesystem
	}
	session.history.event(EventSessionClaimed, claimDetail)
	api.runner.logger.Info("session claimed", "session", session.ID, "uid", session.UID,
		"workspaceKey", session.WorkspaceKey, "restoredFiles", len(body.Files), "mode", answer["mode"],
		"durationMs", time.Since(started).Milliseconds())
	writeJSON(writer, http.StatusCreated, answer)
}

func (api *API) deleteSession(writer http.ResponseWriter, request *http.Request) {
	started := time.Now()
	err := api.runner.Release(request.PathValue("id"), "deleted")
	switch {
	case errors.Is(err, ErrSessionNotFound):
		writeError(writer, http.StatusNotFound, "SESSION_NOT_FOUND", "no such session on this runner")
	case err != nil:
		writeError(writer, http.StatusInternalServerError, "WIPE_FAILED", "the runner could not wipe the session and takes no further sessions")
	default:
		writeJSON(writer, http.StatusOK, map[string]any{"released": true, "durationMs": time.Since(started).Milliseconds()})
	}
}

type execRequest struct {
	Command        string `json:"command"`
	Cwd            string `json:"cwd,omitempty"`
	TimeoutSeconds *int   `json:"timeoutSeconds,omitempty"`
	Background     bool   `json:"background,omitempty"`
	Shell          string `json:"shell,omitempty"`
}

func (api *API) exec(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.session(writer, request)
	if !ok {
		return
	}
	defer end()
	var body execRequest
	decoder := json.NewDecoder(http.MaxBytesReader(writer, request.Body, 1<<20))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&body); err != nil {
		writeError(writer, http.StatusBadRequest, "INVALID_BODY", "body must be JSON {command, cwd?, timeoutSeconds?, background?, shell?}")
		return
	}
	if strings.TrimSpace(body.Command) == "" || strings.ContainsRune(body.Command, 0) {
		writeError(writer, http.StatusBadRequest, "INVALID_COMMAND", "command must be non-empty text without NUL bytes")
		return
	}
	if body.Shell == "" {
		body.Shell = "default"
	}
	if !ValidShellName(body.Shell) {
		writeError(writer, http.StatusBadRequest, "INVALID_SHELL", "shell must match [A-Za-z0-9_-]{1,64}")
		return
	}
	config := api.runner.config
	defaultTimeout, maxTimeout := config.DefaultTimeout, config.MaxTimeout
	if body.Background {
		defaultTimeout, maxTimeout = config.DefaultJobTimeout, config.MaxJobTimeout
	}
	timeout := defaultTimeout
	if body.TimeoutSeconds != nil {
		timeout = time.Duration(*body.TimeoutSeconds) * time.Second
		if *body.TimeoutSeconds < 1 || timeout > maxTimeout {
			writeError(writer, http.StatusBadRequest, "INVALID_TIMEOUT",
				"timeoutSeconds must be between 1 and "+strconv.Itoa(int(maxTimeout/time.Second)))
			return
		}
	}
	cwd, err := session.ResolveCwd(body.Cwd)
	if err != nil {
		writeError(writer, http.StatusBadRequest, "INVALID_CWD", "cwd must be a path without NUL bytes")
		return
	}
	if body.Background {
		job, err := session.StartJob(body.Shell, body.Command, cwd, timeout)
		if err != nil {
			writeError(writer, http.StatusConflict, "JOB_NOT_STARTED", "the job could not start (too many running jobs or session ending)")
			return
		}
		writeJSON(writer, http.StatusAccepted, map[string]any{"jobId": job.ID, "state": JobRunning})
		return
	}
	result, err := session.Exec(body.Shell, body.Command, cwd, timeout)
	switch {
	case errors.Is(err, ErrShellBusy):
		writeError(writer, http.StatusConflict, "SHELL_BUSY", "a command already runs in this shell; use another shell or background")
	case errors.Is(err, ErrSessionNotFound):
		writeError(writer, http.StatusNotFound, "SESSION_NOT_FOUND", "the session ended")
	case err != nil:
		writeError(writer, http.StatusInternalServerError, "EXEC_FAILED", "the shell could not run the command")
	default:
		writeJSON(writer, http.StatusOK, result)
	}
}

func (api *API) getJob(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.session(writer, request)
	if !ok {
		return
	}
	defer end()
	job := session.Job(request.PathValue("job"))
	if job == nil {
		writeError(writer, http.StatusNotFound, "JOB_NOT_FOUND", "no such job in this session")
		return
	}
	writeJSON(writer, http.StatusOK, job.View())
}

func (api *API) deleteJob(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.session(writer, request)
	if !ok {
		return
	}
	defer end()
	job := session.Job(request.PathValue("job"))
	if job == nil {
		writeError(writer, http.StatusNotFound, "JOB_NOT_FOUND", "no such job in this session")
		return
	}
	job.Kill(JobKilled)
	select {
	case <-job.done:
	case <-time.After(5 * time.Second):
	}
	writeJSON(writer, http.StatusOK, job.View())
}

// helperStatus maps a helper exit code to an HTTP status and error code.
func helperStatus(exitCode int) (int, string) {
	switch exitCode {
	case helperExitOK:
		return http.StatusOK, ""
	case helperExitNotFound:
		return http.StatusNotFound, "FILE_NOT_FOUND"
	case helperExitRefused:
		return http.StatusBadRequest, "PATH_REFUSED"
	case helperExitNotRegular:
		return http.StatusConflict, "NOT_A_REGULAR_FILE"
	case helperExitTooLarge:
		return http.StatusRequestEntityTooLarge, "FILE_TOO_LARGE"
	case helperExitNoSpace:
		// the workspace file system is full (ENOSPC of the memory-backed emptyDir): the hard limit
		return http.StatusRequestEntityTooLarge, "QUOTA_EXCEEDED"
	case helperExitIncomplete:
		return http.StatusBadRequest, "INCOMPLETE_BODY"
	}
	return http.StatusInternalServerError, "FILE_OPERATION_FAILED"
}

func (api *API) readFile(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.session(writer, request)
	if !ok {
		return
	}
	defer end()
	relativePath, err := CleanRelativePath(request.PathValue("path"))
	if err != nil {
		writeError(writer, http.StatusBadRequest, "PATH_REFUSED", "the path must be relative, inside the workspace, without ..")
		return
	}
	if session.drive != nil && session.drive.Streamed && strings.HasPrefix(relativePath, DriveSpacesDir+"/") {
		api.readStreamedFile(writer, session, relativePath)
		return
	}
	// the runner's own files (spilled output, conflict markers) carry what the commands printed, secrets included:
	// served with the claim's env masked, as the administration reads show them
	masked := LeadsIntoRunnerDir(api.runner.config.Workspace, relativePath)
	command := api.runner.helperCommand(session.UID, session.env, "read", api.runner.config.Workspace, relativePath)
	stdout, err := command.StdoutPipe()
	if err != nil {
		writeError(writer, http.StatusInternalServerError, "FILE_OPERATION_FAILED", "could not start the reader")
		return
	}
	if err := command.Start(); err != nil {
		writeError(writer, http.StatusInternalServerError, "FILE_OPERATION_FAILED", "could not start the reader")
		return
	}
	reader := bufio.NewReaderSize(stdout, 256<<10)
	header, headerErr := reader.ReadString('\n')
	if headerErr != nil || !strings.HasPrefix(header, "OK ") {
		io.Copy(io.Discard, reader)
		command.Wait()
		status, code := helperStatus(exitCodeOf(command.ProcessState))
		if status == http.StatusOK {
			status, code = http.StatusInternalServerError, "FILE_OPERATION_FAILED"
		}
		writeError(writer, status, code, "the file could not be read")
		return
	}
	size := strings.TrimSpace(strings.TrimPrefix(header, "OK "))
	writer.Header().Set("Content-Type", "application/octet-stream")
	if masked {
		content, err := io.ReadAll(reader)
		command.Wait()
		if err != nil {
			writeError(writer, http.StatusInternalServerError, "FILE_OPERATION_FAILED", "the file could not be read")
			return
		}
		maskedContent := session.secretMask().apply(string(content))
		writer.Header().Set("Content-Length", strconv.Itoa(len(maskedContent)))
		writer.WriteHeader(http.StatusOK)
		io.WriteString(writer, maskedContent)
		return
	}
	writer.Header().Set("Content-Length", size)
	writer.WriteHeader(http.StatusOK)
	if _, err := io.Copy(writer, reader); err != nil {
		command.Process.Kill()
	}
	command.Wait()
}

// writeSessionFile writes one file through the helper; mode is octal text, mtime RFC 3339 or unix nanoseconds.
// size is the exact number of body bytes: a shorter or longer body commits nothing.
func (api *API) writeSessionFile(session *Session, relativePath string, body io.Reader, size int64, mode, mtime string) (int, string, []byte) {
	modeArgument, mtimeArgument := "-", "-"
	if mode != "" {
		if parsed, err := strconv.ParseUint(mode, 8, 32); err != nil || parsed > 0o777 {
			return http.StatusBadRequest, "INVALID_MODE", nil
		}
		modeArgument = mode
	}
	if mtime != "" {
		if moment, err := time.Parse(time.RFC3339Nano, mtime); err == nil {
			mtimeArgument = strconv.FormatInt(moment.UnixNano(), 10)
		} else if _, err := strconv.ParseInt(mtime, 10, 64); err == nil {
			mtimeArgument = mtime
		} else {
			return http.StatusBadRequest, "INVALID_MTIME", nil
		}
	}
	output, exitCode, err := session.helper(body, "write", api.runner.config.Workspace, relativePath,
		modeArgument, mtimeArgument, strconv.FormatUint(api.runner.config.MaxFileBytes, 10), strconv.FormatInt(size, 10))
	if err != nil {
		return http.StatusInternalServerError, "FILE_OPERATION_FAILED", nil
	}
	status, code := helperStatus(exitCode)
	return status, code, output
}

func (api *API) writeFile(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.session(writer, request)
	if !ok {
		return
	}
	defer end()
	relativePath, err := CleanRelativePath(request.PathValue("path"))
	if err != nil {
		writeError(writer, http.StatusBadRequest, "PATH_REFUSED", "the path must be relative, inside the workspace, without ..")
		return
	}
	limit := int64(api.runner.config.MaxFileBytes)
	if request.ContentLength < 0 {
		writeError(writer, http.StatusLengthRequired, "LENGTH_REQUIRED", "PUT needs a Content-Length")
		return
	}
	if request.ContentLength > limit {
		writeError(writer, http.StatusRequestEntityTooLarge, "FILE_TOO_LARGE", "the file exceeds RUNNER_MAX_FILE_BYTES")
		return
	}
	// quota: the file counts in full, minus what the file it replaces uses now
	usage, err := session.MeasureUsage(filepath.Join(api.runner.config.Workspace, relativePath))
	if err != nil {
		writeError(writer, http.StatusInternalServerError, "QUOTA_CHECK_FAILED", "the workspace usage could not be measured")
		return
	}
	// two limits: the quota (the replaced file's bytes are credited) and the mount's free space (the new file is
	// written next to the old one before the rename, so it needs its full size)
	config := api.runner.config
	free := session.workspaceFree()
	reason := ""
	switch {
	case request.ContentLength > free-config.FreeReserve:
		reason = QuotaNoSpace
	case usage.TotalBytes-usage.TargetBytes+request.ContentLength > config.QuotaBytes:
		reason = QuotaLimit
	}
	if reason != "" {
		// a refused PUT changes no state: it answers and logs, nothing more
		report := session.newQuotaReport(reason, usage, free)
		api.runner.logger.Info("put refused by quota", "session", session.ID, "reason", reason,
			"totalBytes", usage.TotalBytes, "replacedBytes", usage.TargetBytes, "fileBytes", request.ContentLength,
			"limitBytes", report.LimitBytes, "quotaBytes", config.QuotaBytes, "freeBytes", free,
			"reserveBytes", config.FreeReserve, "byDirectory", usage.ByDirectory)
		writeJSON(writer, http.StatusRequestEntityTooLarge, map[string]any{
			"error": "QUOTA_EXCEEDED", "message": "the file would push the workspace past its limit",
			"reason": reason, "totalBytes": report.TotalBytes, "limitBytes": report.LimitBytes,
			"quotaBytes": report.QuotaBytes, "freeBytes": report.FreeBytes, "fileBytes": request.ContentLength,
			"byDirectory": report.ByDirectory, "largest": report.Largest,
		})
		return
	}
	body := http.MaxBytesReader(writer, request.Body, limit)
	started := time.Now()
	status, code, output := api.writeSessionFile(session, relativePath, body, request.ContentLength, request.URL.Query().Get("mode"), request.URL.Query().Get("mtime"))
	if code == "QUOTA_EXCEEDED" {
		report := session.CheckQuotaAfter(true)
		writeJSON(writer, status, map[string]any{"error": code, "message": "the workspace file system is full",
			"reason": report.Reason, "totalBytes": report.TotalBytes, "limitBytes": report.LimitBytes,
			"quotaBytes": report.QuotaBytes, "freeBytes": report.FreeBytes, "fileBytes": request.ContentLength,
			"byDirectory": report.ByDirectory, "largest": report.Largest})
		return
	}
	if status != http.StatusOK {
		writeError(writer, status, code, "the file could not be written")
		return
	}
	var result map[string]any
	json.Unmarshal(output, &result)
	result["path"] = relativePath
	api.runner.logger.Info("file written", "session", session.ID, "bytes", result["size"], "durationMs", time.Since(started).Milliseconds())
	writeJSON(writer, http.StatusOK, result)
}

func (api *API) manifest(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.session(writer, request)
	if !ok {
		return
	}
	defer end()
	started := time.Now()
	manifest, exitCode, err := session.Manifest()
	if err != nil || exitCode != 0 {
		writeError(writer, http.StatusInternalServerError, "MANIFEST_FAILED", "the manifest could not be built")
		return
	}
	api.runner.logger.Info("manifest", "session", session.ID, "durationMs", time.Since(started).Milliseconds())
	writer.Header().Set("Content-Type", "application/json")
	writer.WriteHeader(http.StatusOK)
	writer.Write(manifest)
	writer.Write([]byte("\n"))
}

// readStreamedFile answers a file of a streamed mount from the cache (the tree holds nothing there): a local file as
// its bytes, a placeholder as 404 like a missing file in Plan B, SPACE.md and .drive-index as generated.
func (api *API) readStreamedFile(writer http.ResponseWriter, session *Session, relativePath string) {
	state := session.drive
	state.mutex.Lock()
	space, inside, ok := state.resolveWorkspacePath(relativePath)
	var content []byte
	var blob string
	if ok && space.Name != "" {
		switch entry := space.Entries[inside]; {
		case inside == DriveSpaceFile:
			content = append([]byte(space.SpaceMD), []byte("\n"+fuseStreamedNote)...)
		case inside == DriveIndexFile:
			content = indexLines(space)
		case entry != nil && entry.Local && entry.LinkTarget == "":
			blob = state.cachePath(entry.SHA256)
		}
	}
	state.mutex.Unlock()
	if content != nil {
		writer.Header().Set("Content-Type", "application/octet-stream")
		writer.Header().Set("Content-Length", strconv.Itoa(len(content)))
		writer.WriteHeader(http.StatusOK)
		writer.Write(content)
		return
	}
	file, err := os.Open(blob)
	if blob == "" || err != nil {
		writeError(writer, http.StatusNotFound, "FILE_NOT_FOUND", "no such file, or not local")
		return
	}
	defer file.Close()
	info, err := file.Stat()
	if err != nil {
		writeError(writer, http.StatusInternalServerError, "FILE_OPERATION_FAILED", "the file could not be read")
		return
	}
	writer.Header().Set("Content-Type", "application/octet-stream")
	writer.Header().Set("Content-Length", strconv.FormatInt(info.Size(), 10))
	writer.WriteHeader(http.StatusOK)
	io.Copy(writer, file)
}
