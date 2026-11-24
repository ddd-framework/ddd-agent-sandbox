<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * The mark of unsaved changes of a workspace (in the shared cache): the runner session holds a change the store does
 * not have yet. A fresh token per marking call; the flush clears the mark only when it still carries the token it began
 * with.
 */
class SandboxDirtyMark
{
    public string $token = '';

    /** @var string|null The conversation whose call made the change */
    public ?string $conversationKey = null;

    /** @var int Unix time of the marking call */
    public int $since = 0;

    public static function createNow(?string $conversationKey): static
    {
        $sandboxDirtyMark = new static();
        $sandboxDirtyMark->token = bin2hex(random_bytes(8));
        $sandboxDirtyMark->conversationKey = $conversationKey;
        $sandboxDirtyMark->since = time();
        return $sandboxDirtyMark;
    }

    /**
     * @param mixed $cachedValue
     * @return static|null
     */
    public static function fromCachedValue(mixed $cachedValue): ?static
    {
        if (!is_array($cachedValue)) {
            return null;
        }
        $sandboxDirtyMark = new static();
        $sandboxDirtyMark->token = (string)($cachedValue['token'] ?? '');
        $sandboxDirtyMark->conversationKey = isset($cachedValue['conversationKey']) ? (string)$cachedValue['conversationKey'] : null;
        $sandboxDirtyMark->since = (int)($cachedValue['since'] ?? 0);
        return $sandboxDirtyMark;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCachedValue(): array
    {
        return ['token' => $this->token, 'conversationKey' => $this->conversationKey, 'since' => $this->since];
    }

    public function isOlderThan(int $seconds): bool
    {
        return $this->since < time() - $seconds;
    }
}
