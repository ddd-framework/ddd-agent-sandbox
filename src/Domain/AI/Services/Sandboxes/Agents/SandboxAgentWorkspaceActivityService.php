<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes\Agents;

use DDD\Domain\AI\Entities\Sandboxes\SandboxConversation;
use DDD\Domain\AI\Entities\Sandboxes\SandboxConversations;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspaceActivity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The workspace activity over the conversations of `mgamadeus/ddd-agents`: the space of a `space-<id>` key, the
 * conversations of a space, and whether a turn runs there now. A workspace's display name is its key here; a host
 * that names its spaces overrides {@see findDisplayNameOfWorkspaceKey()} (same-path subclass under `App\`).
 */
class SandboxAgentWorkspaceActivityService extends Service implements SandboxWorkspaceActivity
{
    public function findAgentSpaceIdOfWorkspaceKey(string $workspaceKey): ?int
    {
        return $this->getSandboxAgentWorkspaceKeysService()->findAgentSpaceIdOfWorkspaceKey($workspaceKey);
    }

    public function findDisplayNameOfWorkspaceKey(string $workspaceKey): ?string
    {
        return null;
    }

    public function findOwnerDisplayNameOfWorkspaceKey(string $workspaceKey): ?string
    {
        $ownerAccountId = $this->getSandboxAgentWorkspaceKeysService()->findOwnerAccountIdOfWorkspaceKey($workspaceKey);
        if ($ownerAccountId === null) {
            return null;
        }
        /** @var Account|null $ownerAccount */
        $ownerAccount = Account::getService()->find($ownerAccountId);
        return $ownerAccount?->email;
    }

    /**
     * @param int[] $agentSpaceIds
     * @return int[]
     */
    public function countConversationsPerAgentSpaceId(array $agentSpaceIds): array
    {
        return $this->getAIConversationsService()->countRootConversationsPerAgentSpaceId($agentSpaceIds);
    }

    /**
     * @param int[] $agentSpaceIds
     * @return int[]
     */
    public function findAgentSpaceIdsWithALiveTurn(array $agentSpaceIds): array
    {
        return $this->getAIConversationsService()->findAgentSpaceIdsWithALiveTurn($agentSpaceIds);
    }

    public function findNewestConversationsOfAgentSpace(int $agentSpaceId, int $limit): SandboxConversations
    {
        $sandboxConversations = new SandboxConversations();
        foreach ($this->getAIConversationsService()->findNewestRootConversationsOfAgentSpaceId($agentSpaceId, $limit)->getElements() as $agentConversation) {
            $sandboxConversation = new SandboxConversation();
            $sandboxConversation->conversationId = (int)$agentConversation->conversationId;
            $sandboxConversation->accountId = $agentConversation->accountId;
            $sandboxConversation->runState = $agentConversation->runState;
            $sandboxConversation->purposeName = $agentConversation->purposeName;
            $sandboxConversation->createdAt = $agentConversation->createdAt;
            $sandboxConversation->updatedAt = $agentConversation->updatedAt;
            $sandboxConversations->add($sandboxConversation);
        }
        return $sandboxConversations;
    }

    protected function getAIConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    protected function getSandboxAgentWorkspaceKeysService(): SandboxAgentWorkspaceKeysService
    {
        /** @var SandboxAgentWorkspaceKeysService $sandboxAgentWorkspaceKeysService */
        $sandboxAgentWorkspaceKeysService = DDDService::instance()->getService(SandboxAgentWorkspaceKeysService::class);
        return $sandboxAgentWorkspaceKeysService;
    }
}
