<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One process running in a workspace session. The command line is foreign text, truncated by the runner, never the
 * environment.
 */
class SandboxProcess extends ValueObject
{
    /** @var int The process id inside the session */
    public int $pid = 0;

    /** @var string The command line (truncated) */
    public string $command = '';

    /** @var DateTime|null When the process started */
    public ?DateTime $startedAt = null;

    /** @var float|null CPU seconds used */
    public ?float $cpuSeconds = null;

    /** @var int|null Resident memory in bytes */
    public ?int $rssBytes = null;

    /** @var string|null The background job the process belongs to (it can be killed by this id) */
    public ?string $jobId = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->pid);
    }
}
