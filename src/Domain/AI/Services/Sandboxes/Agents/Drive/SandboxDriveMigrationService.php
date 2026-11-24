<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes\Agents\Drive;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Sandboxes\Drive\SandboxDriveMigrationReport;
use DDD\Domain\AI\Entities\Sandboxes\Drive\SandboxDriveMigrationReports;
use DDD\Domain\AI\Services\Sandboxes\Agents\SandboxAgentWorkspaceKeysService;
use DDD\Domain\AI\Services\Sandboxes\SandboxManifestWorkspaceStoreService;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadReservation;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveStagedChange;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveStagedChanges;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyEntry;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyFile;
use DDD\Domain\CloudDrive\Services\AI\DriveSpaceMountResolverService;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Moves the workspaces the manifest store holds for agent spaces (`space-<id>`) into each space's own drive, so the
 * drive store finds them there: through the drive's working-copy protocol as the working copy `migration-<key>` —
 * stage every file whose content differs from the drive (or is new there), put the bytes into the drive's object
 * store from PHP, commit with origin MIGRATED. Idempotent: a second run finds every file with its sha256 in the drive
 * and stages nothing. The manifest store is left as it is (switching `AI.Sandbox.workspaces.store` back to manifest
 * finds it unchanged). Keys without an agent space (`account-<id>`, eval keys) are reported and skipped.
 */
class SandboxDriveMigrationService extends Service
{
    /** @var string The prefix of the migration's own working copy key */
    public const string WORKING_COPY_KEY_PREFIX = 'migration-';

    /** @var int Files staged per call (the drive takes at most 1000) */
    public const int STAGE_BATCH_SIZE = 200;

