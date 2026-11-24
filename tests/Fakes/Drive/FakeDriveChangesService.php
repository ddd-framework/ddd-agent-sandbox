<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes\Drive;

use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvents;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Services\DriveChangesService;

/** The newest change id per space; no change events */
class FakeDriveChangesService extends DriveChangesService
{
    /** @var int[] by space id */
    public array $newestChangeIdsBySpaceId = [];

    public function __construct()
    {
    }

    public function findNewestChangeId(int $driveSpaceId): int
    {
        return $this->newestChangeIdsBySpaceId[$driveSpaceId] ?? 0;
    }

    public function findChangesOfWorkingCopy(DriveWorkingCopy $driveWorkingCopy, ?array $eventTypes = null, int $limit = self::MAX_CHANGES_PER_PAGE): DriveChangeEvents
    {
        return new DriveChangeEvents();
    }
}
