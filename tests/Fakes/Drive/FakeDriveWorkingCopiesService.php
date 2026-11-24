<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes\Drive;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadReservation;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResolutions;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResult;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveStagedChanges;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveStagedUpload;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveStagedUploads;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyChanges;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyEntries;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyEntry;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyFiles;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use DDD\Infrastructure\ObjectStorage\ObjectStoragePresignedRequest;
use RuntimeException;

/**
 * Working copies in memory: base maps, saved entries, pages of changes, pull marks, staging (each new content gets a
 * blob of {@see FakeDriveBlobsService} and a presigned PUT) and a scripted commit result.
 */
class FakeDriveWorkingCopiesService extends DriveWorkingCopiesService
{
    /** @var DriveWorkingCopy[] by id */
    public array $workingCopiesById = [];

    /** @var DriveWorkingCopyFiles[] the base map by working copy id */
    public array $baseMapsByWorkingCopyId = [];

    /** @var DriveWorkingCopyEntry[][] saved entries by working copy id, by path */
    public array $entriesByWorkingCopyId = [];

    /** @var DriveWorkingCopyChanges[][] the pages findChangesSince() answers, by working copy id (shifted) */
    public array $changePagesByWorkingCopyId = [];

    /** @var bool[] pull marks by working copy id */
    public array $pullMarksByWorkingCopyId = [];

    /** @var DriveStagedChanges[] every stageChanges() call */
    public array $stagedChangeCalls = [];

    /** @var array<int, array{paths: string[]|null, conversationKey: string|null, message: string}> every commit */
    public array $commitCalls = [];

    public ?DriveCommitResult $nextCommitResult = null;

    public ?RuntimeException $nextCommitException = null;

    public FakeDriveBlobsService $fakeDriveBlobsService;

    protected int $nextWorkingCopyId = 100;

    public function __construct(FakeDriveBlobsService $fakeDriveBlobsService)
    {
        $this->fakeDriveBlobsService = $fakeDriveBlobsService;
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->workingCopiesById[(int)$entityId] ?? null;
    }

    public function findOrCreateWorkingCopy(DriveSpace $driveSpace, string $workingCopyKey): DriveWorkingCopy
    {
        $driveWorkingCopy = $this->findWorkingCopy($driveSpace, $workingCopyKey);
        if ($driveWorkingCopy !== null) {
            return $driveWorkingCopy;
        }
        $driveWorkingCopy = new DriveWorkingCopy();
        $driveWorkingCopy->id = $this->nextWorkingCopyId++;
        $driveWorkingCopy->driveSpaceId = (int)$driveSpace->id;
        $driveWorkingCopy->workingCopyKey = $workingCopyKey;
        $driveWorkingCopy->changeCursor = 0;
        $this->workingCopiesById[(int)$driveWorkingCopy->id] = $driveWorkingCopy;
        return $driveWorkingCopy;
    }

    public function findWorkingCopy(DriveSpace $driveSpace, string $workingCopyKey): ?DriveWorkingCopy
    {
        foreach ($this->workingCopiesById as $driveWorkingCopy) {
            if ($driveWorkingCopy->driveSpaceId === (int)$driveSpace->id && $driveWorkingCopy->workingCopyKey === $workingCopyKey) {
                return $driveWorkingCopy;
            }
        }
        return null;
    }

    public function buildBaseMap(DriveWorkingCopy $driveWorkingCopy): DriveWorkingCopyFiles
    {
        return $this->baseMapsByWorkingCopyId[(int)$driveWorkingCopy->id] ?? new DriveWorkingCopyFiles();
    }

    public function stageChanges(DriveWorkingCopy $driveWorkingCopy, DriveStagedChanges $driveStagedChanges): DriveStagedUploads
    {
        $this->stagedChangeCalls[] = $driveStagedChanges;
        $driveStagedUploads = new DriveStagedUploads();
        foreach ($driveStagedChanges->getElements() as $driveStagedChange) {
            $driveWorkingCopyEntry = new DriveWorkingCopyEntry();
            $driveWorkingCopyEntry->driveWorkingCopyId = (int)$driveWorkingCopy->id;
            $driveWorkingCopyEntry->path = $driveStagedChange->path;
            $driveWorkingCopyEntry->changeKind = $driveStagedChange->changeKind;
            $driveWorkingCopyEntry->conversationKey = $driveStagedChange->conversationKey;
            $driveStagedUpload = new DriveStagedUpload();
            $driveStagedUpload->path = $driveStagedChange->path;
            if ($driveStagedChange->sha256 !== null) {
                $driveBlob = $this->fakeDriveBlobsService->addUploadingBlob((int)$driveWorkingCopy->driveSpaceId, $driveStagedChange->sha256, $driveStagedChange->sizeBytes);
                $driveWorkingCopyEntry->stagedDriveBlobId = (int)$driveBlob->id;
                $driveUploadReservation = new DriveUploadReservation();
                $driveUploadReservation->driveBlobId = (int)$driveBlob->id;
                $driveUploadReservation->sizeBytes = $driveStagedChange->sizeBytes;
                $driveUploadReservation->uploadRequest = new ObjectStoragePresignedRequest();
                $driveUploadReservation->uploadRequest->url = 'https://proxy.test/upload/' . $driveBlob->id;
                $driveUploadReservation->uploadRequest->headers = [];
                $driveStagedUpload->reservation = $driveUploadReservation;
            }
            $this->entriesByWorkingCopyId[(int)$driveWorkingCopy->id][$driveStagedChange->path] = $driveWorkingCopyEntry;
            $driveStagedUploads->add($driveStagedUpload);
        }
        return $driveStagedUploads;
    }

