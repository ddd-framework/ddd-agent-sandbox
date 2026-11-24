<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;

/** The limits a runner pod reports in `/healthz` (its environment); 0 where it reports none */
class SandboxRunnerLimits extends ValueObject
{
    /** @var string[] The limit names as `/healthz` reports them (= the property names) */
    public const array LIMIT_NAMES = [
        'quotaBytes', 'memoryBytes', 'maxProcesses', 'maxShells', 'maxJobs', 'defaultTimeoutSeconds', 'maxTimeoutSeconds',
        'maxJobTimeoutSeconds', 'sessionIdleSeconds',
    ];

    public int $quotaBytes = 0;

    public int $memoryBytes = 0;

    public int $maxProcesses = 0;

    public int $maxShells = 0;

    public int $maxJobs = 0;

    public int $defaultTimeoutSeconds = 0;

    public int $maxTimeoutSeconds = 0;

    public int $maxJobTimeoutSeconds = 0;

    public int $sessionIdleSeconds = 0;
}
