# sandbox-runner

One slot of the agent sandbox: a persistent shell workspace in which an agent (or any PHP caller of
`mgamadeus/ddd-agent-sandbox`) works as it would on a computer. The contract version is the package version: `GET
/healthz` reports it as `contractVersion`, and `app:agent-sandbox:check-runner` compares it with the installed package.

- **One session at a time.** A session binds the runner to an opaque `workspaceKey` and a fresh unprivileged uid. The
  PHP side resolves the key, restores the workspace's files into the slot and saves them back by content hash
  (manifest), or, in drive mode, hands it the drive's working copy (section Drive mode). The runner holds no store
  credentials; its only outbound calls are presigned transfers through the transfer proxy in drive mode.
- **Persistent shells.** Every session has a bash (more on request, by name) that keeps cwd and exported variables
  between calls; background jobs run beside it.
- **Isolation.** gVisor on the node; inside it the runner is root with only `SETUID`, `SETGID` and `KILL`, and every
  session process runs under the session uid with rlimits. No network, no DNS, no service account token, read-only
  root.
- **Go, one static binary** (standard library plus `golang.org/x/sys` and `go-fuse`). The same binary is its own helper: every operation on session
  files (read, write, manifest, wipe) runs as `sandbox-runner __helper …` under the session uid, because the runner
  has no `CAP_DAC_OVERRIDE` or `CAP_CHOWN` and must not have them.

## Contract

HTTP on `PORT` (8080), JSON. Errors are `{"error": "<CODE>", "message": "…"}`; messages never contain commands,
output or file names.

| Call | Answer |
|---|---|
| `GET /healthz` | `200 {"status":"ok","state":"idle\|busy\|releasing","contractVersion","toolkit","imageConfigHash","capabilities":{drive,fuse,transferProxy},"limits":{…},"workspaceMount","memory"}`; `503` with `state: broken` after a failed wipe (the runner takes no more sessions; the liveness probe restarts it, and the start wipes again). `contractVersion` is the package version compiled in (`-X main.contractVersion`), `toolkit` base or full, `imageConfigHash` the hash of a project image's `image.yaml` (empty for the module image) |
| `POST /v1/sessions` `{workspaceKey, env?, files?}` | `201 {sessionId, uid, workspace, restoredFiles}`. `503 RUNNER_BUSY` (with `Retry-After: 2`) while another session holds the runner |
| `POST /v1/sessions/{id}/exec` `{command, cwd?, timeoutSeconds?, background?, shell?}` | foreground: `200` exec result; `background: true`: `202 {jobId, state: "running"}` |
| `GET /v1/sessions/{id}/jobs/{job}` | job view (status, output head and tail so far) |
| `DELETE /v1/sessions/{id}/jobs/{job}` | kills the job's process group; the final job view |
| `GET /v1/sessions/{id}/files/{path}` | the bytes, `application/octet-stream` with `Content-Length` |
| `PUT /v1/sessions/{id}/files/{path}?mode=0644&mtime=…` | body = the bytes, `Content-Length` required; `200 {path, size, sha256}`; `413 QUOTA_EXCEEDED {totalBytes, limitBytes, fileBytes}` when the file would push the workspace past its limit |
| `GET /v1/sessions/{id}/manifest` | every regular file: `{files: [{path, size, sha256, mtime, mode}], count, totalBytes, usedBytes, limitBytes, skippedSymlinks, skippedOther, unreadable?}` |
| `DELETE /v1/sessions/{id}` | kills every process of the uid, wipes `/workspace`, HOME, `/tmp` and `/dev/shm`, frees the runner: `200 {released, durationMs}` |

### Sessions

- `workspaceKey`: opaque, at most 256 characters; logged as an id.
- `env`: variables for every shell and job of the session, e.g. `TZ`, `AGENT_NOW`, `LANG` from the turn's context.
  Refused: `BASH_ENV`, `ENV`, `HOME`, `PATH`, `IFS`, `SHELLOPTS`, `BASHOPTS`, `PROMPT_COMMAND`, `PS4`, `LD_*`,
  `BASH_FUNC_*`.
- `files`: optional inline restore, `[{path, contentBase64, mode?, mtime?}]`, request body at most 64 MiB. Larger
  workspaces are restored with `PUT` per file after the claim. A failed restore releases the session again.
- The uid comes from `RUNNER_UID_BASE` + a rotating counter (20000 … 59999); the next session always gets the next
  uid. The runner releases a session by itself after `RUNNER_SESSION_IDLE_SECONDS` (2 h) without any call, unless a
  call or job is running.

### Exec

- The command runs in a persistent `bash --noprofile --norc` as the session uid: `cd` and `export` stay for the next
  call. It is handed over with `eval`, so a syntax error fails the call (exit 2) and leaves the shell intact. Stdin is
  `/dev/null`.
- `cwd`: relative to `/workspace` or absolute; the shell `cd`s there first, and that persists like any `cd`.
- `shell`: name of the shell, `[A-Za-z0-9_-]{1,64}`, default `default`, at most 4 per session. One command at a time
  per shell; a second answers `409 SHELL_BUSY`.
- `timeoutSeconds`: foreground default 60, at most 180; background default 600, at most 1800. More answers `400`.
- On timeout the runner kills every process the command started (background processes of earlier calls survive)
  and the shell answers with exit 137. If the shell itself does not answer within `RUNNER_KILL_GRACE_SECONDS` (3 s),
  as with a builtin loop, the whole shell is killed: `shellExited: true`, and the next call starts a new shell in the
  last cwd (`shellRestarted: true`; exported variables are lost). `exit` or `exec` in a command ends the shell the
  same way.

