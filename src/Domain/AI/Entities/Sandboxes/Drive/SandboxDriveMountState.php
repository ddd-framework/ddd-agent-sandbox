<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\Drive;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A further drive space mounted read-only below drivespaces/<mountName>/ in a drive workspace: the space, the working
 * copy that tracks it for the workspace (`<workspaceKey>~<mountName>`) and the cursor a claim started from.
 */
class SandboxDriveMountState extends ValueObject
{
    public string $mountName = '';

    public int $driveSpaceId = 0;

    public int $driveWorkingCopyId = 0;

    /** @var int The newest change of the space the claim's base map holds (acknowledged after the claim) */
    public int $cursor = 0;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->mountName);
    }

    /**
     * @param array<string, mixed> $cachedValue
     * @return static
     */
    public static function fromCachedValue(array $cachedValue): static
    {
        $sandboxDriveMountState = new static();
        $sandboxDriveMountState->mountName = (string)($cachedValue['mountName'] ?? '');
        $sandboxDriveMountState->driveSpaceId = (int)($cachedValue['driveSpaceId'] ?? 0);
        $sandboxDriveMountState->driveWorkingCopyId = (int)($cachedValue['driveWorkingCopyId'] ?? 0);
        $sandboxDriveMountState->cursor = (int)($cachedValue['cursor'] ?? 0);
        return $sandboxDriveMountState;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCachedValue(): array
    {
        return ['mountName' => $this->mountName, 'driveSpaceId' => $this->driveSpaceId, 'driveWorkingCopyId' => $this->driveWorkingCopyId, 'cursor' => $this->cursor];
    }
}
