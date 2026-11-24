<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes\Drive;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;

/** Blobs in memory: their state, sha256 and size; completeUpload() moves the state */
class FakeDriveBlobsService extends DriveBlobsService
{
    /** @var DriveBlob[] by id */
    public array $blobsById = [];

    /** @var int[] ids of the blobs completeUpload() moved, in order */
    public array $completedBlobIds = [];

    protected int $nextBlobId = 1000;

    public function __construct()
    {
    }

    public function addBlob(int $driveSpaceId, string $sha256, int $sizeBytes, string $state = DriveBlob::STATE_RELEASED): DriveBlob
    {
        $driveBlob = new DriveBlob();
        $driveBlob->id = $this->nextBlobId++;
        $driveBlob->driveSpaceId = $driveSpaceId;
        $driveBlob->sha256 = $sha256;
        $driveBlob->sizeBytes = $sizeBytes;
        $driveBlob->state = $state;
        $driveBlob->storageKey = 'blobs/' . $driveBlob->id;
        $this->blobsById[(int)$driveBlob->id] = $driveBlob;
        return $driveBlob;
    }

    public function addUploadingBlob(int $driveSpaceId, string $sha256, int $sizeBytes): DriveBlob
    {
        return $this->addBlob($driveSpaceId, $sha256, $sizeBytes, DriveBlob::STATE_UPLOADING);
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->blobsById[(int)$entityId] ?? null;
    }

    public function completeUpload(DriveBlob $driveBlob, string $nextState = DriveBlob::STATE_PENDING): DriveBlob
    {
        $driveBlob->state = $nextState;
        $this->completedBlobIds[] = (int)$driveBlob->id;
        return $driveBlob;
    }

    public function findReleasedBlobBySha256(int $driveSpaceId, string $sha256): ?DriveBlob
    {
        foreach ($this->blobsById as $driveBlob) {
            if ($driveBlob->driveSpaceId === $driveSpaceId && $driveBlob->sha256 === $sha256 && $driveBlob->state === DriveBlob::STATE_RELEASED) {
                return $driveBlob;
            }
        }
        return null;
    }
}
