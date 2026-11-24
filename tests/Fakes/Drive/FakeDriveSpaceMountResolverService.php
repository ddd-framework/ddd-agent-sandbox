<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes\Drive;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\CloudDrive\Entities\AI\Mounts\DriveSpaceMounts;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\AI\DriveSpaceMountResolverService;

/** One own drive for every agent space and a fixed list of mounts */
class FakeDriveSpaceMountResolverService extends DriveSpaceMountResolverService
{
    public ?DriveSpace $ownDriveSpace = null;

    public DriveSpaceMounts $driveSpaceMounts;

    public function __construct()
    {
        $this->driveSpaceMounts = new DriveSpaceMounts();
    }

    public function findOrCreateOwnDriveSpace(AgentSpace $agentSpace): ?DriveSpace
    {
        return $this->ownDriveSpace;
    }

    public function findOwnDriveSpace(AgentSpace $agentSpace): ?DriveSpace
    {
        return $this->ownDriveSpace;
    }

    public function findMountsOfAgentSpace(AgentSpace $agentSpace): DriveSpaceMounts
    {
        return $this->driveSpaceMounts;
    }
}
