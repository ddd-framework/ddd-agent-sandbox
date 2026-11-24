package main

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"os"
	"strconv"
	"time"
)

func (api *API) driveRoutes(mux *http.ServeMux) {
	mux.HandleFunc("GET /v1/sessions/{id}/drive/status", api.driveStatus)
	mux.HandleFunc("POST /v1/sessions/{id}/drive/stage", api.driveStage)
	mux.HandleFunc("POST /v1/sessions/{id}/drive/pull", api.drivePull)
	mux.HandleFunc("POST /v1/sessions/{id}/drive/materialize", api.driveMaterialize)
	mux.HandleFunc("POST /v1/sessions/{id}/drive/commit", api.driveCommit)
	mux.HandleFunc("POST /v1/sessions/{id}/drive/merge", api.driveMerge)
	mux.HandleFunc("POST /v1/sessions/{id}/drive/resolve", api.driveResolve)
	mux.HandleFunc("POST /v1/sessions/{id}/drive/pin", api.drivePin)
	mux.HandleFunc("POST /v1/sessions/{id}/drive/upload", api.driveUpload)
	mux.HandleFunc("PUT /v1/sessions/{id}/drive/blobs/{sha256}", api.drivePutBlob)
	mux.HandleFunc("GET /v1/sessions/{id}/drive/blobs/{sha256}", api.driveGetBlob)
	mux.HandleFunc("POST /v1/sessions/{id}/execs/{seq}/continue", api.continueExec)
}

// driveSession resolves the session and checks drive mode; on failure it has answered.
func (api *API) driveSession(writer http.ResponseWriter, request *http.Request) (*Session, func(), bool) {
	session, end, ok := api.session(writer, request)
	if !ok {
		return nil, nil, false
	}
	if session.drive == nil {
		end()
		writeError(writer, http.StatusConflict, "DRIVE_MODE_OFF", "the session was claimed without mode drive")
		return nil, nil, false
	}
	return session, end, true
}

func decodeBody(writer http.ResponseWriter, request *http.Request, target any, limit int64) bool {
	decoder := json.NewDecoder(http.MaxBytesReader(writer, request.Body, limit))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		writeError(writer, http.StatusBadRequest, "INVALID_BODY", "the body does not match the drive contract (README, Protocol)")
		return false
	}
	return true
}

func driveFailure(writer http.ResponseWriter, err error) {
	writeError(writer, http.StatusInternalServerError, "DRIVE_OPERATION_FAILED", "the drive operation failed")
}

func (api *API) driveStatus(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	status, err := session.Status()
	if err != nil {
		driveFailure(writer, err)
		return
	}
	writeJSON(writer, http.StatusOK, status)
}

func (api *API) driveStage(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	started := time.Now()
	result, err := session.Stage()
	if err != nil {
		driveFailure(writer, err)
		return
	}
	api.runner.logger.Info("drive stage", "session", session.ID, "changes", len(result.Changes),
		"snapshots", result.Snapshots, "durationMs", time.Since(started).Milliseconds())
	writeJSON(writer, http.StatusOK, result)
}

func (api *API) drivePull(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	var body PullRequest
	if !decodeBody(writer, request, &body, 64<<20) {
		return
	}
	started := time.Now()
	result, err := session.Pull(body)
	if err != nil {
		writeError(writer, http.StatusBadRequest, "PULL_REFUSED", "the pull could not be applied (unknown target or unreadable tree)")
		return
	}
	api.runner.logger.Info("drive pull", "session", session.ID, "target", result.Target, "changes", len(body.Changes),
		"applied", len(result.Applied), "merged", len(result.Merged), "conflicts", len(result.Conflicts),
		"needBlobs", len(result.NeedBlobs), "failed", len(result.Failed), "evicted", len(result.Evicted),
		"durationMs", time.Since(started).Milliseconds())
	writeJSON(writer, http.StatusOK, result)
}

func (api *API) driveMaterialize(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	var body struct {
		Items []MaterializeItem `json:"items"`
	}
	if !decodeBody(writer, request, &body, 16<<20) {
		return
	}
	result, err := session.Materialize(body.Items)
	if err != nil {
		driveFailure(writer, err)
		return
	}
	writeJSON(writer, http.StatusOK, result)
}

func (api *API) driveCommit(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	var body CommitResult
	if !decodeBody(writer, request, &body, 16<<20) {
		return
	}
	status, err := session.ApplyCommit(body)
	if err != nil {
		driveFailure(writer, err)
		return
	}
	writeJSON(writer, http.StatusOK, status)
}

func (api *API) driveMerge(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	var body struct {
		Path   string      `json:"path"`
		Theirs DriveChange `json:"theirs"`
	}
	if !decodeBody(writer, request, &body, 1<<20) {
		return
	}
	relative, err := CleanRelativePath(body.Path)
	if err != nil || !validSHA256(body.Theirs.SHA256) {
		writeError(writer, http.StatusBadRequest, "INVALID_BODY", "path and theirs.sha256 are required")
		return
	}
	outcome, result, err := session.Merge(relative, body.Theirs)
	if errors.Is(err, errTreeChanged) {
		writeError(writer, http.StatusConflict, "TREE_CHANGED", "the local file changed during the merge; nothing was written")
		return
	}
	if err != nil {
		driveFailure(writer, err)
		return
	}
	writeJSON(writer, http.StatusOK, map[string]any{"result": outcome.Result, "conflicts": outcome.Conflicts,
		"sideFile": outcome.SideFile, "sha256": outcome.SHA256, "size": outcome.Size, "needBlobs": result.NeedBlobs,
		"failed": result.Failed})
}

