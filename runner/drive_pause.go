package main

// The drive CLI talks to the runner over a Unix socket. status and diff answer at once. commit, pull, get, pin and
// unpin need PHP (rights, caps, presigned URLs): the runner pauses the foreground exec that started the CLI, answers
// the exec call with status DRIVE_REQUESTS, and continues when PHP posts the responses to …/execs/{seq}/continue. The
// exec's timer stands still while a request waits. Inside a background job these verbs fail at once (no turn waits).

import (
	"bufio"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

// ExecDriveRequests: the exec waits for PHP's responses (POST …/execs/{seq}/continue).
const ExecDriveRequests = "DRIVE_REQUESTS"

// pausableTimer is an exec timeout that stands still while drive requests wait.
type pausableTimer struct {
	mutex     sync.Mutex
	remaining time.Duration
	started   time.Time
	timer     *time.Timer
	paused    int
	fired     bool
	pausedAt  time.Time
	pausedFor time.Duration
	C         chan struct{}
}

func newPausableTimer(timeout time.Duration) *pausableTimer {
	timer := &pausableTimer{remaining: timeout, started: time.Now(), C: make(chan struct{})}
	timer.timer = time.AfterFunc(timeout, timer.fire)
	return timer
}

func (timer *pausableTimer) fire() {
	timer.mutex.Lock()
	defer timer.mutex.Unlock()
	if timer.paused > 0 || timer.fired {
		return
	}
	timer.fired = true
	close(timer.C)
}

// Pause stops the clock (nested pauses count).
func (timer *pausableTimer) Pause() {
	timer.mutex.Lock()
	defer timer.mutex.Unlock()
	if timer.paused == 0 && !timer.fired {
		timer.timer.Stop()
		timer.remaining -= time.Since(timer.started)
		timer.pausedAt = time.Now()
	}
	timer.paused++
}

// Resume restarts the clock with the time that was left.
func (timer *pausableTimer) Resume() {
	timer.mutex.Lock()
	defer timer.mutex.Unlock()
	if timer.paused == 0 {
		return
	}
	timer.paused--
	if timer.paused == 0 && !timer.fired {
		timer.pausedFor += time.Since(timer.pausedAt)
		timer.started = time.Now()
		if timer.remaining <= 0 {
			timer.fired = true
			close(timer.C)
			return
		}
		timer.timer = time.AfterFunc(timer.remaining, timer.fire)
	}
}

func (timer *pausableTimer) Stop() {
	timer.mutex.Lock()
	defer timer.mutex.Unlock()
	timer.timer.Stop()
}

func (timer *pausableTimer) PausedFor() time.Duration {
	timer.mutex.Lock()
	defer timer.mutex.Unlock()
	if timer.paused > 0 {
		return timer.pausedFor + time.Since(timer.pausedAt)
	}
	return timer.pausedFor
}

// driveRequest is one CLI call that waits for PHP.
type driveRequest struct {
	ID      string
	Verb    string
	Payload map[string]any
	reply   chan cliReply
}

// DriveRequestView is how a waiting request appears in the exec answer.
type DriveRequestView struct {
	RequestID string         `json:"requestId"`
	Verb      string         `json:"verb"`
	Payload   map[string]any `json:"payload"`
}

// execRun is one foreground exec; it outlives its HTTP call while paused.
type execRun struct {
	seq     int64
	shell   *Shell
	timer   *pausableTimer
	started time.Time
	done    chan struct{}
	result  ExecResult
	err     error
	// signal: a request was added (buffered; the waiter re-checks the pending list)
	signal  chan struct{}
	mutex   sync.Mutex
	pending []*driveRequest
	// misses: opens of not-local mount files during this exec (placeholder FUSE)
	misses callMisses
	// history: the exec's entry in the session history (admin.go)
	history *historyEntry
}

func (run *execRun) addRequest(request *driveRequest) {
	run.mutex.Lock()
	run.pending = append(run.pending, request)
	run.mutex.Unlock()
	run.timer.Pause()
	select {
	case run.signal <- struct{}{}:
	default:
	}
}

// takeRequest removes a pending request; the timer resumes for it.
func (run *execRun) takeRequest(id string) *driveRequest {
	run.mutex.Lock()
	defer run.mutex.Unlock()
	for index, request := range run.pending {
		if request.ID == id {
			run.pending = append(run.pending[:index], run.pending[index+1:]...)
			run.timer.Resume()
			return request
		}
	}
	return nil
}

func (run *execRun) pendingViews() []DriveRequestView {
	run.mutex.Lock()
	defer run.mutex.Unlock()
	views := []DriveRequestView{}
	for _, request := range run.pending {
		views = append(views, DriveRequestView{RequestID: request.ID, Verb: request.Verb, Payload: request.Payload})
	}
	return views
}

// AwaitExec waits until the exec ends or a drive request waits; a final result removes the run.
func (session *Session) AwaitExec(run *execRun) (ExecResult, error) {
	for {
		select {
		case <-run.done:
			session.forgetRun(run.seq)
			return run.result, run.err
		default:
		}
		if views := run.pendingViews(); len(views) > 0 {
			return ExecResult{Seq: run.seq, Status: ExecDriveRequests, DriveRequests: views,
				DurationMs: time.Since(run.started).Milliseconds(), PausedMs: run.timer.PausedFor().Milliseconds(),
				Cwd: run.shell.Cwd()}, nil
		}
		select {
		case <-run.done:
		case <-run.signal:
		}
	}
}

func (session *Session) forgetRun(seq int64) {
	session.mutex.Lock()
	delete(session.runs, seq)
	session.mutex.Unlock()
}

// Run answers the run of a paused exec.
func (session *Session) Run(seq int64) *execRun {
	session.mutex.Lock()
	defer session.mutex.Unlock()
	return session.runs[seq]
}

// DriveResponse is PHP's answer to one drive request; the runner applies the blocks it carries.
type DriveResponse struct {
	RequestID   string            `json:"requestId"`
	OK          bool              `json:"ok"`
	Message     string            `json:"message,omitempty"`
	Pulls       []PullRequest     `json:"pulls,omitempty"`
	Materialize []MaterializeItem `json:"materialize,omitempty"`
	Commit      *CommitResult     `json:"commit,omitempty"`
}

// Continue applies PHP's responses, answers the waiting CLI calls and resumes the exec. Unknown ids are refused
// before anything is applied.
func (session *Session) Continue(run *execRun, responses []DriveResponse) error {
	run.mutex.Lock()
	known := map[string]bool{}
	for _, request := range run.pending {
		known[request.ID] = true
	}
	run.mutex.Unlock()
	for _, response := range responses {
		if !known[response.RequestID] {
			return fmt.Errorf("unknown requestId")
		}
	}
	for _, response := range responses {
		request := run.takeRequestNoResume(response.RequestID)
		if request == nil {
			continue
		}
		request.reply <- session.applyDriveResponse(request, response)
		run.timer.Resume()
	}
	return nil
}

func (run *execRun) takeRequestNoResume(id string) *driveRequest {
	run.mutex.Lock()
	defer run.mutex.Unlock()
	for index, request := range run.pending {
		if request.ID == id {
			run.pending = append(run.pending[:index], run.pending[index+1:]...)
			return request
		}
	}
	return nil
}

func (session *Session) applyDriveResponse(request *driveRequest, response DriveResponse) cliReply {
	if !response.OK {
		message := response.Message
		if message == "" {
			message = "drive " + request.Verb + " was refused"
		}
		return cliReply{ExitCode: 1, Stderr: strings.TrimRight(message, "\n") + "\n"}
	}
	var out strings.Builder
	if response.Message != "" {
		out.WriteString(strings.TrimRight(response.Message, "\n") + "\n")
	}
	failed := false
	for _, pull := range response.Pulls {
		result, err := session.Pull(pull)
		if err != nil {
			out.WriteString("pull " + pull.Target + ": " + err.Error() + "\n")
			failed = true
			continue
		}
		failed = renderPull(&out, result) || failed
	}
	switch request.Verb {
	case "pin":
		folder, _ := request.Payload["folder"].(string)
		result, err := session.SetPinned(folder, true, response.Materialize)
		if err != nil {
			return cliReply{ExitCode: 1, Stderr: err.Error() + "\n"}
		}
		out.WriteString("pinned " + folder + "\n")
		failed = renderPull(&out, result) || failed
	case "unpin":
		folder, _ := request.Payload["folder"].(string)
		result, err := session.SetPinned(folder, false, nil)
		if err != nil {
			return cliReply{ExitCode: 1, Stderr: err.Error() + "\n"}
		}
		out.WriteString("unpinned " + folder + "\n")
		failed = renderPull(&out, result) || failed
	default:
		if len(response.Materialize) > 0 {
			result, err := session.Materialize(response.Materialize)
			if err != nil {
				return cliReply{ExitCode: 1, Stderr: err.Error() + "\n"}
			}
			failed = renderPull(&out, result) || failed
		}
	}
	if response.Commit != nil {
		if _, err := session.ApplyCommit(*response.Commit); err != nil {
			return cliReply{ExitCode: 1, Stderr: err.Error() + "\n"}
		}
		parts := []string{}
		for _, committed := range response.Commit.Committed {
			if committed.VersionNumber > 0 {
				parts = append(parts, fmt.Sprintf("%s v%d", committed.Path, committed.VersionNumber))
			} else {
				parts = append(parts, committed.Path)
			}
		}
		for _, deleted := range response.Commit.Deleted {
			parts = append(parts, deleted+" trashed")
		}
		out.WriteString(fmt.Sprintf("committed %d files", len(response.Commit.Committed)+len(response.Commit.Deleted)))
		if len(parts) > 0 {
			out.WriteString(": " + strings.Join(parts, " · "))
		}
		out.WriteString("\n")
		for _, conflict := range response.Commit.Conflicts {
			out.WriteString("not committed, newer version in the drive: " + conflict.Path + " (drive pull, then decide)\n")
			failed = true
		}
		if refused, _ := request.Payload["refusedSymlinks"].([]RefusedSymlink); len(refused) > 0 {
			for _, link := range refused {
				out.WriteString("refused symlink: " + link.Path + " -> " + link.Target + " (" + link.Reason + ")\n")
			}
		}
	}
	code := 0
	if failed {
		code = 1
	}
	return cliReply{ExitCode: code, Stdout: out.String()}
}

// renderPull writes a pull or materialise result for the CLI; true when something failed or conflicted.
func renderPull(out *strings.Builder, result *PullResult) bool {
	if len(result.Applied) > 0 {
		fmt.Fprintf(out, "updated %d paths\n", len(result.Applied))
	}
	for _, merged := range result.Merged {
		fmt.Fprintf(out, "merged: %s (both sides changed, no overlap; check the result)\n", merged)
	}
	for _, conflict := range result.Conflicts {
		line := "conflict: " + conflict.Path + " (" + conflict.Kind
		if conflict.SideFile != "" {
			line += ", markers in " + conflict.SideFile
		}
		fmt.Fprintln(out, line+"); decide with commitDriveChanges resolve mine|theirs|both")
	}
	for _, need := range result.NeedBlobs {
		fmt.Fprintf(out, "not fetched: %s\n", strings.Join(need.Paths, ", "))
	}
	for _, failure := range result.Failed {
		fmt.Fprintf(out, "failed: %s: %s\n", failure.Path, failure.Reason)
	}
	for _, evicted := range result.Evicted {
		fmt.Fprintf(out, "no longer local (mirror budget): %s\n", evicted)
	}
	return len(result.Conflicts) > 0 || len(result.NeedBlobs) > 0 || len(result.Failed) > 0
}

// ---------------------------------------------------------------------------------------------------------------------
// The socket

type cliRequest struct {
	Verb    string   `json:"verb"`
	Args    []string `json:"args"`
	Cwd     string   `json:"cwd"`
	Message string   `json:"message,omitempty"`
	All     bool     `json:"all,omitempty"`
	JSON    bool     `json:"json,omitempty"`
}

type cliReply struct {
	ExitCode int    `json:"exitCode"`
	Stdout   string `json:"stdout,omitempty"`
	Stderr   string `json:"stderr,omitempty"`
}

// serveDriveSocket listens for the drive CLI of this session (mode 0666: the session uid connects; the peer's uid is
// checked per connection).
func (session *Session) serveDriveSocket() error {
	socket := session.runner.config.DriveSocket
	os.Remove(socket)
	listener, err := net.Listen("unix", socket)
	if err != nil {
		return err
	}
	os.Chmod(socket, 0o666)
	session.driveListener = listener
	go func() {
		for {
			connection, err := listener.Accept()
			if err != nil {
				return
			}
			go session.handleDriveConnection(connection.(*net.UnixConn))
		}
	}()
	return nil
}

func peerCredentials(connection *net.UnixConn) (*syscall.Ucred, error) {
	raw, err := connection.SyscallConn()
	if err != nil {
		return nil, err
	}
	var credentials *syscall.Ucred
	var credentialsErr error
	if err := raw.Control(func(fd uintptr) {
		credentials, credentialsErr = syscall.GetsockoptUcred(int(fd), syscall.SOL_SOCKET, syscall.SO_PEERCRED)
	}); err != nil {
		return nil, err
	}
	return credentials, credentialsErr
}

func (session *Session) handleDriveConnection(connection *net.UnixConn) {
	defer connection.Close()
	end := session.Begin()
	defer end()
	writeReply := func(reply cliReply) { json.NewEncoder(connection).Encode(reply) }
	credentials, err := peerCredentials(connection)
	if err != nil || int(credentials.Uid) != session.UID {
		writeReply(cliReply{ExitCode: 1, Stderr: "drive: not a process of this session\n"})
		return
	}
	connection.SetReadDeadline(time.Now().Add(10 * time.Second))
	var request cliRequest
	if err := json.NewDecoder(bufio.NewReader(&limitedConnReader{connection: connection, left: 1 << 20})).Decode(&request); err != nil {
		writeReply(cliReply{ExitCode: 2, Stderr: "drive: invalid request\n"})
		return
	}
	connection.SetReadDeadline(time.Time{})
	writeReply(session.handleCLI(request, int(credentials.Pid)))
}

type limitedConnReader struct {
	connection *net.UnixConn
	left       int
}

func (reader *limitedConnReader) Read(buffer []byte) (int, error) {
	if reader.left <= 0 {
		return 0, fmt.Errorf("request too large")
	}
	if len(buffer) > reader.left {
		buffer = buffer[:reader.left]
	}
	count, err := reader.connection.Read(buffer)
	reader.left -= count
	return count, err
}

// cliPath turns a CLI argument into a workspace-relative path (relative to the caller's cwd, or drive/<nodeId>).
func (session *Session) cliPath(argument, cwd string) (string, bool) {
	workspace := session.runner.config.Workspace
	if rest, found := strings.CutPrefix(argument, "drive/"); found {
		if nodeID, err := strconv.ParseInt(rest, 10, 64); err == nil {
			state := session.drive
			state.mutex.Lock()
			defer state.mutex.Unlock()
			for _, space := range append([]*DriveSpaceState{state.Own}, state.spacesOfMounts()...) {
				for entryPath, entry := range space.Entries {
					if entry.NodeID == nodeID {
						return spaceWorkspacePath(space, entryPath), true
					}
				}
			}
			return "", false
		}
	}
	absolute := argument
	if !filepath.IsAbs(argument) {
		if cwd == "" || !filepath.IsAbs(cwd) {
			cwd = workspace
		}
		absolute = filepath.Join(cwd, argument)
	}
	relative, err := filepath.Rel(workspace, filepath.Clean(absolute))
	if err != nil || relative == ".." || strings.HasPrefix(relative, "../") {
		return "", false
	}
	if relative == "." {
		return "", true
	}
	return filepath.ToSlash(relative), true
}

// cliDrivePath is cliPath for a file or folder of the drive (drive get, drive pin): a relative argument that names
// nothing of the drive from the shell's folder is tried from the workspace. The agent's shell starts in
// work/<conversation>/, while the operator, the file tools and the skills name drive paths from /workspace.
func (session *Session) cliDrivePath(argument, cwd string) (string, bool) {
	relative, ok := session.cliPath(argument, cwd)
	if filepath.IsAbs(argument) || strings.HasPrefix(argument, "drive/") || (ok && session.drive.namesDrivePath(relative)) {
		return relative, ok
	}
	if fromWorkspace, found := session.cliPath(argument, session.runner.config.Workspace); found && session.drive.namesDrivePath(fromWorkspace) {
		return fromWorkspace, true
	}
	return relative, ok
}

// namesDrivePath tells whether a workspace path is a file or folder of the drive (own drive or a mount).
func (state *DriveState) namesDrivePath(workspacePath string) bool {
	if workspacePath == "" {
		return false
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	if rest, found := strings.CutPrefix(workspacePath, DriveSpacesDir+"/"); found && !strings.Contains(rest, "/") {
		return state.Mounts[rest] != nil
	}
	space, inside, ok := state.resolveWorkspacePath(workspacePath)
	if !ok || space == nil {
		return false
	}
	for entryPath := range space.Entries {
		if entryPath == inside || strings.HasPrefix(entryPath, inside+"/") {
			return true
		}
	}
	return false
}

const driveJobHint = "drive %s needs the agent host and cannot run in a background job; run it in the foreground, or use the tool (driveStatus, commitDriveChanges, getDriveFile)\n"

func (session *Session) handleCLI(request cliRequest, pid int) cliReply {
	if session.drive == nil {
		return cliReply{ExitCode: 1, Stderr: "drive: drive mode is off for this session\n"}
	}
	switch request.Verb {
	case "status":
		status, err := session.Status()
		if err != nil {
			return cliReply{ExitCode: 1, Stderr: "drive status: " + err.Error() + "\n"}
		}
		if request.JSON {
			encoded, _ := json.MarshalIndent(status, "", "  ")
			return cliReply{Stdout: string(encoded) + "\n"}
		}
		return cliReply{Stdout: renderStatus(status, request.All)}
	case "diff":
		if len(request.Args) != 1 {
			return cliReply{ExitCode: 2, Stderr: "usage: drive diff <path>\n"}
		}
		relative, ok := session.cliPath(request.Args[0], request.Cwd)
		if !ok || relative == "" {
			return cliReply{ExitCode: 2, Stderr: "drive diff: not a path of the own drive\n"}
		}
		diff, err := session.Diff(relative)
		if err != nil {
			return cliReply{ExitCode: 1, Stderr: "drive diff: " + err.Error() + "\n"}
		}
		return cliReply{Stdout: diff}
	case "commit", "pull", "get", "pin", "unpin":
	default:
		return cliReply{ExitCode: 2, Stderr: cliUsage}
	}
	run, inJob := session.ownerOfProcess(pid)
	if inJob {
		return cliReply{ExitCode: 1, Stderr: fmt.Sprintf(driveJobHint, request.Verb)}
	}
	if run == nil {
		return cliReply{ExitCode: 1, Stderr: "drive " + request.Verb + " runs only inside a shell call of the agent\n"}
	}
	payload := map[string]any{}
	switch request.Verb {
	case "commit":
		if strings.TrimSpace(request.Message) == "" {
			return cliReply{ExitCode: 2, Stderr: "usage: drive commit -m <message> [path…]\n"}
		}
		paths := []string{}
		for _, argument := range request.Args {
			relative, ok := session.cliPath(argument, request.Cwd)
			if !ok {
				return cliReply{ExitCode: 2, Stderr: "drive commit: not a path of the own drive: " + argument + "\n"}
			}
			paths = append(paths, relative)
		}
		staged, err := session.Stage()
		if err != nil {
			return cliReply{ExitCode: 1, Stderr: "drive commit: " + err.Error() + "\n"}
		}
		changes := []StatusChange{}
		for _, change := range staged.Changes {
			if len(paths) == 0 || underPinned(paths, change.Path) || change.From != "" && underPinned(paths, change.From) {
				changes = append(changes, change)
			}
		}
		if len(changes) == 0 && len(staged.RefusedSymlinks) == 0 {
			return cliReply{Stdout: "nothing to commit\n"}
		}
		payload["message"], payload["paths"], payload["changes"] = request.Message, paths, changes
		payload["refusedSymlinks"], payload["conflicts"] = staged.RefusedSymlinks, staged.Conflicts
	case "pull":
	case "get":
		if len(request.Args) == 0 {
			return cliReply{ExitCode: 2, Stderr: "usage: drive get <path>…\n"}
		}
		var paths []string
		for _, argument := range request.Args {
			relative, ok := session.cliDrivePath(argument, request.Cwd)
			if !ok || relative == "" {
				return cliReply{ExitCode: 2, Stderr: "drive get: not a path of the drive: " + argument + "\n"}
			}
			paths = append(paths, relative)
		}
		state := session.drive
		state.mutex.Lock()
		files, failures := state.expandToFiles(paths)
		items := []map[string]any{}
		for _, file := range files {
			space, relative, _ := state.resolveWorkspacePath(file)
			entry := space.Entries[relative]
			items = append(items, map[string]any{"path": file, "space": space.Name, "spaceId": space.SpaceID,
				"nodeId": entry.NodeID, "versionId": entry.VersionID, "sha256": contentOf(entry), "size": entry.Size})
		}
		state.mutex.Unlock()
		if len(items) == 0 {
			var out strings.Builder
			for _, failure := range failures {
				fmt.Fprintf(&out, "drive get: %s: %s\n", failure.Path, failure.Reason)
			}
			if out.Len() == 0 {
				return cliReply{Stdout: "already local\n"}
			}
			return cliReply{ExitCode: 1, Stderr: out.String()}
		}
		payload["items"] = items
	case "pin", "unpin":
		if len(request.Args) != 1 {
			return cliReply{ExitCode: 2, Stderr: "usage: drive " + request.Verb + " <folder>\n"}
		}
		relative, ok := session.cliDrivePath(request.Args[0], request.Cwd)
		state := session.drive
		state.mutex.Lock()
		space, inside, resolved := state.resolveWorkspacePath(relative)
		if !resolved && strings.HasPrefix(relative, DriveSpacesDir+"/") {
			if space = state.Mounts[strings.TrimPrefix(relative, DriveSpacesDir+"/")]; space != nil {
				inside, resolved = "", true
			}
		}
		var folderFiles []map[string]any
		if resolved {
			for entryPath, entry := range space.Entries {
				if inside == "" || strings.HasPrefix(entryPath, inside+"/") {
					if !entry.Local && request.Verb == "pin" {
						folderFiles = append(folderFiles, map[string]any{"path": spaceWorkspacePath(space, entryPath),
							"nodeId": entry.NodeID, "versionId": entry.VersionID, "sha256": contentOf(entry), "size": entry.Size})
					}
				}
			}
		}
		state.mutex.Unlock()
		if !ok || !resolved {
			return cliReply{ExitCode: 2, Stderr: "drive " + request.Verb + ": not a folder of the drive\n"}
		}
		sort.Slice(folderFiles, func(left, right int) bool {
			return folderFiles[left]["path"].(string) < folderFiles[right]["path"].(string)
		})
		payload["folder"], payload["space"], payload["spaceId"] = relative, space.Name, space.SpaceID
		if request.Verb == "pin" {
			payload["items"] = folderFiles
		}
	}
	driveRequest := &driveRequest{ID: "dr_" + randomHex(8), Verb: request.Verb, Payload: payload, reply: make(chan cliReply, 1)}
	run.addRequest(driveRequest)
	select {
	case reply := <-driveRequest.reply:
		return reply
	case <-time.After(session.runner.config.DrivePauseLimit):
		if run.takeRequest(driveRequest.ID) != nil {
			return cliReply{ExitCode: 1, Stderr: "drive " + request.Verb + ": no answer from the agent host; nothing changed\n"}
		}
		return <-driveRequest.reply
	case <-run.done:
		if run.takeRequest(driveRequest.ID) != nil {
			return cliReply{ExitCode: 1, Stderr: "drive " + request.Verb + ": the call ended\n"}
		}
		return <-driveRequest.reply
	}
}

// ownerOfProcess finds the foreground exec (by its shell) or the job a process descends from.
func (session *Session) ownerOfProcess(pid int) (*execRun, bool) {
	session.mutex.Lock()
	shells := map[int]*execRun{}
	for _, run := range session.runs {
		select {
		case <-run.done:
		default:
			shells[run.shell.pid] = run
		}
	}
	jobs := map[int]bool{}
	for _, job := range session.jobs {
		jobs[job.pid] = true
	}
	session.mutex.Unlock()
	for current, steps := pid, 0; current > 1 && steps < 256; current, steps = parentPID(current), steps+1 {
		if run := shells[current]; run != nil {
			return run, false
		}
		if jobs[current] {
			return nil, true
		}
	}
	return nil, false
}

func parentPID(pid int) int {
	data, err := os.ReadFile("/proc/" + strconv.Itoa(pid) + "/stat")
	if err != nil {
		return 0
	}
	closing := strings.LastIndexByte(string(data), ')')
	if closing < 0 || closing+2 > len(data) {
		return 0
	}
	fields := strings.Fields(string(data[closing+2:]))
	if len(fields) < 2 {
		return 0
	}
	parent, _ := strconv.Atoi(fields[1])
	return parent
}

// renderStatus is the text of `drive status`.
func renderStatus(status DriveStatus, all bool) string {
	var out strings.Builder
	shown, scratch := 0, 0
	for _, change := range status.Changes {
		if !all && (change.Path == "work" || strings.HasPrefix(change.Path, "work/")) {
			scratch++
			continue
		}
		line := fmt.Sprintf("  %-9s %s", change.Kind, change.Path)
		if change.From != "" {
			line += " (from " + change.From + ")"
		}
		if change.Type == "link" {
			line += " -> " + change.LinkTarget
		}
		if shown == 0 {
			out.WriteString("uncommitted changes:\n")
		}
		out.WriteString(line + "\n")
		shown++
	}
	if shown == 0 {
		out.WriteString("no uncommitted changes\n")
	}
	if scratch > 0 {
		fmt.Fprintf(&out, "  (%d changes below work/ not shown; drive status --all)\n", scratch)
	}
	for _, conflict := range status.Conflicts {
		line := fmt.Sprintf("conflict: %s (%s", conflict.Path, conflict.Kind)
		if conflict.SideFile != "" {
			line += ", markers in " + conflict.SideFile
		}
		out.WriteString(line + ")\n")
	}
	for _, link := range status.RefusedSymlinks {
		fmt.Fprintf(&out, "refused symlink: %s -> %s (%s)\n", link.Path, link.Target, link.Reason)
	}
	if status.NotLocalFiles > 0 {
		fmt.Fprintf(&out, "not local: %d files, %s (listed in %s; drive get <path>)\n", status.NotLocalFiles,
			humanBytes(status.NotLocalBytes), DriveIndexFile)
	}
	for _, mount := range status.Mounts {
		pinned := ""
		if len(mount.Pinned) > 0 {
			pinned = ", pinned: " + strings.Join(mount.Pinned, " ")
		}
		sync := "streamed: every file listed, not-local ones answer ENODATA"
		if !mount.Streamed {
			sync = "not mounted as a file system: only local files are here, the rest is in " + DriveIndexFile
		}
		fmt.Fprintf(&out, "mount %s · %s · %d files, %d local%s · as of change %d · %s\n",
			path.Join(DriveSpacesDir, mount.Name), strings.ToLower(mount.Mode), mount.Files, mount.LocalFiles, pinned,
			mount.Cursor, sync)
	}
	return out.String()
}

func humanBytes(bytes int64) string {
	switch {
	case bytes >= 1<<30:
		return fmt.Sprintf("%.1f GB", float64(bytes)/float64(1<<30))
	case bytes >= 1<<20:
		return fmt.Sprintf("%.1f MB", float64(bytes)/float64(1<<20))
	case bytes >= 1<<10:
		return fmt.Sprintf("%d KB", bytes>>10)
	}
	return fmt.Sprintf("%d B", bytes)
}
