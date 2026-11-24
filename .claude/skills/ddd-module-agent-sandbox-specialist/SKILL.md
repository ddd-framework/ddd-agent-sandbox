---
name: ddd-module-agent-sandbox-specialist
description: "Installs, configures and extends mgamadeus/ddd-agent-sandbox: the runner (gVisor, one session per pod, contract version in /healthz), SandboxRunnerClientService, SandboxWorkspacesService (explicit keys), the store port SandboxWorkspaceStore, SandboxSessionRulesService (pools, timeouts, network allow-list), image.yaml and build-image, the preload folder and seed, Kustomize overlay with render-deploy, admin endpoints SandboxesControllerTrait, audit table SandboxActionLogEntries, screen @mgamadeus/ddd-agent-sandbox/screen, and beside ddd-agents the MCP package agent.sandbox.shell, the skills Agent.Sandbox.Shell and Agent.Sandbox.Documents, flush at a settled turn, eval participant. Use when adding the sandbox to an application, check-runner fails (contract, image configuration drift, drive capability, isolation), sessions are refused as busy, a workspace change is lost or not saved, configuring network, pools or preload, building the project's runner image, or mounting the sandbox admin screen."
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
- A host overrides behaviour by a same-path subclass under `App\` (rules, keys, activity names) or registers its own
  store / activity by the tags.

## Commands

| Command | Use |
|---|---|
| `app:agent-sandbox:check-runner [--isolation] [--format=json]` | deploy check; exit 1 on a problem |
| `app:agent-sandbox:sessions` | list; `--sweep` (every minute), `--expire [--dryRun]` (daily), `--offload` (before a rollout), `--release=<key>`, `--release-idle`, `--flush=<key>`, `--bench=N` |
| `app:agent-sandbox:seed [--dryRun]` | preload folder → store (every deploy) |
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
