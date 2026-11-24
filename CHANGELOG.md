# Changelog

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
