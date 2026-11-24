<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\Drive;

use DDD\Domain\AI\Entities\Sandboxes\SandboxLiveClaim;

/**
 * A drive-mode claim: the runner's `drive` block (the own drive's base map with restore URLs, the mounts) and what the
 * store keeps once the runner took it.
 */
class SandboxDriveClaim extends SandboxLiveClaim
{
    public int $agentSpaceId = 0;

    public int $operatorAccountId = 0;

    public int $driveSpaceId = 0;

    public int $driveWorkingCopyId = 0;

    /** @var int The newest change of the own drive the base map holds */
    public int $ownCursor = 0;

    public SandboxDriveMountStates $mountStates;

    /** @var string|null The mount whose top holds `start.sh` (the library's preload start script) */
    public ?string $startScriptMountName = null;

    public function __construct()
    {
        $this->mountStates = new SandboxDriveMountStates();
    }
}
