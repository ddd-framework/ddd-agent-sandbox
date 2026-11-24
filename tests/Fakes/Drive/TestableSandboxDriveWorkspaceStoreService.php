<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes\Drive;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Services\Sandboxes\Agents\Drive\SandboxDriveWorkspaceStoreService;
use DDD\Domain\AI\Services\Sandboxes\Agents\SandboxAgentWorkspaceKeysService;
use DDD\Domain\AI\Services\Sandboxes\SandboxRunnerClientService;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspaceStore;
use DDD\Domain\CloudDrive\Entities\AI\Mounts\DriveSpaceMount;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Services\AI\DriveSpaceMountResolverService;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use DDD\Tests\AgentSandbox\Fakes\InMemorySandboxWorkspaceStore;
use DDD\Tests\AgentSandbox\Fakes\TestableSandboxWorkspacesService;
use Throwable;

/**
 * The drive store on fakes: an own drive (space 7) for every agent space, a library mount (space 50), working copies,
 * blobs and grants in memory, the fake runner with the drive protocol, and the testable workspace service whose store
 * it is.
 */
class TestableSandboxDriveWorkspaceStoreService extends SandboxDriveWorkspaceStoreService
{
    public const int OWN_DRIVE_SPACE_ID = 7;

    public const int LIBRARY_SPACE_ID = 50;

    public TestableSandboxWorkspacesService $testableSandboxWorkspacesService;

    public FakeDriveRunnerClientService $fakeDriveRunnerClientService;

    public FakeDriveBlobsService $fakeDriveBlobsService;

    public FakeDriveWorkingCopiesService $fakeDriveWorkingCopiesService;

    public FakeDriveContentGrantsService $fakeDriveContentGrantsService;

    public FakeDriveChangesService $fakeDriveChangesService;

    public FakeDriveSpaceMountResolverService $fakeDriveSpaceMountResolverService;

    public FakeDriveVersionsService $fakeDriveVersionsService;

    public FakeDriveSpacesService $fakeDriveSpacesService;

    public InMemorySandboxWorkspaceStore $inMemoryManifestStore;

    /** @var int[] scratch drive space id by workspace key */
    public array $scratchDriveSpaceIds = [];

    /** @var bool whether a version still being scanned counts as stuck */
    public bool $isScanStuck = false;

    /** @var string[] the errors logged */
    public array $loggedErrors = [];

    public function __construct()
    {
        $this->testableSandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $this->fakeDriveRunnerClientService = new FakeDriveRunnerClientService();
        $this->testableSandboxWorkspacesService->fakeSandboxRunnerClientService = $this->fakeDriveRunnerClientService;
        $this->testableSandboxWorkspacesService->setWorkspaceStore($this);
        $this->useCache($this->testableSandboxWorkspacesService->inMemorySandboxCache);
        $this->inMemoryManifestStore = $this->testableSandboxWorkspacesService->inMemorySandboxWorkspaceStore;
        $this->fakeDriveBlobsService = new FakeDriveBlobsService();
        $this->fakeDriveWorkingCopiesService = new FakeDriveWorkingCopiesService($this->fakeDriveBlobsService);
        $this->fakeDriveContentGrantsService = new FakeDriveContentGrantsService();
        $this->fakeDriveChangesService = new FakeDriveChangesService();
        $this->fakeDriveVersionsService = new FakeDriveVersionsService();
        $this->fakeDriveSpacesService = new FakeDriveSpacesService();
        $this->fakeDriveSpaceMountResolverService = new FakeDriveSpaceMountResolverService();
        $ownDriveSpace = new DriveSpace();
        $ownDriveSpace->id = self::OWN_DRIVE_SPACE_ID;
        $ownDriveSpace->spaceKind = DriveSpaceMountResolverService::OWN_DRIVE_SPACE_KIND;
        $ownDriveSpace->title = 'Own drive';
        $ownDriveSpace->fileCount = 2;
        $ownDriveSpace->usedBytes = 2048;
        $librarySpace = new DriveSpace();
        $librarySpace->id = self::LIBRARY_SPACE_ID;
        $librarySpace->spaceKind = DriveSpaceMountResolverService::LIBRARY_SPACE_KIND;
        $librarySpace->title = 'Library';
        $this->fakeDriveSpacesService->spacesById = [self::OWN_DRIVE_SPACE_ID => $ownDriveSpace, self::LIBRARY_SPACE_ID => $librarySpace];
        $this->fakeDriveSpaceMountResolverService->ownDriveSpace = $ownDriveSpace;
        $libraryMount = new DriveSpaceMount();
        $libraryMount->driveSpace = $librarySpace;
        $libraryMount->mountName = 'library';
        $this->fakeDriveSpaceMountResolverService->driveSpaceMounts->add($libraryMount);
    }

    public function getWorkingCopyOfKey(string $workspaceKey): ?DriveWorkingCopy
    {
        return $this->fakeDriveWorkingCopiesService->findWorkingCopy($this->fakeDriveSpacesService->spacesById[self::OWN_DRIVE_SPACE_ID], $workspaceKey);
    }

    protected function findAgentSpace(int $agentSpaceId): ?AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $agentSpaceId;
        return $agentSpace;
    }

    protected function findOwnDriveSpaceIdOfAgentSpace(int $agentSpaceId): int
    {
        return $agentSpaceId > 0 && $this->fakeDriveSpaceMountResolverService->ownDriveSpace !== null ? (int)$this->fakeDriveSpaceMountResolverService->ownDriveSpace->id : 0;
    }

    protected function findScratchDriveSpaceId(string $workspaceKey): int
    {
        return $this->scratchDriveSpaceIds[$workspaceKey] ?? 0;
    }

    protected function findDriveSpaceAsSystem(int $driveSpaceId): ?DriveSpace
    {
        return $this->fakeDriveSpacesService->spacesById[$driveSpaceId] ?? null;
    }

    protected function isBlobScanStuck(int $driveBlobId): bool
    {
        return $this->isScanStuck;
    }

    protected function pauseForScan(): void
    {
        // the fake ends the wait at once: a scan still running holds the change back
        $this->scanWaitEndsAt = 0.0;
    }

    protected function logError(string $message, Throwable $throwable): void
    {
        $this->loggedErrors[] = $message;
    }

    protected function getManifestWorkspaceStore(): SandboxWorkspaceStore
    {
        return $this->inMemoryManifestStore;
    }

    protected function getSandboxWorkspacesService(): SandboxWorkspacesService
    {
        return $this->testableSandboxWorkspacesService;
    }

    protected function getSandboxRunnerClientService(): SandboxRunnerClientService
    {
        return $this->fakeDriveRunnerClientService;
    }

    protected function getSandboxAgentWorkspaceKeysService(): SandboxAgentWorkspaceKeysService
    {
        return new SandboxAgentWorkspaceKeysService();
    }

    protected function getDriveSpaceMountResolverService(): DriveSpaceMountResolverService
    {
        return $this->fakeDriveSpaceMountResolverService;
    }

    protected function getDriveWorkingCopiesService(): DriveWorkingCopiesService
    {
        return $this->fakeDriveWorkingCopiesService;
    }

    protected function getDriveChangesService(): DriveChangesService
    {
        return $this->fakeDriveChangesService;
    }

    protected function getDriveContentGrantsService(): DriveContentGrantsService
    {
        return $this->fakeDriveContentGrantsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        return $this->fakeDriveBlobsService;
    }

    protected function getDriveVersionsService(): DriveVersionsService
    {
        return $this->fakeDriveVersionsService;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        return $this->fakeDriveSpacesService;
    }
}
