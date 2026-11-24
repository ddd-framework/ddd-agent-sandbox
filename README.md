# mgamadeus/ddd-agent-sandbox

The agent sandbox for [mgamadeus/ddd](https://github.com/ddd-framework/ddd): isolated runner sessions in which an
agent (or any PHP caller) works on a persistent workspace as it would on a computer — a shell, Python with the office
libraries, file tools — without network and without access to anything but its workspace.

- **Runner** (`runner/`, Go): one session per pod, gVisor on the node, every session process under its own uid with
  rlimits, seccomp and a memory cgroup, a disk quota, persistent shells and background jobs. Image
  `ghcr.io/ddd-framework/ddd-agent-sandbox-runner:<version>` (toolkit base) and `:<version>-full` (LibreOffice,
  Chromium headless shell, Node, Typst, fonts), released with the same tag as the Composer package. `GET /healthz`
  reports `contractVersion` (= the package version), `toolkit`, `imageConfigHash`, `capabilities` and `limits`.
- **PHP core** (`DDD\Domain\AI\…\Sandboxes`): `SandboxRunnerClientService` (the HTTP contract), `SandboxWorkspacesService`
  (workspaces by explicit key: claim and restore, write-behind save, idle release, capacity, expiry, preload),
  the port `SandboxWorkspaceStore` with the built-in manifest store (manifest and contents by sha256 in an object
  store), `SandboxSessionRulesService` (pool, timeouts, background jobs, network per workspace), the image and deploy
  renderers, and the administration with its audit table and endpoints.
- **Agent part** (only beside `mgamadeus/ddd-agents` 0.3.x): the MCP package `agent.sandbox.shell`
  (`shell`, `readFile`, `writeFile`, `editFile`, `searchFiles`, `viewWorkspaceImages`), the skill
  `Agent.Sandbox.Shell` and the document skills `Agent.Sandbox.Documents` → `.Pptx`, `.Pdf`, `.Excel`, the workspace of
  a call (`space-<agentSpaceId>`, `account-<accountId>`, an eval run's key), the flush at a settled turn, the state
  note, the eval participant and the workspace activity of the admin screen. Gated by the account right `useSandbox`
  (`SandboxAccountRights::USE_SANDBOX`).
- **Drive store** (only beside ddd-agents and `mgamadeus/ddd-clouddrive` 0.2.x; `AI.Sandbox.workspaces.store` =
  `drive`): the workspace of an agent space IS the working copy of its own drive (see "Drive store" below).
- **Frontend** (`web/`, npm `@mgamadeus/ddd-agent-sandbox`): the RTK Query client of the admin routes and the admin
  screens (`./screen`) on the blocks of `@mgamadeus/ddd-admin-ui`.

## Installation

1. `composer require mgamadeus/ddd-agent-sandbox`; for the admin screen `pnpm add @mgamadeus/ddd-agent-sandbox`.
2. The runner pool: render the overlay of `deploy/k8s/base` (namespace, image, replicas, limits, callers) with
   `php bin/console app:agent-sandbox:render-deploy --output=deploy/sandbox/<stage>` and apply it
   (`kubectl apply -k deploy/sandbox/<stage>`). The nodes need gVisor (RuntimeClass `gvisor`) and a StorageClass
   `sandbox-workspace` with a hard size limit per volume; the pods pull with the `ghcr-pull` secret.
3. Configuration (each file replaces the module's file as a whole, keep every key):
   - `config/app/AI/Sandbox/runner.php`: `enabled` / `AGENT_SANDBOX_ENABLED`, `host` / `AGENT_SANDBOX_RUNNER_HOST`
     (the headless Service, e.g. `sandbox-runner.sandbox.svc.cluster.local`), `port` / `AGENT_SANDBOX_RUNNER_PORT`,
     `pools`, `cacheGroup` (shared across hosts, Redis), `timezone`, timeout caps, `network`, `toolkitNote`, `deploy`.
   - `config/app/AI/Sandbox/workspaces.php`: `store` (`manifest` or `drive`), `objectStore` (`sandbox-workspaces`), `keyPrefix`,
     `idleReleaseSeconds`, `maxSyncedFileBytes`, `uploadInBackground`, `storedWorkspaceQuotaBytes`, the state note,
     the content cache, `preloadDirectory`.
   - An object store `sandbox-workspaces` (`config/app/Common/ObjectStorage.php`, `OBJECT_STORAGE_SANDBOX_WORKSPACES_*`).
4. In the deploy: `app:agent-sandbox:check-runner` (exit 1 on a problem) and `app:agent-sandbox:seed` (the preload).
5. The table `SandboxActionLogEntries` through the schema diff (`app:entity:show-sql SandboxActionLogEntry`).
6. Two worker loops: `app:agent-sandbox:sessions --sweep` every minute and `app:agent-sandbox:sessions --expire` once
   a day. Before a runner rollout: `app:agent-sandbox:sessions --offload`.
7. Admin: one controller with `use SandboxesControllerTrait;` (operators only; the access hook
   `throwForbiddenUnlessSandboxesAccessAllowed()`), and the screens from `@mgamadeus/ddd-agent-sandbox/screen` with
   `createSandboxesApi({ baseApi, apiPrefix })`.
8. With ddd-agents: bind the skill in `config/app/AI/skillPackages.php`
   (`'Agent.Sandbox.Shell' => [SandboxShellMCPPackage::class]`, the document skills to `[]`) and gate it in
   `skillRequirements.php`. The package requires the account right `useSandbox` (ddd-agents maps it to roles in
   `AI.accountRights`, by default the superadmins) and an admin with access to all resources
   (`MCPRequiresAdminWithAccessToAllResources`).
9. With ddd-clouddrive: `store` = `drive`, the transfer proxy beside the runner pool (`RUNNER_TRANSFER_PROXY_URL`), and
   once `app:agent-sandbox:migrate-to-drive` (see "Drive store").

## A project's sandbox

- **Installed software: `config/app/AI/Sandbox/image.yaml`** — `base` (base or full), `apt`, `python` (exact pins
  `name==1.2.3`), `npm` (exact pins, full only), `fonts` (paths or globs), `files` (project path → absolute image
  path), `env`. `app:agent-sandbox:build-image --image=<registry path> --build --push` writes the Dockerfile
  (`FROM` the module image of the installed version) and builds it; the tag is `<module version>-<config hash>`.
  The hash is written into the image and reported by `/healthz`; `check-runner` fails on drift.
- **Preloaded content: a folder, default `resources/Sandbox/preload/`** — templates, kits, sample files.
  `app:agent-sandbox:seed` makes the store equal to the folder; every claim restores it read-only below
  `$SANDBOX_PRELOAD` (`/workspace/.preload`), never saved; a `start.sh` at its top runs once per session.
- **Runtime rules** — `SandboxSessionRulesService` answers per workspace: pool, timeout caps, background jobs,
  session environment. Override it (same-path subclass) for rules per agent space or role.
- **Network** — `AI.Sandbox.runner.network.mode`: `off` (default; the NetworkPolicy lets nothing out) or `allowList`
  (the sessions get `HTTP(S)_PROXY` of the egress proxy component `deploy/k8s/egress-proxy`, which lets only
  `allowedHosts` through). `check-runner --isolation` probes from inside a session.
- **Capacity** — one session per pod; when every pod is busy the idle sessions are released once, then the call is
  refused clearly (no queue).

## Drive store

Beside ddd-agents and ddd-clouddrive 0.2.x (Composer `conflict` outside that range), `AI.Sandbox.workspaces.store` =
`drive` selects `SandboxDriveWorkspaceStoreService`, a `SandboxLiveWorkspaceStore`: the workspace service hands every
step of a key it serves to it, every other key stays in the manifest store.

- **Which keys:** `space-<agentSpaceId>` and a key a call of the space bound (an eval run's), when the agent space has
  an own drive (ddd-clouddrive's `DriveSpaceMountResolverService::findOrCreateOwnDriveSpace()`); a key with an eval
  scratch drive. `account-<id>`, keys made from files (`createWorkspaceFromFiles()`) and `system.*` stay in the
  manifest store; an agent space without an own drive (link table missing, host gives none) too.
- **Claim:** the runner gets the own drive's base map (latest versions plus the saved, uncommitted entries) with
  restore URLs through the transfer proxy, and every further space of the mount resolver read-only below
  `drivespaces/<name>/` (the library as `drivespaces/library/`, `$SANDBOX_PRELOAD` points there, its `start.sh` runs
  once per session).
- **After a call:** the runner's status names the call's changes; the flush stages them in the working copy and the
  runner uploads the snapshots (presigned PUTs). Nothing is visible in the drive yet.
- **Commit:** at the end of a turn (settled-turn listener: the conversation's changes), at release (every change),
  on `drive commit` in the shell; the runner's base moves in the drive's after-commit hook
  (`SandboxDriveWorkingCopyListener`). A conflict stays uncommitted and comes back as one note of the next call.
- **Pull:** at the first call of every turn, and at the next call when the working copy carries ddd-clouddrive's pull
  mark (`DriveWorkingCopiesService::requestPull()`, set by the browser file sink); both consume the mark. A version
  still in the malware scan is waited for (30 s), else held back with the cursor.
- **`drive` CLI** in the shell (status, diff, commit, pull, get, pin): its requests are answered by PHP under the
  rights of the conversation's owner.
- **Config** `config/app/AI/Sandbox/drive.php` (`AI.Sandbox.drive.*`): `thresholdBytes` (own-drive files above stay
  not local), `mirrorBudgetBytes`, `mountTextMaxBytes`, `ignore`, `maxRequestRounds`, `libraryTitle`.
- **Preload:** `app:agent-sandbox:seed` also writes the preload folder into the library space (created on first
  seed; new or changed files only).
- **Moving existing workspaces:** `app:agent-sandbox:migrate-to-drive [--workspaceKey=space-<id>] [--dryRun]` copies
  the manifest-store workspaces of agent spaces into their own drives (working copy `migration-<key>`, origin
  MIGRATED; idempotent; the manifest store is left as it is, so switching back to `manifest` finds it unchanged).

## Runner contract

`runner/README.md`. `SandboxRunnerContract::CONTRACT_VERSION` equals the package version; compatible is the same major
and at least `MINIMUM_CONTRACT_VERSION`, and while the major is 0 the same minor. With a store other than the manifest
store `check-runner` requires the capability `drive`.

## Development

```bash
composer install && vendor/bin/phpunit
docker run --rm -v "$PWD/runner:/src" -w /src golang:1.27.4-trixie sh -c 'go vet ./... && go test ./...'
cd web && pnpm install && pnpm typecheck && pnpm test && pnpm build
```

Untrusted input: no byte from a workspace is interpreted by the server (pictures decoded by `RasterImageDecoder`,
documents parsed inside the sandbox, files served as untrusted content); raw sinks are listed with their reason in
`config/untrusted-sinks-allowlist.php` and checked by `tests/UntrustedSinkScanTest.php`.

## License

MIT