    /**
     * @param string|null $onlyWorkspaceKey one key, or null for every key of the manifest store
     * @param bool $dryRun count only, write nothing (no drive, no working copy is created)
     * @return SandboxDriveMigrationReports
     * @throws InternalErrorException an upload failed (what was staged stays; the next run goes on)
     */
    public function migrateManifestWorkspaces(?string $onlyWorkspaceKey, bool $dryRun): SandboxDriveMigrationReports
    {
        $workspaceKeys = $onlyWorkspaceKey !== null ? [$onlyWorkspaceKey] : $this->getManifestWorkspaceStore()->listWorkspaceKeys();
        $sandboxDriveMigrationReports = new SandboxDriveMigrationReports();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            foreach ($workspaceKeys as $workspaceKey) {
                $sandboxDriveMigrationReport = $this->migrateManifestWorkspace((string)$workspaceKey, $dryRun);
                $sandboxDriveMigrationReports->add($sandboxDriveMigrationReport);
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $sandboxDriveMigrationReports;
    }

    /**
     * @throws InternalErrorException
     */
    protected function migrateManifestWorkspace(string $workspaceKey, bool $dryRun): SandboxDriveMigrationReport
    {
        $sandboxDriveMigrationReport = new SandboxDriveMigrationReport();
        $sandboxDriveMigrationReport->workspaceKey = $workspaceKey;
        $agentSpaceId = $this->getSandboxAgentWorkspaceKeysService()->findAgentSpaceIdOfWorkspaceKey($workspaceKey);
        if ($agentSpaceId === null) {
            $sandboxDriveMigrationReport->state = 'skipped: no agent space key (only space-<id> has a drive)';
            return $sandboxDriveMigrationReport;
        }
        $agentSpace = $this->findAgentSpace($agentSpaceId);
        if ($agentSpace === null) {
            $sandboxDriveMigrationReport->state = 'skipped: agent space ' . $agentSpaceId . ' does not exist';
            return $sandboxDriveMigrationReport;
        }
        $sandboxWorkspaceManifest = $this->getManifestWorkspaceStore()->readManifest($workspaceKey);
        $sandboxDriveMigrationReport->fileCount = $sandboxWorkspaceManifest->countFiles();
        $sandboxDriveMigrationReport->byteCount = $sandboxWorkspaceManifest->sumBytes();
        $driveSpaceMountResolverService = $this->getDriveSpaceMountResolverService();
        $driveSpace = $dryRun ? $driveSpaceMountResolverService->findOwnDriveSpace($agentSpace) : $driveSpaceMountResolverService->findOrCreateOwnDriveSpace($agentSpace);
        if ($driveSpace === null && !$dryRun) {
            $sandboxDriveMigrationReport->state = 'skipped: the agent space has no own drive';
            return $sandboxDriveMigrationReport;
        }
        $driveWorkingCopiesService = $this->getDriveWorkingCopiesService();
        $workingCopyKey = static::WORKING_COPY_KEY_PREFIX . $workspaceKey;
        $driveWorkingCopy = null;
        /** @var DriveWorkingCopyFile[] $driveFilesByPath */
        $driveFilesByPath = [];
        if ($driveSpace !== null) {
            $sandboxDriveMigrationReport->driveSpaceId = (int)$driveSpace->id;
            $driveWorkingCopy = $dryRun ? $driveWorkingCopiesService->findWorkingCopy($driveSpace, $workingCopyKey) : $driveWorkingCopiesService->findOrCreateWorkingCopy($driveSpace, $workingCopyKey);
            // what a former run left staged and uncommitted is staged again below (same content: known, no upload)
            foreach (($driveWorkingCopy !== null ? $driveWorkingCopiesService->buildBaseMap($driveWorkingCopy)->getElements() : []) as $driveWorkingCopyFile) {
                $driveFilesByPath[$driveWorkingCopyFile->path] = $driveWorkingCopyFile;
            }
            if ($driveWorkingCopy === null) {
                // a dry run without the migration's working copy: compare with the drive as the own sandbox sees it
                $ownWorkingCopy = $driveWorkingCopiesService->findWorkingCopy($driveSpace, $workspaceKey);
                foreach (($ownWorkingCopy !== null ? $driveWorkingCopiesService->buildBaseMap($ownWorkingCopy)->getElements() : []) as $driveWorkingCopyFile) {
                    if ($driveWorkingCopyFile->versionId !== null) {
                        $driveWorkingCopyFile->changeKind = null;
                        $driveFilesByPath[$driveWorkingCopyFile->path] = $driveWorkingCopyFile;
                    }
                }
            }
        }
        /** @var DriveStagedChange[] $driveStagedChangeList */
        $driveStagedChangeList = [];
        foreach ($sandboxWorkspaceManifest->files->getElements() as $manifestFile) {
            if ($driveWorkingCopiesService->isExcludedPath($manifestFile->path)) {
                $sandboxDriveMigrationReport->excludedCount++;
                continue;
            }
            $sha256 = strtolower($manifestFile->sha256);
            $driveFile = $driveFilesByPath[$manifestFile->path] ?? null;
            if ($driveFile !== null && $driveFile->changeKind === null && strtolower((string)$driveFile->sha256) === $sha256) {
                $sandboxDriveMigrationReport->unchangedCount++;
                continue;
            }
            $driveStagedChange = new DriveStagedChange();
            $driveStagedChange->path = $manifestFile->path;
            $driveStagedChange->changeKind = $driveFile?->versionId !== null ? DriveWorkingCopyEntry::CHANGE_MODIFIED : DriveWorkingCopyEntry::CHANGE_ADDED;
            $driveStagedChange->baseVersionId = $driveFile?->versionId;
            $driveStagedChange->sha256 = $sha256;
            $driveStagedChange->sizeBytes = $manifestFile->size;
            $driveStagedChangeList[] = $driveStagedChange;
        }
        $sandboxDriveMigrationReport->stagedCount = count($driveStagedChangeList);
        if ($dryRun || $driveWorkingCopy === null) {
            $sandboxDriveMigrationReport->state = $sandboxDriveMigrationReport->stagedCount > 0 ? ($driveSpace === null ? 'would create the drive and migrate' : 'would migrate') : 'up to date';
            return $sandboxDriveMigrationReport;
        }
        foreach (array_chunk($driveStagedChangeList, static::STAGE_BATCH_SIZE) as $driveStagedChangeBatch) {
            $this->stageAndUploadBatch($workspaceKey, $driveWorkingCopy, $driveStagedChangeBatch, $sandboxDriveMigrationReport);
        }
        if ($sandboxDriveMigrationReport->stagedCount > 0 || $driveWorkingCopiesService->findEntries($driveWorkingCopy)->count() > 0) {
            $driveCommitResult = $driveWorkingCopiesService->commitChanges($driveWorkingCopy, 'Moved from the sandbox workspace store (' . $workspaceKey . ')', null, null, null, DriveNode::ORIGIN_MIGRATED);
            $sandboxDriveMigrationReport->committedCount = $driveCommitResult->committedFiles?->count() ?? 0;
            $sandboxDriveMigrationReport->conflictCount = $driveCommitResult->conflictedFiles?->count() ?? 0;
        }
        $sandboxDriveMigrationReport->state = match (true) {
            $sandboxDriveMigrationReport->conflictCount > 0 => 'migrated with conflicts (left staged in ' . $driveWorkingCopy->workingCopyKey . ')',
            $sandboxDriveMigrationReport->stagedCount === 0 && $sandboxDriveMigrationReport->committedCount === 0 => 'up to date',
            default => 'migrated',
        };
        return $sandboxDriveMigrationReport;
    }

    /**
     * @param DriveStagedChange[] $driveStagedChangeBatch
     * @throws InternalErrorException an upload failed (the run stops; what was staged stays and the next run goes on)
     */
    protected function stageAndUploadBatch(string $workspaceKey, DriveWorkingCopy $driveWorkingCopy, array $driveStagedChangeBatch, SandboxDriveMigrationReport $sandboxDriveMigrationReport): void
    {
        $driveStagedChanges = new DriveStagedChanges();
        $sha256sByPath = [];
        foreach ($driveStagedChangeBatch as $driveStagedChange) {
            $driveStagedChanges->add($driveStagedChange);
            $sha256sByPath[$driveStagedChange->path] = (string)$driveStagedChange->sha256;
        }
        foreach ($this->getDriveWorkingCopiesService()->stageChanges($driveWorkingCopy, $driveStagedChanges)->getElements() as $driveStagedUpload) {
            $driveUploadReservation = $driveStagedUpload->reservation;
            if ($driveUploadReservation === null) {
                continue;
            }
            if ($driveUploadReservation->isContentKnown) {
                $sandboxDriveMigrationReport->knownCount++;
                continue;
            }
            $content = $this->getManifestWorkspaceStore()->readBlob($workspaceKey, $sha256sByPath[$driveStagedUpload->path]);
            $this->putReservedContent($driveUploadReservation, $content, $driveStagedUpload->path);
            $sandboxDriveMigrationReport->uploadedCount++;
        }
    }

    /**
     * Puts the bytes of a reserved upload into the drive's object store from PHP (no presigned request: the server
     * holds the content) and marks the blob STAGED for the working copy.
     *
     * @throws InternalErrorException
     */
    protected function putReservedContent(DriveUploadReservation $driveUploadReservation, string $content, string $path): void
    {
        $driveBlobsService = $this->getDriveBlobsService();
        $driveBlob = $driveBlobsService->find($driveUploadReservation->driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob || $driveBlob->storageKey === null) {
            throw new InternalErrorException(sprintf('The reserved blob of %s is gone.', $path));
        }
        if ($driveBlob->state !== DriveBlob::STATE_UPLOADING) {
            return;
        }
        $objectStorage = $driveBlobsService->getObjectStorage();
        if ($driveBlob->multipartUploadId !== null) {
            $objectStorage->abortMultipartUpload((string)$driveBlob->storageKey, $driveBlob->multipartUploadId);
            $driveBlob->multipartUploadId = null;
            $driveBlob->updatePartialIgnoringRights('multipartUploadId');
        }
        $objectStorage->putObjectIfAbsent((string)$driveBlob->storageKey, $content, $driveBlob->contentType);
        $driveBlobsService->completeUpload($driveBlob, DriveBlob::STATE_STAGED);
    }

    protected function findAgentSpace(int $agentSpaceId): ?AgentSpace
    {
        $agentSpace = AgentSpace::getService()->find($agentSpaceId, false);
        return $agentSpace instanceof AgentSpace ? $agentSpace : null;
    }

    protected function getManifestWorkspaceStore(): SandboxManifestWorkspaceStoreService
    {
        /** @var SandboxManifestWorkspaceStoreService $sandboxManifestWorkspaceStoreService */
        $sandboxManifestWorkspaceStoreService = DDDService::instance()->getService(SandboxManifestWorkspaceStoreService::class);
        return $sandboxManifestWorkspaceStoreService;
    }

    protected function getSandboxAgentWorkspaceKeysService(): SandboxAgentWorkspaceKeysService
    {
        /** @var SandboxAgentWorkspaceKeysService $sandboxAgentWorkspaceKeysService */
        $sandboxAgentWorkspaceKeysService = DDDService::instance()->getService(SandboxAgentWorkspaceKeysService::class);
        return $sandboxAgentWorkspaceKeysService;
    }

    protected function getDriveSpaceMountResolverService(): DriveSpaceMountResolverService
    {
        /** @var DriveSpaceMountResolverService $driveSpaceMountResolverService */
        $driveSpaceMountResolverService = DDDService::instance()->getService(DriveSpaceMountResolverService::class);
        return $driveSpaceMountResolverService;
    }

    protected function getDriveWorkingCopiesService(): DriveWorkingCopiesService
    {
        /** @var DriveWorkingCopiesService $driveWorkingCopiesService */
        $driveWorkingCopiesService = DDDService::instance()->getService(DriveWorkingCopiesService::class);
        return $driveWorkingCopiesService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        return $driveBlobsService;
    }
}
