# mgamadeus/ddd-agent-sandbox

> For base patterns, see vendor/mgamadeus/ddd/AGENTS.md and skills in vendor/mgamadeus/ddd. For the agent part, see
> vendor/mgamadeus/ddd-agents.

The agent sandbox: runner (`runner/`, Go, image `ghcr.io/ddd-framework/ddd-agent-sandbox-runner`), PHP client,
workspaces with a pluggable store, project image and preload, administration with audit, and beside ddd-agents the
agent's shell tools and document skills. Full guide: skill `ddd-module-agent-sandbox-specialist`.

## Layout

| Path | What |
|---|---|
| `src/Modules/AgentSandbox/AgentSandboxModule.php` | DDDModule; the agent part (`Services\Sandboxes\Agents`, `Presentation\Mcp\Client\Sandbox`) registers only beside ddd-agents |
| `src/Domain/AI/Entities/Sandboxes/` | VOs (session, manifest, sync result, zones, health, contract, admin VOs), the audit entity `SandboxActionLogEntry` |
| `src/Domain/AI/Services/Sandboxes/` | `SandboxRunnerClientService`, `SandboxWorkspacesService`, `SandboxWorkspaceStore` (port) + `SandboxManifestWorkspaceStoreService`, `SandboxSessionRulesService`, `SandboxAdministrationService`, `SandboxesAdminService`, `SandboxWorkspaceActivity` (port), image, deploy and picture services |
| `src/Domain/AI/Services/Sandboxes/Agents/` | keys of a call, workspace activity, settled-turn flush, eval participant |
| `src/Presentation/Mcp/Client/Sandbox/` | `SandboxShellMCPPackage` (`agent.sandbox.shell`) and its DTOs |
| `src/Presentation/Api/Admin/Sandbox/` | `SandboxesControllerTrait` and its DTOs |
| `src/Symfony/Commands/Sandbox/` | `app:agent-sandbox:check-runner`, `:sessions`, `:seed`, `:render-deploy`, `:build-image` |
| `config/app/AI/Sandbox/` | `runner.php`, `workspaces.php` (`AI.Sandbox.*`) |
| `config/app/AI/Skills/Agent/Sandbox/` | `Shell`, `Documents`, `Pptx`, `Pdf`, `Excel` |
| `deploy/k8s/` | Kustomize base and the optional egress proxy component |
| `web/` | npm `@mgamadeus/ddd-agent-sandbox` (client `.`, screens `./screen`) |

## Rules

- Conventions of Core apply: no `private` / `final`, services from the container, typed VOs instead of struct-shaped
  arrays (the raw runner answers `array<string, mixed>` of the client are the wire contract), `declare(strict_types=1)`.
- `SandboxRunnerContract::CONTRACT_VERSION` = the package version (the release workflow checks it); while the major is
  0 a minor may break the contract: runner first, then PHP.
- `DBSandboxActionLogEntryModel.php` is generated (`app:generate-doctrine-models-for-entities`); never edit it.
- Untrusted input: `config/untrusted-sinks-allowlist.php` + `tests/UntrustedSinkScanTest.php`.
- Versions in lockstep: `composer.json` = `web/package.json` = the tag `vX.Y.Z`.
- Public repository: no application names, local paths or internal procedures.

## Contributing: versions and commit messages

- **A pull request never sets a version.** Leave `version` in `composer.json` and in `package.json` as it is, and add
  no version heading to `CHANGELOG.md`. Write the changelog entry under `## Unreleased` at the top of the changelog
  (add the heading when it is missing).
- **The maintainer assigns the version at release:** bumps `composer.json` / `package.json`, renames `## Unreleased` to
  the new version and tags it. Versions are regular only (no pre-release suffixes).
- **Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):** `feat: …`, `fix: …`,
  `docs: …`, `refactor: …`, `test: …`, `chore: …`, optionally with a scope (`fix(chat): …`); a breaking change carries
  `!` after the type or a `BREAKING CHANGE:` footer.
- **Merging a pull request that still carries a version:** keep the version fields of `main`, move its changelog entry
  under `## Unreleased`, then release as usual.
- **Rebase on the current `main` before the merge,** so that only real code conflicts remain.
- **A breaking change is named.** Its changelog entry says what an application must change. The release makes it a
  major version (a package at 1.0 or above) or a minor version (a package still at 0.x).
- **No application terms in a package:** no application's name, and no logic that only one application has (its own
  posts, its own media screens, its own country rules). A package offers hooks; the application individualises
  through them. Review checks this.
- **Tags are never moved or deleted.** A mistake in a release is fixed by a new patch version.
