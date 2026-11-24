<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One entry of a session's log: a limit hit, a refused system call, a timeout. The type is the runner's
 * (`MEMORY_EXCEEDED`, `SECCOMP_DENIED`, `TIMEOUT`, …); unknown types are shown as they are.
 */
class SandboxSessionEvent extends ValueObject
{
    /** @var DateTime|null When it happened */
    public ?DateTime $occurredAt = null;

    /** @var string The event type, upper case */
    public string $type = '';

    /** @var string|null A short detail (foreign text) */
    public ?string $detail = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