```json
{ "seq": 7, "status": "OK", "exitCode": 0, "timedOut": false, "durationMs": 412, "cwd": "/workspace/sub",
  "stdout": { "head": "1\n2\n…", "tail": "…\n100000\n", "bytes": 588895, "truncated": true },
  "stderr": { "head": "", "bytes": 0, "truncated": false },
  "spillPath": "/workspace/.runner/out-7.log", "spillComplete": true }
```

- `status`: `OK`, `TIMED_OUT`, `SHELL_EXITED` (the command ended the shell) or `QUOTA_EXCEEDED` (with `quota`: the
  watcher killed the command, or the end-of-call check found the usage above the quota or "No space left on device"
  in the output; section Quota) or `MEMORY_EXCEEDED` (with `memory`: the session passed its memory limit and every
  process of it ended; section Memory).
- Output: head and tail per stream, 2,000 characters each (`RUNNER_OUTPUT_HEAD_CHARS`, `…_TAIL_CHARS`). A stream that
  fits in 4,000 characters comes whole in `head`. When a stream is truncated, the full output of both streams in
  arrival order goes to `/workspace/.runner/out-<seq>.log` (jobs: `job-<seq>.log`); `spillComplete: false` when the
  file-size limit cut it.
- Output of a background process (`cmd &`) between two calls belongs to the next call of that shell.
- Background jobs (`background: true`) run as their own bash in their own process group, started with the exported
  environment and cwd of the shell named in `shell` (when it is idle). Job view: `{jobId, state: running|exited|
  timedOut|killed|quotaExceeded|memoryExceeded, exitCode, timedOut, durationMs, quota?, memory?, stdout, stderr,
  spillPath?}`. At most 8
  running jobs per session.

### Quota

The workspace is a volume of its own: on the cluster a disk-backed local volume per pod (LVM-LocalPV, generic
ephemeral volume, 4Gi, ext4 or XFS), in local Docker tests a tmpfs. The runner refuses to start when `/workspace` is
not a mount of its own (`RUNNER_REQUIRE_WORKSPACE_MOUNT=0` for development), and `/healthz` reports
`workspaceMount {type, ownMount, sizeBytes, freeBytes}`. Two limits apply; the smaller one wins:

| Limit | Default | Readable as |
|---|---|---|
| The runner's quota, `RUNNER_WORKSPACE_LIMIT_BYTES`, over `RUNNER_QUOTA_DIRS` (default `/workspace,/tmp`; `/tmp` holds HOME) | 3.6 GiB (3,865,470,566 bytes) | `QUOTA_EXCEEDED`, `reason: "LIMIT"` |
| The free space of the workspace mount (statfs), minus `RUNNER_WORKSPACE_FREE_RESERVE_BYTES`; behind it the file system's own `ENOSPC` | reserve 16 MiB | `QUOTA_EXCEEDED`, `reason: "NO_SPACE"` |

`limitBytes` in every report is the effective limit: the quota, or the usage plus the free space above the reserve
when the mount holds less. `quotaBytes` and `freeBytes` come with it.

- **Measure.** A helper under the session uid walks the directories and counts every file once per inode as the
  larger of its allocated blocks and its apparent size (sparse files count in full). Folders the agent closed for
  itself (`chmod 000`) are opened for the walk and get their mode back. HOME is `/tmp/home`, so caches there (Playwright, LibreOffice profiles, matplotlib) count
  under `/tmp`; every report and log line splits the usage `byDirectory` (`{"/workspace": …, "/tmp": …}`). 50,000
  files take about 40 to 50 ms locally (runc) and about 2.0 s under gVisor on the ext4 volume.
- **Watcher.** While an exec or a job runs, it measures every `RUNNER_QUOTA_INTERVAL_MS` (1,000): the usage and the
  free space of the workspace mount. `RUNNER_QUOTA_WATCH_MODE=auto` (default) walks exactly and switches the session
  to statfs (the used bytes of each quota mount above its state at claim) as soon as one walk takes longer than
  `RUNNER_QUOTA_EXACT_BUDGET_MS` (500), so a tick always fits even where gVisor makes stat calls slow; `exact` and
  `statfs` force one way (statfs needs every quota directory to be its own mount). statfs only raises the suspicion:
  before anything is killed an exact walk confirms the excess. ext4 frees the blocks of deleted files only at its next
  journal commit (up to about 5 s), and statfs counts directories and metadata, so right after an `rm` statfs can stand
  far above the real usage; such a false alarm kills nothing, logs `quota statfs estimate above the limit, exact walk
  below: no action` and sets an offset, which an exact walk resets every `RUNNER_QUOTA_RECALIBRATE_TICKS` (10) ticks.
  Past a limit it kills every process of the session uid (jobs end as `quotaExceeded`, shells end) and the interrupted
  exec answers `"status":"QUOTA_EXCEEDED"`; the report comes from the exact walk. It kills only growth: once an excess
  is reported (by the watcher or an end-of-call check), a command that leaves the usage alone or frees space (`ls`, a
  long `rm -rf`) runs to its end and gets `QUOTA_EXCEEDED` from the end-of-call check; more than 1 MiB above the
  reported usage is killed again. The log line `quota exceeded` names
  reason, measure (`exact` or `statfs`), total, effective limit, quota, free space and `byDirectory`. New shells, jobs and the interrupted answers wait
  until the kill is complete. Recovery as after a timeout that ended the shell: the next call starts a new shell in the
  last cwd (`shellRestarted: true`, exported variables lost).
- **End of every exec and job.** One exact measurement, because a burst can start and finish between two ticks. Past
  a limit, or when the output said "No space left on device" (stdout or stderr, also split over chunks), the exec
  answers `QUOTA_EXCEEDED` instead of `OK` and the job ends as `quotaExceeded`, both with their own exit code. Nothing
  is killed: the command has ended, the shell keeps its state, the files stay for deleting. Every further call reports
  `QUOTA_EXCEEDED` until the usage is below the limits again.
