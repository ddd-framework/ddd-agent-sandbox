<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/** One changed path of a workspace save: new, changed, deleted or not saved (too large) */
class SandboxWorkspaceChange
{
    public string $path = '';

    /** @var string one of SandboxWorkspaceSyncResult::CHANGE_ */
    public string $change = '';

    public int $size = 0;

    /** @var string|null What the change line says about the file's lifetime ({@see SandboxWorkspaceZones}) */
    public ?string $expiryLabel = null;
}
