<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Listeners\Conversations\AIConversationSettledOwnerTurnListener;
use DDD\Domain\AI\Services\Sandboxes\SandboxRunnerClientService;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;

/**
 * Saves the workspace of a conversation once its owner turn is settled (ddd-agents calls it in the worker, after the
 * release): a change the turn's tools left to the write-behind is in the store before the owner reads the answer,
 * instead of whenever its flush process gets to it. Idempotent; without an open unsaved mark it costs one cache read.
 * A workspace a live store serves (the drive store) is committed instead: what the conversation changed becomes
 * visible in the drive, a conflict comes back as a note of the next call.
 */
class SandboxSettledTurnFlushListener implements AIConversationSettledOwnerTurnListener
{
    /**
     * @param AIConversation $conversation
     * @return void
     * @throws ConflictException
     * @throws InternalErrorException
     */
    public function afterSettledOwnerTurn(AIConversation $conversation): void
    {
        /** @var SandboxRunnerClientService $sandboxRunnerClientService */
        $sandboxRunnerClientService = DDDService::instance()->getService(SandboxRunnerClientService::class);
        if (!$sandboxRunnerClientService->isSandboxEnabled()) {
            return;
        }
        /** @var SandboxAgentWorkspaceKeysService $sandboxAgentWorkspaceKeysService */
        $sandboxAgentWorkspaceKeysService = DDDService::instance()->getService(SandboxAgentWorkspaceKeysService::class);
        $workspaceKey = $sandboxAgentWorkspaceKeysService->resolveWorkspaceKeyOfConversation($conversation);
        if ($workspaceKey === null) {
            return;
        }
        /** @var SandboxWorkspacesService $sandboxWorkspacesService */
        $sandboxWorkspacesService = DDDService::instance()->getService(SandboxWorkspacesService::class);
        if ($sandboxWorkspacesService->findLiveStoreOfWorkspace($workspaceKey) !== null) {
            // a drive workspace: the turn end commits what this conversation changed (never fails the turn)
            $rootConversationId = isset($conversation->id) ? (int)$conversation->getTreeRootConversationId() : 0;
            if ($rootConversationId > 0) {
                $sandboxWorkspacesService->commitAtTurnEnd(
                    $workspaceKey,
                    SandboxAgentWorkspaceKeysService::buildConversationKey($rootConversationId),
                    (int)($conversation->accountId ?? 0)
                );
            }
            return;
        }
        if ($sandboxWorkspacesService->findDirtyMark($workspaceKey) === null) {
            return;
        }
        $sandboxWorkspacesService->flushPendingChanges($workspaceKey);
    }
}
