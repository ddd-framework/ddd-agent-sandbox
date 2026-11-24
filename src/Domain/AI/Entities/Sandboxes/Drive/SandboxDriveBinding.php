<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\Drive;

/**
 * Which agent space and operator a workspace key belongs to (noted from the calls; a `space-<id>` key names its space
 * itself): the claim, a flush process and the turn end of the key read it.
 */
class SandboxDriveBinding
{
    public int $agentSpaceId = 0;

    /** @var int The account whose rights the drive reads and writes use; 0 = the signed-in account */
    public int $operatorAccountId = 0;

    /**
     * @param mixed $cachedValue
     * @return static|null
     */
    public static function fromCachedValue(mixed $cachedValue): ?static
    {
        if (!is_array($cachedValue) || (int)($cachedValue['agentSpaceId'] ?? 0) <= 0) {
            return null;
        }
        $sandboxDriveBinding = new static();
        $sandboxDriveBinding->agentSpaceId = (int)$cachedValue['agentSpaceId'];
        $sandboxDriveBinding->operatorAccountId = (int)($cachedValue['operatorAccountId'] ?? 0);
        return $sandboxDriveBinding;
    }

    /**
     * @return array<string, int>
     */
    public function toCachedValue(): array
    {
        return ['agentSpaceId' => $this->agentSpaceId, 'operatorAccountId' => $this->operatorAccountId];
    }
}
