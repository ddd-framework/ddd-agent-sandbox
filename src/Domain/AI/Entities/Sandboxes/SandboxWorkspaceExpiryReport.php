<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * What the expiry did (or would do, dry run) to one stored workspace: files whose lifetime is over, scratch removed
 * under quota pressure, bytes freed, files kept, the size of lib/; or why the workspace was skipped.
 */
class SandboxWorkspaceExpiryReport
{
    public string $workspaceKey = '';

    /** @var string[] */
    public array $expiredPaths = [];

    /** @var string[] */
    public array $pressurePaths = [];

    public int $freedBytes = 0;

    public int $keptFiles = 0;

    public int $libBytes = 0;

    /** @var string|null Why the workspace was left for the next run */
    public ?string $skippedReason = null;
}
