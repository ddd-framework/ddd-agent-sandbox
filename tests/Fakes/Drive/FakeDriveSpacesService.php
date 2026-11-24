<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes\Drive;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;

/** Spaces in memory; every right is held */
class FakeDriveSpacesService extends DriveSpacesService
{
    /** @var DriveSpace[] by id */
    public array $spacesById = [];

    public function __construct()
    {
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->spacesById[(int)$entityId] ?? null;
    }

    public function findSpaceWithRight(int $driveSpaceId, string $right): ?DriveSpace
    {
        return $this->spacesById[$driveSpaceId] ?? null;
    }
}
