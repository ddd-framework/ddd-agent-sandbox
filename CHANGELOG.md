# Changelog

## 0.2.1

- `Agent.Sandbox.Documents` declares `turnMinimumSteps: 30` (ddd-agents 0.3.2): while it is loaded, a turn has at
  least 30 steps (under the planned-turn ceiling and its default cost cap), so building a deck or report, rendering,
  checking and handing it over fits one reply instead of stopping before the hand-over.
- `Agent.Sandbox.Documents`: show the files early. When a tool that shows files to the user is loaded, the agent shows
  them right after the first build, before the check; a fix rebuilds the same paths.
- Requires ddd-agents 0.3.2 beside this package (`conflict` `<0.3.2`): older versions do not know the skill key and
  their skill-file lint reports it.
- Contract 0.2.1; the runner is unchanged and runner 0.2.0 stays compatible (same minor). New images `0.2.1` are built
  with the tag; deploying them is optional.

## 0.2.0

- **Drive store** (beside `mgamadeus/ddd-agents` 0.3.x and `mgamadeus/ddd-clouddrive` 0.2.x;
  `AI.Sandbox.workspaces.store` = `drive`): `SandboxDriveWorkspaceStoreService`. The workspace of an agent space is the
  working copy of its own drive: claim with the base map and the mounts (library below `drivespaces/library/`,
  `$SANDBOX_PRELOAD` there, its `start.sh` once per session), save after a call through the runner's presigned
  uploads, commit at the turn end, at release and on `drive commit`, pull at the first call of a turn and on
  ddd-clouddrive's pull mark, the in-session `drive` CLI answered by PHP. Every other key stays in the manifest store.
  Config `config/app/AI/Sandbox/drive.php`; working-copy listener `SandboxDriveWorkingCopyListener`.
- **Port** `SandboxLiveWorkspaceStore` (extends `SandboxWorkspaceStore`): a store that works with the live session;
  `SandboxWorkspacesService` hands claim, record, save, the CLI pause, release commit, reset, state note, current
  manifest and turn-end commit of its keys to it. `SandboxWorkspacesService::setCallContext()` (`SandboxCallContext`:
  turn, agent space, operator), `takePendingNotes()`, `buildCommandHintLines()`, `commitAtTurnEnd()`,
  `seedPreloadForLiveWorkspaces()`; `SandboxRunnerClientService::claimDriveSession()`, `callDrive()`, `continueExec()`.
- **Commands:** `app:agent-sandbox:migrate-to-drive` (manifest-store workspaces of agent spaces into their drives,
  idempotent, `--dryRun`); `app:agent-sandbox:seed` also seeds the library space with the drive store.
- **Admin:** the drive status of a workspace (`findDriveStatus`) and the offload's committed count come from the drive
  store.
- **Rights:** `SandboxAccountRights::USE_SANDBOX` (`useSandbox`) on the package `agent.sandbox.shell`
  (`#[MCPRequiresAccountRights]`) and in the skill `Agent.Sandbox.Shell` (`requiredAccountRights`).
- **Requires ddd-agents 0.3.x** (`conflict` `<0.3.0 || >=0.4.0`); the conversations of a workspace come from
  `AIConversationsService::findNewestRootConversationsOfAgentSpaceId()`.
- Runner unchanged; contract and images `0.2.0` (a 0.x minor needs the same runner minor: deploy the 0.2.0 images).

What a host does: update ddd-agents to 0.3.x with this release; grant `useSandbox` (`AI.accountRights`, by default
the superadmins); deploy the runner images 0.2.0. For the drive store: ddd-clouddrive 0.2.x, the transfer proxy,
`store` = `drive`, `app:agent-sandbox:migrate-to-drive --dryRun`, then without `--dryRun`, then `app:agent-sandbox:seed`.

## 0.1.0

First release.

- **Runner** (`runner/`): one sandbox session per pod (gVisor; session uid, rlimits, seccomp, memory cgroup, disk
  quota; persistent shells, background jobs, file and manifest routes, administration reads, drive protocol).
  `GET /healthz` adds `contractVersion` (the package version, compiled in), `toolkit`, `imageConfigHash`,
  `capabilities` (`drive`, `fuse`, `transferProxy`) and `limits`. Images `ghcr.io/ddd-framework/ddd-agent-sandbox-runner:0.1.0`
  (base) and `:0.1.0-full`; no project font or name in the image.
- **PHP core**: `SandboxRunnerClientService` (pools, health), `SandboxWorkspacesService` with explicit workspace keys
  (claim and restore, write-behind save with a detached flush, restart after a lost session, idle release, capacity:
  release idle once, then refuse; expiry of scratch, preload restored read-only below `$SANDBOX_PRELOAD` with
  `start.sh`), the port `SandboxWorkspaceStore` with the manifest store (`sandbox-workspaces`), `SandboxSessionRulesService`
  (pool, timeout caps, background jobs, network: off or an allow-list through the egress proxy), image configuration
  (`image.yaml`, exact pins, hash), Kustomize overlay rendering.
- **Commands**: `app:agent-sandbox:check-runner` (`--isolation`), `:sessions` (`--sweep`, `--expire`, `--offload`,
  `--flush`, `--bench`), `:seed`, `:render-deploy`, `:build-image`.
- **Administration**: `SandboxesControllerTrait` (list, details, files with safe previews, processes, commands,
  events, conversations, audit, actions start / offload / kill job / end / reset), audit table
  `SandboxActionLogEntries`, port `SandboxWorkspaceActivity`; npm `@mgamadeus/ddd-agent-sandbox` with `./screen`.
- **Agent part** (beside ddd-agents ≥ 0.1.44): MCP package `agent.sandbox.shell` (shell, readFile, writeFile, editFile,
  searchFiles, viewWorkspaceImages), skills `Agent.Sandbox.Shell`, `Agent.Sandbox.Documents`, `.Pptx`, `.Pdf`,
  `.Excel`, workspace keys of a call, settled-turn flush, state note, eval participant, workspace activity.

What a host does: README › Installation.
