<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes\Drive;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;

/** File versions in memory */
class FakeDriveVersionsService extends DriveVersionsService
{
    /** @var DriveFileVersion[] by id */
    public array $versionsById = [];

    public function __construct()
    {
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->versionsById[(int)$entityId] ?? null;
    }
}
