<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/** What one sweep of the worker loop did: workspaces whose overdue unsaved change it saved, sessions it released */
class SandboxSweepResult
{
    /** @var string[] */
    public array $flushedWorkspaceKeys = [];

    /** @var string[] */
    public array $releasedWorkspaceKeys = [];
}
