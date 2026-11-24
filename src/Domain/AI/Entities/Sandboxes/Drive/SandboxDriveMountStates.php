<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\Drive;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The mounts of a drive workspace, by mount name.
 *
 * @property SandboxDriveMountState[] $elements
 * @method SandboxDriveMountState|null first()
 * @method SandboxDriveMountState|null getByUniqueKey(string $uniqueKey)
 * @method SandboxDriveMountState[] getElements()
 */
class SandboxDriveMountStates extends ObjectSet
{
    public function findByMountName(string $mountName): ?SandboxDriveMountState
    {
        return $this->getByUniqueKey(SandboxDriveMountState::uniqueKeyStatic($mountName));
    }

    public function findByDriveSpaceId(int $driveSpaceId): ?SandboxDriveMountState
    {
        foreach ($this->getElements() as $sandboxDriveMountState) {
            if ($sandboxDriveMountState->driveSpaceId === $driveSpaceId) {
                return $sandboxDriveMountState;
            }
        }
        return null;
    }

    /**
     * @param array<int, mixed> $cachedValues
     * @return static
     */
    public static function fromCachedValues(array $cachedValues): static
    {
        $sandboxDriveMountStates = new static();
        foreach ($cachedValues as $cachedValue) {
            if (is_array($cachedValue)) {
                $sandboxDriveMountState = SandboxDriveMountState::fromCachedValue($cachedValue);
                $sandboxDriveMountStates->add($sandboxDriveMountState);
            }
        }
        return $sandboxDriveMountStates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toCachedValues(): array
    {
        return array_map(static fn(SandboxDriveMountState $sandboxDriveMountState): array => $sandboxDriveMountState->toCachedValue(), $this->getElements());
    }
}
