<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/** A file written into a workspace: what the runner stored and what the save that followed changed */
class SandboxFileWrite
{
    public string $path = '';

    public int $size = 0;

    public string $sha256 = '';

    public ?SandboxWorkspaceSyncResult $syncResult = null;
}
