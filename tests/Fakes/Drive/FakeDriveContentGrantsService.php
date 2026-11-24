<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes\Drive;

use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;

/** Restore grants as URLs naming the blob */
class FakeDriveContentGrantsService extends DriveContentGrantsService
{
    public function __construct()
    {
    }

    public function createWorkspaceRestoreGrant(DriveWorkingCopy $driveWorkingCopy, int $driveBlobId, string $fileName): DriveContentGrant
    {
        $driveContentGrant = new DriveContentGrant();
        $driveContentGrant->purpose = DriveContentGrant::PURPOSE_WORKSPACE_RESTORE;
        $driveContentGrant->url = 'https://proxy.test/restore/' . $driveBlobId . '/' . rawurlencode($fileName);
        return $driveContentGrant;
    }
}
