<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * What PHP enforces for the calls of one workspace ({@see \DDD\Domain\AI\Services\Sandboxes\SandboxSessionRulesService}):
 * the runner pool its session is claimed in, the longest command timeout, whether background jobs are allowed, and the
 * environment every shell of its session gets. Hard limits (CPU, memory, disk, processes) are the pod's, not these.
 */
class SandboxSessionRules
{
    public string $poolName = SandboxRunnerPool::DEFAULT_POOL_NAME;

    /** @var int The longest timeout of a foreground command, in seconds */
    public int $maxCommandTimeoutSeconds = 600;

    /** @var int The longest timeout of a background job, in seconds */
    public int $maxJobTimeoutSeconds = 3600;

    public bool $allowsBackgroundJobs = true;

    /** @var string[] environment variables of every shell of the session, by name */
    public array $environment = [];
}
