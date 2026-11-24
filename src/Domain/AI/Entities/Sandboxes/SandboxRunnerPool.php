<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One pool of runner pods: the headless Service name and its port. The default pool is `AI.Sandbox.runner.host` /
 * `.port`; further pools (another image or other hard limits) are `AI.Sandbox.runner.pools.<name>`.
 */
class SandboxRunnerPool extends ValueObject
{
    public const string DEFAULT_POOL_NAME = 'default';

    public string $name = self::DEFAULT_POOL_NAME;

    /** @var string The headless Service (DNS name with one A record per pod) or one address */
    public string $host = '';

    public int $port = 8080;
}
