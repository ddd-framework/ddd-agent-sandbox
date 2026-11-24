---
name: ddd-module-agent-sandbox-specialist
description: "Installs, configures and extends mgamadeus/ddd-agent-sandbox: the runner (gVisor, one session per pod, contract in /healthz), SandboxRunnerClientService, SandboxWorkspacesService (explicit keys), the store ports SandboxWorkspaceStore and SandboxLiveWorkspaceStore, the drive store beside ddd-clouddrive (workspace = working copy of the agent space's drive, pull mark, migrate-to-drive, library seed), SandboxSessionRulesService (pools, network), image.yaml and build-image, preload and seed, render-deploy, admin endpoints SandboxesControllerTrait, audit table, screen, and beside ddd-agents the MCP package agent.sandbox.shell (right useSandbox), the Agent.Sandbox skills, turn-end flush, eval participant. Use when adding the sandbox to an application, check-runner fails, sessions are refused as busy, a workspace change is lost or not saved, configuring network, pools or preload, building the runner image, mounting the admin screen, switching workspaces to the drive, or a drive change never reaches the sandbox."
---

# Agent sandbox

> Base patterns: See core skills in vendor/mgamadeus/ddd. Agent tools: ddd-module-agents-tools-specialist.

## Pieces

| Piece | Class / file |
|---|---|
| Runner contract | `runner/README.md`; `SandboxRunnerContract` (`CONTRACT_VERSION` = package version; same major, ≥ minimum, while 0.x the same minor) |
| HTTP client | `SandboxRunnerClientService` (`resolveRunnerAddresses($pool)`, `readHealth()`, sessions, exec, jobs, files, manifest, admin reads) |
| Workspaces | `SandboxWorkspacesService`: `runShellCommand()`, `readBackgroundJob()`, `readWorkspaceFileBytes()`, `writeWorkspaceFileBytes()`, `buildStateNoteOnce()`, `releaseSession()`, `offloadAllSessions()`, `sweepWorkspaces()`, `expireWorkspaces()`, `createWorkspaceFromFiles()`, `deleteWorkspace()`, `seedPreloadContent()` |
| Store | port `SandboxWorkspaceStore` (tag `ai.sandbox_workspace_store`, chosen by `AI.Sandbox.workspaces.store`); built in: `SandboxManifestWorkspaceStoreService` (`manifest`) |
| Live store | port `SandboxLiveWorkspaceStore` (extends the store port): claim, record, save, CLI pause, release commit, turn-end commit, pull, state note, reset of the keys it serves live (`isLiveWorkspace()`); call context `SandboxWorkspacesService::setCallContext()` (`SandboxCallContext`) |
| Drive store | beside ddd-agents and ddd-clouddrive 0.2.x: `SandboxDriveWorkspaceStoreService` (`drive`, `Services\Sandboxes\Agents\Drive\`), `SandboxDriveWorkingCopyListener`, `SandboxDriveLibrarySeedService`, `SandboxDriveMigrationService`; config `AI.Sandbox.drive.*` |
| Rights | `SandboxAccountRights::USE_SANDBOX` (`useSandbox`) on `agent.sandbox.shell` and in `Agent.Sandbox.Shell` |
| Rules per workspace | `SandboxSessionRulesService::findRulesOfWorkspace()` → `SandboxSessionRules` (pool, timeout caps, background jobs, environment) |
| Admin | `SandboxesControllerTrait` → `SandboxesAdminService` (guard, audit, file delivery) → `SandboxAdministrationService` (runner, store); port `SandboxWorkspaceActivity` (tag `ai.sandbox_workspace_activity`) |
| Agent part | `SandboxShellMCPPackage` (`agent.sandbox.shell`), `SandboxAgentWorkspaceKeysService`, `SandboxSettledTurnFlushListener`, `SandboxAgentEvalWorkspaceParticipantService`, `SandboxAgentWorkspaceActivityService` |

## Rules

- Workspace keys are plain (`[A-Za-z0-9][A-Za-z0-9._-]{0,127}`); `system.` is reserved (`system.preload`).
- The cache group (`AI.Sandbox.runner.cacheGroup`) must be shared by every process (sessions, unsaved marks, locks):
  Redis in production. APCu only for a single host.
- After a call the change is in the runner session and marked; a detached `app:agent-sandbox:sessions --flush=<key>`
  uploads it. A session lost in that window loses the call's change (logged as an error). The sweep saves overdue marks.
- One session per pod; all busy → idle sessions released once → `ConflictException` "Every sandbox slot is in use".
- `/workspace/.preload`, `node_modules`, `.cache`, `.runner` and other `EXCLUDED_PATH_SEGMENTS` are never saved.
- Lifetimes (`SandboxWorkspaceZones`): `work/<conversation>/` and the root 7 days after the last activity, `out/` and
  `inbox/` 30 days, `lib/` and `NOTES.md` never.
- Network is off by default. `allowList` needs the egress proxy component and the hosts in
  `AI.Sandbox.runner.network.allowedHosts`; `check-runner --isolation` probes it.
- Project software only through `image.yaml` + `app:agent-sandbox:build-image`; `check-runner` compares
  `imageConfigHash`.
- The MCP package requires an admin with access to all resources; a host narrows with a skill gate or widens with a
  same-path subclass of the package.
- Drive store: `space-<id>` keys (and keys a call of the space bound) of agent spaces with an own drive, and keys
  with an eval scratch drive, are drive workspaces; everything else stays in the manifest store. Pull at the first
  call of a turn and on ddd-clouddrive's pull mark (`requestPull()`), commit at the turn end / release / `drive
  commit`; a conflict stays uncommitted and becomes a note of the next call. The runner needs the transfer proxy.
- A host overrides behaviour by a same-path subclass under `App\` (rules, keys, activity names) or registers its own
  store / activity by the tags.

## Commands

| Command | Use |
|---|---|
| `app:agent-sandbox:check-runner [--isolation] [--format=json]` | deploy check; exit 1 on a problem |
| `app:agent-sandbox:sessions` | list; `--sweep` (every minute), `--expire [--dryRun]` (daily), `--offload` (before a rollout), `--release=<key>`, `--release-idle`, `--flush=<key>`, `--bench=N` |
| `app:agent-sandbox:seed [--dryRun]` | preload folder → store, with the drive store also → library space (every deploy) |
| `app:agent-sandbox:migrate-to-drive [--workspaceKey] [--dryRun]` | manifest-store workspaces of agent spaces → their own drives (idempotent) |
| `app:agent-sandbox:render-deploy [--output] [--image] [--stdout]` | Kustomize overlay of the runner pool |
| `app:agent-sandbox:build-image [--image] [--build] [--push] [--printTag]` | the project's runner image from image.yaml |

## Troubleshooting

| Symptom | Check |
|---|---|
| `no runner host is configured` | `AGENT_SANDBOX_RUNNER_HOST` / `AI.Sandbox.runner.host` |
| `contract … does not serve this package` | runner image tag = package version (same minor while 0.x) |
| `image configuration drift` | rebuild and deploy the project image (`build-image`) |
| `The object store "sandbox-workspaces" … is not configured` | `OBJECT_STORAGE_SANDBOX_WORKSPACES_*` |
| tools answer "sandbox is not enabled" | `AGENT_SANDBOX_ENABLED=true` |
| admin screen "No sandbox runner is configured" | the runner host of the PHP process serving the admin API |
| a drive workspace stays in the manifest store | the agent space has no own drive (link table, host resolver), or the key was made from files |
| `the runner has no transfer proxy` in the log | `RUNNER_TRANSFER_PROXY_URL` on the runner pods (drive store only) |
| a file the server stored is not in the session | the writer must call `DriveWorkingCopiesService::requestPull()`; the next call pulls |
| tools not offered | the account lacks `useSandbox` (`AI.accountRights`) |
