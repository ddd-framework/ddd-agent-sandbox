<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * The state of a background job (the runner's job view, raw answer of the runner contract) and what the save after its
 * end changed (null while it runs).
 */
class SandboxJobRead
{
    /** @var array<string, mixed> the runner's job view as it came */
    public array $jobAnswer = [];

    public ?SandboxWorkspaceSyncResult $syncResult = null;

    public function isRunning(): bool
    {
        return ($this->jobAnswer['state'] ?? 'running') === 'running';
    }
}
