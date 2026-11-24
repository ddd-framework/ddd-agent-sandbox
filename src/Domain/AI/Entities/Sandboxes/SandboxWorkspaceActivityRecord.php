<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * The activity of a workspace noted between two saves (in the shared cache, cheap per call): when each conversation
 * last worked here and when each file was last read. The next save and the expiry merge it into the manifest.
 */
class SandboxWorkspaceActivityRecord
{
    /** @var string[] RFC 3339 by conversation key */
    public array $conversationsLastActiveAt = [];

    /** @var string[] RFC 3339 by path */
    public array $filesLastReadAt = [];

    /**
     * @param mixed $cachedValue
     * @return static
     */
    public static function fromCachedValue(mixed $cachedValue): static
    {
        $activityRecord = new static();
        if (!is_array($cachedValue)) {
            return $activityRecord;
        }
        foreach ((array)($cachedValue['conversations'] ?? []) as $conversationKey => $lastActiveAt) {
            if (is_string($lastActiveAt)) {
                $activityRecord->conversationsLastActiveAt[(string)$conversationKey] = $lastActiveAt;
            }
        }
        foreach ((array)($cachedValue['reads'] ?? []) as $path => $lastReadAt) {
            if (is_string($lastReadAt)) {
                $activityRecord->filesLastReadAt[(string)$path] = $lastReadAt;
            }
        }
        return $activityRecord;
    }

    /**
     * @return array<string, array<string, string>> the value kept in the cache
     */
    public function toCachedValue(): array
    {
        return ['conversations' => $this->conversationsLastActiveAt, 'reads' => $this->filesLastReadAt];
    }
}