    public function discardChanges(DriveWorkingCopy $driveWorkingCopy, array $paths): void
    {
        foreach ($paths as $path) {
            unset($this->entriesByWorkingCopyId[(int)$driveWorkingCopy->id][$path]);
        }
    }

    public function commitChanges(
        DriveWorkingCopy $driveWorkingCopy,
        string $message,
        ?array $paths = null,
        ?DriveCommitResolutions $driveCommitResolutions = null,
        ?string $conversationKey = null,
        string $origin = DriveNode::ORIGIN_AGENT
    ): DriveCommitResult {
        $this->commitCalls[] = ['paths' => $paths, 'conversationKey' => $conversationKey, 'message' => $message];
        if ($this->nextCommitException !== null) {
            throw $this->nextCommitException;
        }
        $driveCommitResult = $this->nextCommitResult ?? new DriveCommitResult();
        $driveCommitResult->committedFiles ??= new DriveWorkingCopyFiles();
        $driveCommitResult->conflictedFiles ??= new DriveWorkingCopyFiles();
        return $driveCommitResult;
    }

    public function findChangesSince(DriveWorkingCopy $driveWorkingCopy, ?int $afterChangeId = null, int $limit = DriveChangesService::MAX_CHANGES_PER_PAGE): DriveWorkingCopyChanges
    {
        $pages = $this->changePagesByWorkingCopyId[(int)$driveWorkingCopy->id] ?? [];
        if ($pages === []) {
            $driveWorkingCopyChanges = new DriveWorkingCopyChanges();
            $driveWorkingCopyChanges->fromCursor = (int)$driveWorkingCopy->changeCursor;
            $driveWorkingCopyChanges->toCursor = (int)$driveWorkingCopy->changeCursor;
            $driveWorkingCopyChanges->changedFiles = new DriveWorkingCopyFiles();
            return $driveWorkingCopyChanges;
        }
        return array_shift($this->changePagesByWorkingCopyId[(int)$driveWorkingCopy->id]);
    }

    public function requestPull(DriveWorkingCopy $driveWorkingCopy): void
    {
        $this->pullMarksByWorkingCopyId[(int)$driveWorkingCopy->id] = true;
    }

    public function isPullRequested(DriveWorkingCopy $driveWorkingCopy): bool
    {
        return $this->pullMarksByWorkingCopyId[(int)$driveWorkingCopy->id] ?? false;
    }

    public function consumePullRequest(DriveWorkingCopy $driveWorkingCopy): bool
    {
        $isPullRequested = $this->isPullRequested($driveWorkingCopy);
        unset($this->pullMarksByWorkingCopyId[(int)$driveWorkingCopy->id]);
        return $isPullRequested;
    }

    public function acknowledgeChanges(DriveWorkingCopy $driveWorkingCopy, int $toCursor): void
    {
        if ($toCursor > (int)$driveWorkingCopy->changeCursor) {
            $driveWorkingCopy->changeCursor = $toCursor;
        }
    }

    public function findEntries(DriveWorkingCopy $driveWorkingCopy): DriveWorkingCopyEntries
    {
        $driveWorkingCopyEntries = new DriveWorkingCopyEntries();
        foreach ($this->entriesByWorkingCopyId[(int)$driveWorkingCopy->id] ?? [] as $driveWorkingCopyEntry) {
            $driveWorkingCopyEntries->add($driveWorkingCopyEntry);
        }
        return $driveWorkingCopyEntries;
    }

    public function findEntryByPath(DriveWorkingCopy $driveWorkingCopy, string $path): ?DriveWorkingCopyEntry
    {
        return $this->entriesByWorkingCopyId[(int)$driveWorkingCopy->id][$path] ?? null;
    }

    public function isExcludedPath(string $path): bool
    {
        return str_starts_with($path, '.runner/');
    }
}