- **PUT** measures first and answers `413` before reading the body when the file does not fit the quota (the file it
  replaces is credited) or the free space (not credited: the new file is written beside the old one before the
  rename). A write that still hits `ENOSPC` answers `413` with `reason: "NO_SPACE"`; nothing is committed. Body:
  `{"error":"QUOTA_EXCEEDED","reason":…,"totalBytes":…,"limitBytes":…,"quotaBytes":…,"freeBytes":…,"fileBytes":…,
  "byDirectory":{…},"largest":[…]}`. A refused PUT changes no state: the next exec answers `OK` while the usage stays
  below the limits. It logs `put refused by quota` with the current total, the replaced file's bytes, the requested
  bytes, the limits and `byDirectory`.
- **Report** in exec and job answers: `"quota":{"reason":"LIMIT|NO_SPACE","totalBytes":…,"limitBytes":…,
  "quotaBytes":…,"freeBytes":…,"byDirectory":{…},"largest":[{"path":"/workspace/…","bytes":…}, …]}` with the five
  largest files. `QUOTA_EXCEEDED` holds only while the measured usage of the session (workspace plus `/tmp`) or the
  free space is past a limit.
- **Manifest:** `totalBytes` is the sum of the listed files, `usedBytes` the quota's measure, `limitBytes` the
  effective limit, plus `quotaBytes` and `freeBytes`.
- **`lost+found`.** mkfs.ext4 leaves a root-owned, closed `lost+found` at the root of the volume, on which every
  recursive tool of the session failed (`rg` over `/workspace` answered only its permission error). The runner removes
  it when it is empty: at start, after every wipe and at every claim (ext4 needs it only for fsck; mkfs and fsck create
  it again). A non-empty one (fsck recovered something) stays, closed to 0700 root, and the wipe, the manifest and the
  usage leave it out.
- **Why a disk volume and not tmpfs.** A tmpfs keeps its data in memory with nothing behind it: without swap its pages
  cannot be reclaimed, so a full 2Gi workspace pinned 2Gi of the pod's memory limit for as long as the files existed.
  The pages of a disk-backed volume are page cache: written back to disk and dropped under memory pressure. The
  runner therefore needs 3Gi of memory instead of 4Gi, and the workspace grows to 4Gi. Under gVisor written file data
  still shows in the pod cgroup as `file` memory (reclaimable). The volume's `ENOSPC` is the hard
  limit; the quota at 3.6 GiB and the free-space reserve answer earlier and readably. tmpfs stays the way for local
  Docker tests (`--tmpfs /workspace:…,size=…`); it behaves the same towards the runner.

### Memory

The pod's memory limit is the host's: past it the host's memory cgroup kills `gvisor_sentry` and the whole runner
pod restarts with every session (measured on a cluster and locally under runsc release-20261102.0). The
runner therefore keeps each session below its own limit, `RUNNER_SESSION_MEMORY_LIMIT_BYTES` (default 2 GiB of the
3Gi pod: the rest holds the runner, the Sentry and the 256Mi `/tmp` memory disk; `0` switches it off), measured every
`RUNNER_SESSION_MEMORY_INTERVAL_MS` (50) during the whole session, also between calls (background processes).

- **Measure** (`/healthz` → `memory {measure, limitBytes}`, log `runner started` → `memoryMeasure`): `cgroup-v1` (or
  `cgroup-v2`) where the runner may create a cgroup below `RUNNER_CGROUP_ROOT` (`/sys/fs/cgroup`): gVisor's own
  cgroupfs allows that to root without `CAP_SYS_ADMIN`. Every shell and job helper waits (a sync descriptor,
  `__helper sync=<fd>`) until the runner has placed it in `sandbox-session`, so all it starts is counted. gVisor enforces no
  limit there, but its usage counts what the session holds: anonymous memory once however many forked processes share
  it (a forked 600 MiB Python: cgroup 614 MiB, RSS sum 1.2 GiB), tmpfs files its processes wrote (`/tmp`, `/dev/shm`),
  not the page cache of the 9p workspace volume (1 GiB written: 0). Elsewhere (runc, local tests) `rss`: the VmRSS
  sum of the session uid's processes. `RUNNER_SESSION_MEMORY_MODE=rss` or `off` forces one. Not used: `RLIMIT_AS`
  (Chromium and V8 reserve tens of GiB of address space) and `RLIMIT_DATA` (gVisor does not apply it to anonymous
  `mmap`: 1 GiB allocated under 512 MiB).
- **Excess.** Every process of the session ends (cgroup, then uid), as at a quota excess: the interrupted exec answers
  `"status":"MEMORY_EXCEEDED"` with `"memory":{"usedBytes","limitBytes","measure","largest":[{pid,command,rssBytes}]}`,
  jobs end as `memoryExceeded` with the same block, and stderr of both gets the line `sandbox: memory limit of the
  sandbox session exceeded (… used, limit …): every process of the session was stopped; largest: python3 2.3 GB. Use
  less memory …`. The shell comes back on the next call in its last cwd (`shellRestarted`). When the session stays
  above its limit without a process, its files in `/dev/shm` are removed (`removedShmBytes`); a session that stays
  above without growing (more than 16 MiB) is not stopped again. Log `memory limit exceeded` with bytes only.
- **Measured** (local runsc, 3g container cgroup without swap): a 3.5 GB Python allocation without the limit: the
  host cgroup killed the sandbox (the "pod"); with it: `MEMORY_EXCEEDED` at 2.42 to 2.51 GB (the allocation runs at
  several GB/s, so one tick overshoots by up to about 0.4 GB), runner and container stay.

### Session isolation (seccomp)

