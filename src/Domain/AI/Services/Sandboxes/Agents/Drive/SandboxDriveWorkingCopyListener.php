<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes\Agents\Drive;

use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResolutions;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResult;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopyListener;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Services\DDDService;

/**
 * The live sandbox on a working copy of the drive (ddd-clouddrive's DriveWorkingCopyListener): the drive's own reads
 * (driveStatus, commitDriveChanges, the commit before a copy or a card) see what the sandbox holds now, and every commit
 * moves the sandbox's base.
 *
 * - Before a read: when a live drive session holds the working copy, its unsaved changes are saved first (runner stage,
 *   stageChanges, upload) under the workspace's save lock. Nothing when this process is saving or committing that
 *   working copy itself.
 * - After a commit: `theirs` / `both` are applied in the sandbox, then its base moves.
 *
 * A throw is logged by the drive, which goes on with what is saved; the sandbox keeps every change and the next save
 * stages it again. Only active while the drive store is the configured store.
 */
class SandboxDriveWorkingCopyListener implements DriveWorkingCopyListener
{
    /**
     * @throws ConflictException the save lock stayed busy (the drive logs it and reads what is saved)
     */
    public function beforeWorkingCopyRead(DriveWorkingCopy $driveWorkingCopy): void
    {
        $sandboxDriveWorkspaceStoreService = $this->findActiveDriveWorkspaceStore();
        if ($sandboxDriveWorkspaceStoreService === null || $sandboxDriveWorkspaceStoreService->isInOwnOperation((int)$driveWorkingCopy->id)) {
            return;
        }
        $liveSession = $sandboxDriveWorkspaceStoreService->findLiveSessionOfWorkingCopy($driveWorkingCopy);
        if ($liveSession === null) {
            return;
        }
        [$sandboxSession, $sandboxDriveSessionState] = $liveSession;
        $sandboxWorkspacesService = $this->getSandboxWorkspacesService();
        $conversationKey = $sandboxWorkspacesService->getCallContext()?->conversationKey;
        $sandboxWorkspacesService->runUnderSaveLock($sandboxSession->workspaceKey, fn() => $sandboxDriveWorkspaceStoreService->saveDriveChanges(
            $sandboxSession,
            $sandboxDriveSessionState,
            $conversationKey !== '' ? $conversationKey : null
        ));
    }

    public function afterWorkingCopyCommitted(DriveWorkingCopy $driveWorkingCopy, DriveCommitResult $driveCommitResult, ?DriveCommitResolutions $driveCommitResolutions): void
    {
        $sandboxDriveWorkspaceStoreService = $this->findActiveDriveWorkspaceStore();
        $liveSession = $sandboxDriveWorkspaceStoreService?->findLiveSessionOfWorkingCopy($driveWorkingCopy);
        if ($liveSession === null) {
            return;
        }
        [$sandboxSession, $sandboxDriveSessionState] = $liveSession;
        $sandboxDriveWorkspaceStoreService->applyCommitToRunner($sandboxSession, $sandboxDriveSessionState, $driveWorkingCopy, $driveCommitResult, $driveCommitResolutions);
    }

    /**
     * The drive store when it is the configured store; null otherwise (the listener stays quiet).
     */
    protected function findActiveDriveWorkspaceStore(): ?SandboxDriveWorkspaceStoreService
    {
        $sandboxWorkspaceStore = $this->getSandboxWorkspacesService()->getWorkspaceStore();
        return $sandboxWorkspaceStore instanceof SandboxDriveWorkspaceStoreService ? $sandboxWorkspaceStore : null;
    }

    protected function getSandboxWorkspacesService(): SandboxWorkspacesService
    {
        /** @var SandboxWorkspacesService $sandboxWorkspacesService */
        $sandboxWorkspacesService = DDDService::instance()->getService(SandboxWorkspacesService::class);
        return $sandboxWorkspacesService;
    }
}