func (api *API) driveResolve(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	var body ResolveRequest
	if !decodeBody(writer, request, &body, 1<<20) {
		return
	}
	result, err := session.Resolve(body)
	if err != nil {
		writeError(writer, http.StatusBadRequest, "RESOLVE_REFUSED", err.Error())
		return
	}
	writeJSON(writer, http.StatusOK, result)
}

func (api *API) drivePin(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	var body struct {
		Folder string            `json:"folder"`
		Pinned bool              `json:"pinned"`
		Items  []MaterializeItem `json:"items,omitempty"`
	}
	if !decodeBody(writer, request, &body, 16<<20) {
		return
	}
	result, err := session.SetPinned(body.Folder, body.Pinned, body.Items)
	if err != nil {
		writeError(writer, http.StatusBadRequest, "PIN_REFUSED", err.Error())
		return
	}
	writeJSON(writer, http.StatusOK, result)
}

func (api *API) driveUpload(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	var body struct {
		Uploads []UploadItem `json:"uploads"`
	}
	if !decodeBody(writer, request, &body, 16<<20) {
		return
	}
	outcomes, err := session.Upload(body.Uploads)
	if err != nil {
		writeError(writer, http.StatusConflict, "NO_TRANSFER_PROXY", "transfer is stream: read snapshots with GET …/drive/blobs/{sha256}")
		return
	}
	writeJSON(writer, http.StatusOK, map[string]any{"uploads": outcomes})
}

func (api *API) drivePutBlob(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	sha := request.PathValue("sha256")
	if !validSHA256(sha) {
		writeError(writer, http.StatusBadRequest, "INVALID_SHA256", "the path names a lower-case hex sha256")
		return
	}
	if request.ContentLength < 0 {
		writeError(writer, http.StatusLengthRequired, "LENGTH_REQUIRED", "PUT needs a Content-Length")
		return
	}
	limit := int64(api.runner.config.MaxFileBytes)
	if request.ContentLength > limit {
		writeError(writer, http.StatusRequestEntityTooLarge, "FILE_TOO_LARGE", "the blob exceeds RUNNER_MAX_FILE_BYTES")
		return
	}
	if _, free, _, err := statfsBytes(api.runner.config.DriveStateDir); err == nil &&
		request.ContentLength > free-api.runner.config.FreeReserve {
		writeError(writer, http.StatusRequestEntityTooLarge, "QUOTA_EXCEEDED", "the cache volume cannot hold the blob")
		return
	}
	if session.drive.hasBlob(sha) {
		io.Copy(io.Discard, io.LimitReader(request.Body, limit))
		writeJSON(writer, http.StatusOK, map[string]any{"sha256": sha, "cached": true})
		return
	}
	if err := receiveBlob(session.drive, sha, http.MaxBytesReader(writer, request.Body, limit), limit); err != nil {
		if errors.Is(err, errHashMismatch) {
			writeError(writer, http.StatusBadRequest, "HASH_MISMATCH", "the bytes do not hash to the sha256 of the path")
			return
		}
		driveFailure(writer, err)
		return
	}
	writeJSON(writer, http.StatusOK, map[string]any{"sha256": sha, "cached": false})
}

// driveGetBlob serves a cache blob (a staged snapshot or a base): uploads in stream transfer read from here, never
// from the tree.
func (api *API) driveGetBlob(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.driveSession(writer, request)
	if !ok {
		return
	}
	defer end()
	sha := request.PathValue("sha256")
	if !session.drive.hasBlob(sha) {
		writeError(writer, http.StatusNotFound, "BLOB_NOT_FOUND", "no such blob in the cache (stage first)")
		return
	}
	file, err := os.Open(session.drive.cachePath(sha))
	if err != nil {
		writeError(writer, http.StatusNotFound, "BLOB_NOT_FOUND", "no such blob in the cache (stage first)")
		return
	}
	defer file.Close()
	info, err := file.Stat()
	if err != nil {
		driveFailure(writer, err)
		return
	}
	writer.Header().Set("Content-Type", "application/octet-stream")
	writer.Header().Set("Content-Length", strconv.FormatInt(info.Size(), 10))
	writer.WriteHeader(http.StatusOK)
	io.Copy(writer, file)
}

func (api *API) continueExec(writer http.ResponseWriter, request *http.Request) {
	session, end, ok := api.session(writer, request)
	if !ok {
		return
	}
	defer end()
	seq, err := strconv.ParseInt(request.PathValue("seq"), 10, 64)
	run := session.Run(seq)
	if err != nil || run == nil {
		writeError(writer, http.StatusNotFound, "EXEC_NOT_FOUND", "no paused or unanswered exec with this seq")
		return
	}
	var body struct {
		Responses []DriveResponse `json:"responses"`
	}
	if !decodeBody(writer, request, &body, 16<<20) {
		return
	}
	if err := session.Continue(run, body.Responses); err != nil {
		writeError(writer, http.StatusBadRequest, "UNKNOWN_REQUEST", "a requestId is not waiting in this exec")
		return
	}
	result, err := session.AwaitExec(run)
	if err != nil {
		writeError(writer, http.StatusInternalServerError, "EXEC_FAILED", "the shell could not run the command")
		return
	}
	writeJSON(writer, http.StatusOK, result)
}
