<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A root conversation of the agent space a workspace belongs to (the sandbox screen links to it).
 */
class SandboxConversation extends ValueObject
{
    /** @var int The conversation's id */
    public int $conversationId = 0;

    /** @var int|null The account that owns the conversation */
    public ?int $accountId = null;

    /** @var string|null The run state (`IDLE`, `RUNNING`, …) */
    public ?string $runState = null;

    /** @var string|null The purpose the conversation serves */
    public ?string $purposeName = null;

    /** @var DateTime|null When it was created */
    public ?DateTime $createdAt = null;

    /** @var DateTime|null Its last change */
    public ?DateTime $updatedAt = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->conversationId);
    }
}
