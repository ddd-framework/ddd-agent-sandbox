<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * One command run in a conversation's shell: the runner's exec answer (foreground: status, exitCode, stdout, stderr,
 * cwd, …; background: jobId, state — the raw answer of the runner contract, runner/README.md), what the save that
 * followed changed (null for a background command), and how long the runner took.
 */
class SandboxCommandRun
{
    /** @var array<string, mixed> the runner's exec answer as it came */
    public array $execAnswer = [];

    public ?SandboxWorkspaceSyncResult $syncResult = null;

    public int $runnerMilliseconds = 0;
}
