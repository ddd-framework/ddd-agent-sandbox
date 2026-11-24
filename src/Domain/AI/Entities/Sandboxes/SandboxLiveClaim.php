<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * What a live store ({@see \DDD\Domain\AI\Services\Sandboxes\SandboxLiveWorkspaceStore}) sends with a claim instead of
 * restoring files: the runner's claim block and the environment the store adds. A store extends it with the state it
 * needs once the runner took the claim.
 */
class SandboxLiveClaim
{
    /** @var string The runner's claim mode (`drive`) */
    public string $mode = '';

    /** @var array<string, mixed> The runner's claim block of that mode (wire format, runner/README.md) */
    public array $claimBlock = [];

    /** @var string[] Environment variables the store adds to every shell of the session, by name */
    public array $environment = [];

    /** @var int Files the claim brings into the workspace (measurement) */
    public int $restoredFileCount = 0;
}
