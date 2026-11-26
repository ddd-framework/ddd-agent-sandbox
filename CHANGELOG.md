# Changelog

## 0.2.12

- Beside `mgamadeus/ddd-agents` 0.9: constraint `^0.3.2 || ^0.4.0 || ^0.5.0 || ^0.6.0 || ^0.7.0 || ^0.8.0 || ^0.9.0`
  (conflict below 0.3.2 and from 0.10.0). The suite passes against ddd-agents 0.9.0. Nothing else changes; contract
  0.2.12.

## 0.2.11

- Beside `mgamadeus/ddd-agents` 0.8: constraint `^0.3.2 || ^0.4.0 || ^0.5.0 || ^0.6.0 || ^0.7.0 || ^0.8.0` (conflict
  below 0.3.2 and from 0.9.0). The suite passes against ddd-agents 0.8.0 and ddd-clouddrive 0.5.4. Nothing else
  changes; contract 0.2.11.

## 0.2.10

- Beside `mgamadeus/ddd-agents` 0.7 and `mgamadeus/ddd-clouddrive` 0.5: constraints `^0.3.2 || ^0.4.0 || ^0.5.0 || ^0.6.0 || ^0.7.0`
  (conflict below 0.3.2 and from 0.8.0) and `^0.3.1 || ^0.4.0 || ^0.5.0` (conflict below 0.3.1 and from 0.6.0). The
  suite passes against ddd-agents 0.7.3 and ddd-clouddrive 0.5.0. Nothing else changes; contract 0.2.10.

## 0.2.9

- Beside `mgamadeus/ddd-agents` 0.6: constraints `^0.3.2 || ^0.4.0 || ^0.5.0 || ^0.6.0` (conflict below 0.3.2 and from
  0.7.0). The suite passes against ddd-agents 0.6.0. Nothing else changes; contract 0.2.9.

## 0.2.8

- Beside `mgamadeus/ddd-agents` 0.5: constraints `^0.3.2 || ^0.4.0 || ^0.5.0` (conflict below 0.3.2 and from 0.6.0).
  The suite passes against ddd-agents 0.5.0.
- With ddd-agents 0.5.0 or later, `app:agent:check-installation` requires the table `SandboxActionLogEntries` only
  while the sandbox is switched on (`AI.Sandbox.runner.enabled`, or AGENT_SANDBOX_ENABLED):
  `SandboxFeatureTableConditionProviderService` (namespace `Services\Sandboxes\Installation`, registered only beside
  ddd-agents' `AgentFeatureTableConditionProvider`). Before, a host with the sandbox off got it as a BLOCKING missing
  table. ddd-agents 0.5.0 has its own host steps (its CHANGELOG).
- Contract 0.2.8, runner unchanged (0.2.0 compatible); web unchanged apart from the version.

## 0.2.7

- Beside `mgamadeus/ddd-agents` 0.4: constraints `^0.3.2 || ^0.4.0` (conflict below 0.3.2 and from 0.5.0). The suite
  passes against ddd-agents 0.4.0. Nothing changes for a host; ddd-agents 0.4.0 has its own host steps (its CHANGELOG).
- PHP, web and runner unchanged. Contract 0.2.7, runner unchanged (0.2.0 compatible).

## 0.2.6

- Web (`@mgamadeus/ddd-agent-sandbox`): the files tab draws source files and Markdown like the file blocks of
  `@mgamadeus/ddd-admin-ui` do. `SandboxDetailScreen` and `SandboxFilePreview` take the two further loaders of the
  host beside `loadPdfJs` and pass them to the preview: `loadCodeHighlighter` (a source file in colour, its tokens as
  text nodes) and `renderMarkdown` (a Markdown file as markup, with the switch to its source). Both are optional:
  without the highlighter a source file shows plain, without the renderer a Markdown file shows its source. The
  server still sends every text as `text/plain`; the file's name decides the view (`sandboxTextViewOf`, exported:
  `.md` / `.markdown` → Markdown, an extension the blocks know a language for → source, everything else plain text
  as before). No new text key: the blocks' own keys (`Preview`, `Source`, "View of the Markdown file") come with the
  admin-ui manifest.
- **A workspace file is untrusted text.** The package still renders no file content as HTML; a host that passes
  `renderMarkdown` passes the untrusted pipeline (`source => <MarkdownPreview source={source} untrusted />` of
  `@mgamadeus/ddd-admin-ui/markdown`: raw HTML stays text, no picture is loaded, web and mail links only).
- **Peer:** `@mgamadeus/ddd-admin-ui` ≥ 0.8.0 (was ≥ 0.6.0): `CodePreview`, `MarkdownFilePreview` and the loader types
  exist from 0.8.0. A host below 0.8.0 stays on 0.2.5.
- PHP and runner unchanged. Contract 0.2.6, runner unchanged (0.2.0 compatible). The web package is built and tested
  against `@mgamadeus/ddd-admin-ui` 0.8.1 (lockfile); the peer floor stays 0.8.0.

## 0.2.5

- Beside ddd-clouddrive 0.4: the Composer constraints admit `^0.3.1 || ^0.4.0` (conflict below 0.3.1 and from 0.5.0).
  The drive store uses no API that changed in clouddrive 0.4 (its suite passes against 0.4.0). Note for hosts on the
  drive store: in clouddrive 0.4 the agent's direct `saveDriveFile` refuses a copy made from an older version of the
  target instead of overwriting it (clouddrive's CHANGELOG). Contract 0.2.5,
  runner unchanged (0.2.0 compatible).

## 0.2.4

- `app:agent-sandbox:seed` on a host without the sandbox: the drive library space is seeded independently of the
  workspace store; while the sandbox is disabled or the workspaces' object store is not configured, the preload
  workspace is skipped with a note (what is skipped, why, what to set) and the command exits 0
  (`SandboxWorkspacesService::findPreloadWorkspaceSeedSkipNote()`, `SandboxManifestWorkspaceStoreService::isObjectStorageConfigured()`).
  An error of a configured store still exits 1. Contract 0.2.4, runner unchanged (0.2.0 compatible); still beside
  ddd-clouddrive ^0.3.1.

## 0.2.3

- Beside ddd-clouddrive 0.3 (every byte between a browser and the store goes through the backend): requires
  ddd-clouddrive ^0.3.1 (conflict below 0.3.1). The drive store's presigned requests are server side (runner →
  store: `stageChanges()` reservations, `createWorkspaceRestoreGrant()`) and unchanged in clouddrive 0.3; the runner
  protocol is unchanged (contract 0.2.3, runner 0.2.0 compatible; the release builds the runner image 0.2.3 from the
  same runner code, a host that pins 0.2.2 keeps working).

## 0.2.2

- Server messages for a host's text export: `i18n/server-messages.json` lists the 22 messages the sandbox endpoints
  answer with, with hints (built and checked by `ddd-server-messages` of mgamadeus/ddd 3.42; CI runs `build --check`).
  A host merges it with `ddd-server-messages collect` and must not prune these keys. Requires mgamadeus/ddd ^3.42.0;
  contract 0.2.2, runner 0.2.0 compatible.

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