Every process of a session runs under a seccomp filter the runner's helper installs (with `no_new_privs`, on all its
threads) right before it executes the session's bash, and before every other helper that runs as the session uid
(`git diff`/`merge-file` of drive mode read the session's HOME config). The filter is inherited by everything the
session starts and cannot be removed. gVisor confines the sandbox as a whole, but without it a session shell could
`unshare --user --mount --map-root-user` and, as root of its own namespace, mount tmpfs or a FUSE of its own.

| Refused | Answer |
|---|---|
| `unshare` and `clone` with any namespace flag (user, mount, net, pid, uts, ipc, cgroup, time) | `EPERM` |
| `setns`, `mount`, `umount2`, `pivot_root`, `chroot`, the new mount API (`open_tree`, `move_mount`, `fsopen`, `fsconfig`, `fsmount`, `fspick`, `mount_setattr`) | `EPERM` |
| `keyctl`, `add_key`, `request_key`, `bpf`, `perf_event_open`, `ptrace` (no tool of the toolkit traces) | `EPERM` |
| `clone3` (its flags sit in a struct a filter cannot read; glibc's fork, `posix_spawn` and `pthread_create` fall back to `clone`) | `ENOSYS` |
| another architecture (i386, x32, arm32) | `ENOSYS` |

`RUNNER_SESSION_SECCOMP=0` switches it off (development only). Checked locally under runc and runsc
release-20261102.0: `unshare -U true` fails with `Operation not permitted`, `/proc/self/status` shows `Seccomp: 2`,
and Python threads and subprocesses, git, rg, Playwright (Python and Node), kaleido and LibreOffice work (integration
full 73/73 in both).

### Files

- Paths are relative to `/workspace`. Refused with `400 PATH_REFUSED`: absolute paths, any `..` segment, NUL,
  backslash, and every symlink that leads out of the workspace (Go's `os.Root`, race-free). The last segment is
  opened with `O_NOFOLLOW`; a symlink there answers `409` or `400`. A `PUT` replaces a symlink at the target path by
  the file itself (rename, never follows).
- `PUT` writes into a temporary file next to the target and renames it into place only when exactly
  `Content-Length` bytes arrived; parents are created. `mode` is octal (`0755`), `mtime` RFC 3339 or Unix
  nanoseconds, so a restore reproduces the manifest.
- Statuses: `404 FILE_NOT_FOUND`, `409 NOT_A_REGULAR_FILE`, `413 FILE_TOO_LARGE` (`RUNNER_MAX_FILE_BYTES`, 1 GiB),
  `413 QUOTA_EXCEEDED` (quota or `ENOSPC`, section Quota), `411 LENGTH_REQUIRED`, `400 INCOMPLETE_BODY`.
- The manifest leaves out `.runner/` (spilled output is session-only) and symlinks (counted). Files the agent made
  unreadable for itself are listed in `unreadable`.

### Administration

Reads for the admin screen "Sandboxen" (superadmins, through PHP's `AgentSandboxAdministration`). Same access as
every other call (the NetworkPolicy: app and worker pods only). They never count as activity of the session (the idle
release stays as it was; `lastActivityAt` does not move), never answer the session's `env`, and pass every command,
argv and output tail through the secret mask: each value of the claim's `env` with at least 4 characters
(`SecretMinChars`; shorter ones carry no secret and would mask ordinary output, e.g. `de`) becomes `***`, the longest
value first. Event details hold codes and numbers only.

| Call | Answer |
|---|---|
| `GET /v1/sessions` | `200 {pod, state, sessions: [{sessionId, workspaceKey, createdAt, lastActivityAt, memoryBytes, memoryPeakBytes, memoryLimitBytes, memoryMeasure, diskUsedBytes, diskQuotaBytes, diskMeasure, mountMode: FUSE\|PLAIN, mountModeReason, mode: legacy\|drive, pod, runningJobCount, runningExecCount, shellCount}]}`: none or one (one session per runner). Disk usage by statfs where every quota directory is its own mount (`diskMeasure: statfs`), else the exact walk; memory is measured now, the peak is the highest of every 50 ms tick |
| `GET /v1/sessions/{id}/processes` | `{processes: [{pid, argv, truncated, startedAt, cpuSeconds, rssBytes, jobId?}]}`: every process of the session (cgroup or uid), `argv` from `/proc/<pid>/cmdline` joined by spaces and cut to 512 characters (never `environ`); `jobId` when a job's bash is an ancestor |
| `GET /v1/sessions/{id}/execs?limit=` | `{execs: [{seq, command, shell, startedAt, durationMs, status, exitCode, stdoutTail, stderrTail, truncated, jobId?}]}`, newest first, the last 100 (`limit` 1 … 100). Foreground execs and jobs; `status` is `running`, the exec status (`OK`, `TIMED_OUT`, …) or the job state; `command` at most 2 KB, each tail at most 8 KB (the end of the stream, or the runner's 2,000-character tail of a longer one); a running entry shows its output so far |
| `GET /v1/sessions/{id}/events?limit=` | `{events: [{seq, occurredAt, type, detail}]}`, newest first, the last 200: `SESSION_CLAIMED`, `TIMEOUT`, `SHELL_EXITED`, `JOB_KILLED`, `QUOTA_EXCEEDED` (watcher or end-of-call check), `MEMORY_EXCEEDED`, `OOM_SUSPECTED` (exit 137 without a runner timeout, limit or kill), `FUSE_LOST`. Not observable: a syscall the seccomp filter refused (it answers `EPERM`/`ENOSYS` silently, nothing reaches the runner) |
| `GET /v1/sessions/{id}/disk` | `{usedBytes, quotaBytes, limitBytes, freeBytes, byDirectory}` from the exact walk |
| `GET /v1/sessions/{id}/files?path=` | `{path, entries: [{name, type: file\|dir\|symlink\|other, size, mtime?, notLocal?}], truncated}`: one directory (empty path: the root), folders first, at most 2,000 entries. Listed as the session uid through `os.Root`: `..`, absolute paths and symlinks that lead out answer `400 PATH_REFUSED`, a file `409 NOT_A_DIRECTORY`, a missing folder `404`. The runner's own folder `.runner` (and `lost+found`) is left out of the root listing; listing it, a folder below it or a symlink that leads into it answers `400 PATH_REFUSED`. Below `drivespaces/` of a streamed session the listing comes from the drive state (the mounts' files with `notLocal`, `SPACE.md`, `.drive-index`), since outside the session's namespace the folder is empty |

Killing a job stays `DELETE …/jobs/{job}`, ending the session `DELETE /v1/sessions/{id}`; the file bytes stay
`GET …/files/{path}`. A read of the runner's own folder (`.runner/**`: spilled output, conflict markers; also through
a workspace symlink that leads there) comes back with the claim's env masked as `***`, like the tails of
`…/execs`; every other file is served as stored.

## Drive mode

The placeholder FUSE for mounts (below), Plan B where it cannot mount. A claim with `mode: "drive"` makes
`/workspace` the working copy of the agentSpace's own drive space; without it the legacy manifest and restore protocol
above applies unchanged (the default until the PHP side switches).

- **State outside the tree.** `RUNNER_DRIVE_STATE_DIR` (default `/tmp/.drive-state`, root, 0700) holds `state.json`
  (per path: node id, version id, sha256, size, local or not, conflict; per space: pinned folders and cursor) and
  `cache/<sha256>` (read-only blobs: merge and diff bases, staged snapshots). On the cluster it belongs on the session
  volume, beside the tree (a second `subPath` mount of the workspace volume); `/tmp` is a 256Mi memory disk. The quota
  walk of the session cannot read it, so the cache does not count against the workspace quota; the mirror budget
  covers tree plus cache. Release empties the directory and keeps it (it may be a mount point); a claim empties it
  again first and logs `drive state leftovers removed at claim` with a count. A failed emptying breaks the runner
  like a failed wipe.
- **Layout.** `drivespaces/<name>/` per mount, read-only for the session, with a generated `SPACE.md` (claim
  `spaceMd`) and `.drive-index`. `/workspace` gets the sticky bit, so the session can neither move nor replace them.
  Local in a mount are pinned folders, fetched files (`drive get`) and small text files (`.md`, `.csv`, `.json`, … up
  to `RUNNER_DRIVE_MOUNT_TEXT_MAX_BYTES`, 256 KiB); in the own drive everything up to the threshold (claim
  `thresholdBytes`, default 20 MB) plus pinned folders. The rest is listed in `.drive-index` (TSV: path, size, node
  id, version id, sha256) at the workspace root and per mount.
- **Placeholder FUSE (mounts).** Every file of a mounted space is visible with its size (`ls`, `find`, `stat`); a local
  one is read from the root-owned cache, opening one that is not local fails with `ENODATA` ("No data available",
  errno 61) and is recorded for the hint of the running exec or job. Read-only (`EROFS`), mode 0444/0555, all dates
  the time the file system started (the snapshot carries none). The tree holds nothing below `drivespaces/` then, the
  cache keeps the blobs of local mount files (they count once in the mirror budget), `SPACE.md` gets the line `sync:
  streamed …`, and `GET …/files/drivespaces/…` answers local mount files from the cache. How, without
  `CAP_SYS_ADMIN` (Pod Security baseline): at the first drive claim with mounts the
  runner starts a holder (`sandbox-runner __fuse-holder`) in a new user and mount namespace whose maps the runner
  writes: inner 0 → outer `RUNNER_FUSE_HOLDER_UID` (100000; outer 0 cannot be mapped without `CAP_SETFCAP`), the
  session uid range onto itself. The holder, root inside, mounts FUSE once at `RUNNER_FUSE_ROOT` (`/tmp/.sandbox-fuse`,
  `allow_other,default_permissions`, read-only, nosuid, nodev) and passes the `/dev/fuse` descriptor to the runner,
  which serves it with go-fuse (v2.12.1) for its whole life; per session the holder binds the root onto
  `/workspace/drivespaces` and removes the bind at release. One connection per runner, because under gVisor nothing
  wakes a thread blocked in `read` on `/dev/fuse` (close, unmount, end of the namespace): a connection per session left
  about three threads per session behind. Shells and jobs start through `nsenter --user --mount --setuid --setgid`
  (`RUNNER_NSENTER`, util-linux; Go cannot `setns` into a user namespace from its multi-threaded process) as the
  session uid inside that namespace; the helper then installs the seccomp filter. The runner and every helper outside
  see the plain, empty folder; the quota walk never counts the mounts. `RUNNER_DRIVE_FUSE=0` switches it off.
- **Plan B (fallback).** When the holder or the mount fails (no `/dev/fuse`, user namespaces refused, as under
  Docker's default seccomp profile locally), the runner logs `placeholder FUSE not mounted; drive mounts fall back to
  Plan B` once and copies the local mount files into the tree as root-owned 0444 files; the rest is only in
  `.drive-index`. `drive status` and `GET …/drive/status` say which (`mountFilesystem`: `fuse`, `plain` or `plain:
  <reason>`; per mount `streamed`; the CLI line ends with `streamed: every file listed, not-local ones answer ENODATA`
  or `not mounted as a file system: …`).
- **Inodes.** A tree file is always an independent copy of a cache blob (a reflink where the file system offers one,
  never a hardlink). Blobs cross between root and session uid as open descriptors handed to the helper.
- **Excluded from sync:** the claim's `ignore` (default `.venv`, `node_modules`, `__pycache__`, `.cache`, `.git`,
  `/tmp`; gitignore style, no negation) plus `.driveignore` at the root; always `.runner`, `drivespaces`, `mounts`,
  `.drive-index`.
- **Symlinks.** A link that resolves inside the own drive is a link node; one held by the base map is known; every
  other (outside `/workspace`, into a mount or an excluded area) is listed in `refusedSymlinks` and never committed.
- **Hints.** After every exec and job the runner adds `notLocal: [{path, size}]` (at most 10), `notLocalCount`,
  `notLocalBytes` and `notLocalSource`. Streamed mounts: the files the call really opened (the FUSE miss log, by the
  calling process's ancestry: its shell or job; `notLocalSource: "fuse"`), paths built at run time included. The own
  drive and Plan B mounts: path words and globs of the command, resolved against its starting cwd, matched against
  the index (`"command"`): paths built at run time are missed, and a named file the command never opened still
  yields a hint.
- **Mirror budget** (claim `mirrorBudgetBytes`, default 1.5 GiB): at claim and every pull, while tree plus cache are
  above it, the least recently used files become index entries: unpinned mount files first, then own files outside
  `lib/`, `NOTES.md` and pinned folders. A locally changed file is never dropped.
- **The `drive` CLI** (the runner binary as `/usr/local/bin/drive`, socket `RUNNER_DRIVE_SOCKET`, default
  `/tmp/.drive.sock`, `DRIVE_SOCKET` in the session): `status [--all] [--json]` and `diff <path>` (`git diff
  --no-index` against the cached base) answer at once. `commit -m <msg> [path…]`, `pull`, `get <path>…`, `pin
  <folder>`, `unpin <folder>` pause the foreground exec (below); in a background job they fail at once with a hint
  to use the tool. Paths are relative to the cwd, absolute below `/workspace`, or `drive/<nodeId>`.
- **Transfers.** `proxy` (the default once `RUNNER_TRANSFER_PROXY_URL` is set; dev:
  `http://transfer-proxy.agent-sandbox-proxy-dev.svc:8080`, resolved through a hostAlias): the runner GETs and PUTs
  presigned URLs itself in absolute form (`https` becomes `http`, the proxy opens TLS upstream) with
  `X-Transfer-Token: <token>`, which the proxy strips. The token comes from `RUNNER_TRANSFER_PROXY_TOKEN_FILE`
  (root:root 0400; `RUNNER_TRANSFER_PROXY_TOKEN` for local runs only), lives in the runner's memory and never in a
  session's env or argv; session processes reach the proxy but get 403 without it. The headers PHP signed into a
  URL (`If-None-Match: *`, `Content-MD5`) are sent exactly as given; a `Content-MD5` that does not match the snapshot
  fails the item (`CONTENT_MD5_MISMATCH`) before anything is sent. `stream`: PHP pushes and reads blobs through the
  runner. A claim gets `proxy` when a proxy is configured, unless it asks for `stream`.

### Protocol (drive mode)

Every path is workspace-relative; mount paths are `drivespaces/<name>/…`. A change is
`{op: create|update|delete|move, path, fromPath?, nodeId, versionId, sha256, size, mode?, contentType?, linkTarget?,
url?}` (`url`: presigned GET, proxy transfer; without it the blob must be cached). Results of pull, materialize,
resolve and the claim share one shape: `{target, cursor, applied[], merged[], conflicts[{path, kind: both-changed|
trashed, theirsVersionId, theirsSha256, sideFile?, binary?, baseVersionId}], needBlobs[{sha256, size, paths}],
failed[{path, reason}], evicted[], skipped}`. `needBlobs`: push them (stream), then repeat the call; pulls and
materialisations are idempotent.

| Call | Body | Answer |
|---|---|---|
| `POST /v1/sessions` | `{workspaceKey, env?, mode: "drive", drive: {spaceId, cursor, thresholdBytes?, mirrorBudgetBytes?, ignore?, transfer?, pinned?, entries: [change + working?: {sha256, size, mode?, url?, deleted?}], mounts: [{name, spaceId, mode: READ_ONLY\|READ_WRITE\|WRITE_WITH_CONSENT, spaceMd, cursor, pinned?, entries: [change]}]}}` | `201 {sessionId, uid, workspace, mode, transfer: proxy\|stream, drive: result}`. `entries` is the base map (latest written versions); `working` overlays a saved, uncommitted state. Entries whose blob is missing stay pending |
| `PUT …/drive/blobs/{sha256}` | the bytes (`Content-Length`) | `200 {sha256, cached}`; `400 HASH_MISMATCH` |
| `GET …/drive/blobs/{sha256}` | | the bytes of a cached blob (staged snapshot or base) |
| `POST …/drive/materialize` | `{items: [{path, url?}]}`; empty: every pending entry | result |
| `GET …/drive/status` | | `{changes: [{path, kind: added\|modified\|deleted\|renamed, from?, type: file\|link, sha256, size, mode, nodeId, baseVersionId, linkTarget?, linkResolved?, wasNotLocal?}], conflicts, refusedSymlinks: [{path, target, reason}], notLocalFiles, notLocalBytes, mounts: [{name, spaceId, mode, cursor, pinned, files, localFiles, notLocalFiles}], budget, cursor, transfer}` |
| `POST …/drive/stage` | | the status, every changed file snapshotted under the `sha256` it names, plus `snapshots`. The save step: upload from these snapshots only |
| `POST …/drive/upload` | `{uploads: [{sha256, url, headers?, offset?, length?}]}` (proxy; `headers`: exactly the signed ones; `offset`/`length` for a multipart part) | `{uploads: [{sha256, offset, ok, statusCode, etag, error?}]}`; `409 NO_TRANSFER_PROXY` in stream transfer |
| `POST …/drive/commit` | `{committed: [change], deleted: [path], conflicts: [{path, theirsNodeId, theirsVersionId, theirsSha256, theirsSize}]}` | the status; the base moves for committed and deleted paths (a committed node id at a new path is a rename) |
| `POST …/drive/pull` | `{target: ""\|own\|<mount>, cursor, changes: [change], pinned?}` | result. Untouched locally: replaced. Both changed, text up to `RUNNER_DRIVE_MERGE_MAX_BYTES` (4 MiB): `git merge-file` as the session uid; clean → `merged` (a local change on the new base), overlapping → conflict with markers in `.runner/conflicts/<path>`. Binary, large or without a cached base: conflict. Deleted remotely, changed locally: `trashed` conflict. Nothing is overwritten |
| `POST …/drive/merge` | `{path, theirs: change}` | `{result: merged\|conflict\|binary, conflicts, sideFile, sha256, size, needBlobs, failed}` |
| `POST …/drive/resolve` | `{path, resolution: mine\|theirs\|both, name?, url?}` | result (`both`: the local file moves to `name`, theirs takes the path) |
| `POST …/drive/pin` | `{folder, pinned, items?}` | result (unpin drops mount contents to the index) |
| exec answer | | adds `notLocal…` (above); `status: "DRIVE_REQUESTS"` with `driveRequests: [{requestId, verb, payload}]`, `pausedMs` while the CLI waits |
| `POST …/execs/{seq}/continue` | `{responses: [{requestId, ok, message?, pulls?: [pull body], materialize?: [{path, url?}], commit?: commit body}]}` | the exec answer: final, or `DRIVE_REQUESTS` again. `400 UNKNOWN_REQUEST` applies nothing |

Request payloads: `commit {message, paths, changes (staged, as in stage), refusedSymlinks, conflicts}`, `pull {}`,
`get {items: [{path, space, spaceId, nodeId, versionId, sha256, size}]}`, `pin {folder, space, spaceId, items}`,
`unpin {folder, space, spaceId}`. The runner applies the response's blocks, prints `message` and its summary to the
CLI (`ok: false`: `message` on stderr, exit 1) and resumes the exec timer. A request without an answer fails after
`RUNNER_DRIVE_PAUSE_SECONDS` (300). `pin` records the folder once answered `ok`.

## Limits

| Limit | Value | Setting |
|---|---|---|
| Processes and threads of the session uid | 512 (`RLIMIT_NPROC`) | `RUNNER_MAX_PROCESSES` |
| Open files per process | 4,096 (`RLIMIT_NOFILE`) | `RUNNER_MAX_OPEN_FILES` |
| File size | 1 GiB (`RLIMIT_FSIZE`), also the `PUT` limit | `RUNNER_MAX_FILE_BYTES` |
| Core files | none | — |
| Foreground / background wall time | 60 s default, 180 s max / 600 s default, 1800 s max | `RUNNER_DEFAULT_TIMEOUT_SECONDS`, `RUNNER_MAX_TIMEOUT_SECONDS`, `…_JOB_…` |
| Output in the answer | 2,000 + 2,000 characters per stream | `RUNNER_OUTPUT_HEAD_CHARS`, `RUNNER_OUTPUT_TAIL_CHARS` |
| Shells / running jobs per session | 4 / 8 | `RUNNER_MAX_SHELLS`, `RUNNER_MAX_JOBS` |
| Idle release | 2 h | `RUNNER_SESSION_IDLE_SECONDS` |
| Disk usage of `/workspace` and `/tmp` | 3.6 GiB or the workspace mount's free space minus 16 MiB, whichever is smaller; measured every second during execs and jobs, at the end of each, and before every `PUT` | `RUNNER_WORKSPACE_LIMIT_BYTES`, `RUNNER_WORKSPACE_FREE_RESERVE_BYTES`, `RUNNER_QUOTA_DIRS`, `RUNNER_QUOTA_INTERVAL_MS`, `RUNNER_QUOTA_WATCH_MODE`, `RUNNER_QUOTA_EXACT_BUDGET_MS`, `RUNNER_QUOTA_RECALIBRATE_TICKS` |
| Memory of a session (cgroup usage or RSS sum) | 2 GiB, checked every 50 ms; above it every process of the session ends (`MEMORY_EXCEEDED`) | `RUNNER_SESSION_MEMORY_LIMIT_BYTES`, `RUNNER_SESSION_MEMORY_INTERVAL_MS`, `RUNNER_SESSION_MEMORY_MODE` |
| Memory, CPU, disk | the pod's limits (below) | — |

Session environment: `PATH`, `HOME=/tmp/home` (memory disk, wiped), `XDG_CACHE_HOME` and `XDG_CONFIG_HOME` below it,
`PYTHONPYCACHEPREFIX` (no `__pycache__` in the workspace), `MPLCONFIGDIR`, `WORKSPACE=/workspace`, `LANG=C.UTF-8`,
`TZ=UTC`, `TMPDIR=/tmp`, the image variables named in `RUNNER_SESSION_PASS_ENV`, then the session's `env`. Nothing
else of the runner's own environment reaches a session.

`RUNNER_SESSION_PASS_ENV` (default `PLAYWRIGHT_BROWSERS_PATH,BROWSER_PATH`) lists the image ENV a tool needs at run
time; the runner copies each one that is set. In `full`: `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers` (Playwright for
Python and Node; without it they look in `$HOME/.cache/ms-playwright` and fail with "Executable doesn't exist") and
`BROWSER_PATH=/usr/local/bin/chrome-headless-shell` (kaleido, plotly's picture export; without it the export hangs).
The other image ENV is build-only (`PYTHON_VERSION`, `GPG_KEY`, `PIP_*`, `DEBIAN_FRONTEND`) or set by the runner
anyway (`PATH`, `LANG`). A new tool that reads a variable at run time gets it in the Dockerfile and in this list.

## Logs

JSON lines on stdout: `session claimed`, `exec`, `job started`, `job ended`, `file written`, `manifest`,
`session released` with session id, uid, workspace key, sequence numbers, durations, exit codes, states and byte
counts. Never commands, output or file names.

## Image

One Dockerfile, two toolkits (build argument `TOOLKIT`). Layers in the order of the toolkit list, volatile on top: system and
fonts, LibreOffice, Chromium, Node, Python packages, runner. Pins: base images by digest, Debian packages through
`apt-get --snapshot` (`DEBIAN_SNAPSHOT`, Debian 13.7 state of 2026-11-20), Python packages exact in
`requirements-base.txt` / `requirements-full.txt`, Node packages in `node/package-lock.json`. `pip` is removed after
the install and `npm` never enters the image: nothing can be installed at run time. No setuid binaries. `tini` is
PID 1 and reaps orphaned processes.

| Toolkit | Contents | Size (linux/arm64, local build 2026-11-22) |
|---|---|---|
| `base` (default) | Debian 13.7 slim, bash, coreutils, procps, ripgrep 14.1, jq 1.7, file; CPython 3.13.18 with openpyxl 3.1.5, pandas 3.1.1, python-docx 1.2.0 (numpy 2.5.5, lxml 6.1.3) | 339 MB unpacked, 100 MB compressed |
| `full` | `base` plus pandoc 3.1, poppler-utils 25.03, qpdf 12.2, ImageMagick 7.1 (restricted policy), libvips 8.16, ffmpeg 7.1, tesseract 5.5 (deu, eng), git, sqlite3, fd, 7zip, zip; fonts Liberation, Carlito, Caladea, DejaVu, Noto (+ CJK, emoji); LibreOffice 25.2 without GUI and Java; Chromium headless shell 159 (Playwright revision 1255) at `/opt/pw-browsers`; Node 24.22 with docx, pptxgenjs, exceljs, pdf-lib, pdfjs-dist, sharp, react, react-dom, react-icons, playwright 1.65.1, typescript 7, tsx (`/node_modules` → `/opt/node/node_modules`); the full Python list (91 pinned packages, playwright 1.65.2 on the same browser revision) | 3.58 GB unpacked, 1.28 GB compressed (the largest parts: Python packages 1.29 GB, system libraries 0.6 GB, Node modules 0.35 GB, Chromium 0.29 GB, LibreOffice 0.25 GB) |

`soffice` (and `libreoffice`) is a wrapper (`bin/soffice`) that gives every call a fresh profile below HOME: session
uids have no passwd entry, and without it LibreOffice stops with "User installation could not be completed".
`test/smoke-full.sh` checks the heavy tools (LibreOffice docx to PDF, the headless shell, Playwright, Node and tsx
imports, ImageMagick, libvips, tesseract, ffmpeg, the full Python import list, no pip or npm). Not yet in `full`: the
website template (Vite, Tailwind, shadcn) and the `/skills` folder. Python comes from the official image below `/usr/local`.

Images: `ghcr.io/ddd-framework/ddd-agent-sandbox-runner:<version>` (base) and `:<version>-full`, built by the release
workflow of this repository on the tag `v<version>`. Project software (fonts, packages) is added on top through the
project's `image.yaml` (`app:agent-sandbox:build-image`), never by hand.

## Run locally

```bash
docker build -t ddd-agent-sandbox-runner:base runner
docker build -t ddd-agent-sandbox-runner:full --build-arg TOOLKIT=full runner
runner/test/integration.sh ddd-agent-sandbox-runner:base     # 78 checks under runc (Plan B, 8 of them the administration reads); 85 where the placeholder FUSE mounts (17 of drive mode; the state dir is its own tmpfs mount, as on the cluster), --network none, caps as in k8s
runner/test/integration.sh ddd-agent-sandbox-runner:full     # runc 74, runsc with FUSE 82; plus Playwright (Python, Node), kaleido, LibreOffice in a session
RUNNER_TEST_EXACT_BUDGET_MS=10 runner/test/integration.sh ddd-agent-sandbox-runner:base   # the statfs watcher locally
```

- `RUNNER_TEST_EXPECT_MOUNT_TYPE`: also check the workspace mount's type (`tmpfs` locally, `9p` under gVisor, where the
  gofer hands the ext4 volume through); without it the check is `ownMount` and a size above 0.
- `RUNNER_TEST_EXPECT_MOUNT_FS=fuse`: also check that the placeholder FUSE mounted (cluster, runsc); without it the
  FUSE checks run only when it did, and Docker's default seccomp profile (runc) makes the runner fall back to Plan B.
- `RUNNER_TEST_KEEP=1`: leave the containers for inspection.
- `RUNNER_TEST_EXACT_BUDGET_MS` (500): the walk budget of the main runner. A 50,000-file walk within it passes as
  is; above it the check is that the watcher switched to statfs (log line `quota watcher uses statfs`).
- `lost+found` (ext4) is ignored where the script expects an empty workspace. The script plants one (root, 0700) to
  check that `rg -l x /workspace` as the session uid gives no permission error and that a non-empty one stays at
  0700.

The smoke of the full toolkit: see the header of `test/smoke-full.sh`.

Unit tests (Go; no local Go needed): the image build runs them as root. Dependencies: `golang.org/x/sys` (seccomp)
and `github.com/hanwen/go-fuse/v2` (placeholder FUSE), pinned in `go.sum`. By hand (add `CGO_ENABLED=1 go test -race` for the race detector, about 4 minutes):

```bash
docker run --rm -v "$PWD/runner:/src" -w /src -e GOFLAGS=-buildvcs=false golang:1.27.4-trixie go test -count=1 ./...
```

A runner outside a container must run as root to switch uids; `RUNNER_ALLOW_UNPRIVILEGED=1` runs every session under
the runner's own uid, for development only.

## Kubernetes

The Kustomize base in `deploy/k8s/` of this repository (namespace, runner pool, headless Service, NetworkPolicy, Pod
Security, RuntimeClass `gvisor`); `app:agent-sandbox:render-deploy` writes a project's overlay from its config (image
tag, pool size, limits, the network switch).
