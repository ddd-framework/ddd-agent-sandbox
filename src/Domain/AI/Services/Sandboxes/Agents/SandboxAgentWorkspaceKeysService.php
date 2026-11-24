<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\Service;

/**
 * The workspace of an agent's call (beside `mgamadeus/ddd-agents`):
 *
 * - the key the call carries (ddd-agents sets one per eval case × repeat, `eval-<caseKey>-<repeat>-<runId>`);
 * - else the conversation's agent space: `space-<agentSpaceId>` (every conversation of the space sees the same files);
 * - else the conversation's owner account: `account-<accountId>` (a conversation at account level).
 *
 * The conversation of a call is the root of its delegation tree (a sub-agent works in its root's shell):
 * `conversation-<rootConversationId>`, or "harness" without a conversation. A host with other keys overrides this
 * service (same-path subclass under `App\`).
 */
class SandboxAgentWorkspaceKeysService extends Service
{
    /** @var string Prefix of the workspace of an agent space */
    public const string WORKSPACE_KEY_PREFIX_SPACE = 'space-';

    /** @var string Prefix of the workspace of a conversation without a space */
    public const string WORKSPACE_KEY_PREFIX_ACCOUNT = 'account-';

    /** @var string Prefix of the workspace of an eval case run */
    public const string WORKSPACE_KEY_PREFIX_EVAL = 'eval-';

    /** @var string Prefix of the conversation key of a delegation tree */
    public const string CONVERSATION_KEY_PREFIX = 'conversation-';

    /**
     * @param AgentToolCallProvenance|null $agentToolCallProvenance
     * @return string the provenance's key, `space-<id>` or `account-<id>`
     * @throws ForbiddenException without a space and an account, or a key that is no plain key
     */
    public function resolveWorkspaceKeyOfCall(?AgentToolCallProvenance $agentToolCallProvenance): string
    {
        $provenanceWorkspaceKey = (string)($agentToolCallProvenance?->workspaceKey ?? '');
        if ($provenanceWorkspaceKey !== '') {
            if (preg_match(SandboxWorkspacesService::WORKSPACE_KEY_PATTERN, $provenanceWorkspaceKey) !== 1) {
                throw new ForbiddenException('The workspace key of the call is no plain key: ' . $provenanceWorkspaceKey);
            }
            return $provenanceWorkspaceKey;
        }
        $agentSpaceId = (int)($agentToolCallProvenance?->agentSpaceId ?? 0);
        if ($agentSpaceId > 0) {
            return static::WORKSPACE_KEY_PREFIX_SPACE . $agentSpaceId;
        }
        $authAccountId = (int)(AuthService::instance()->getAccount()?->id ?? 0);
        $accountId = $agentToolCallProvenance?->getConversationOwnerAccountIdOrAuthAccountId($authAccountId > 0 ? $authAccountId : null)
            ?? ($authAccountId > 0 ? $authAccountId : null);
        if ($accountId === null) {
            throw new ForbiddenException('The workspace needs a conversation with an agent space or an account.');
        }
        return static::WORKSPACE_KEY_PREFIX_ACCOUNT . $accountId;
    }

    /**
     * The conversation a call belongs to, for its shell and cwd: the root of the delegation tree, or "harness".
     *
     * @param AgentToolCallProvenance|null $agentToolCallProvenance
     * @return string
     */
    public function resolveConversationKeyOfCall(?AgentToolCallProvenance $agentToolCallProvenance): string
    {
        $conversationId = (int)(isset($agentToolCallProvenance?->conversation->id)
            ? $agentToolCallProvenance->conversation->getTreeRootConversationId()
            : ($agentToolCallProvenance?->rootConversationId ?? $agentToolCallProvenance?->conversationId ?? 0));
        return $conversationId > 0 ? static::buildConversationKey($conversationId) : SandboxWorkspacesService::CONVERSATION_KEY_WITHOUT_CONVERSATION;
    }

    /**
     * The workspace the conversation's tools worked in, as {@see resolveWorkspaceKeyOfCall()} chose it: the run's own
     * key (an eval case), else the space's, else the owner account's.
     *
     * @param AIConversation $conversation
     * @return string|null null when the conversation names none of them
     */
    public function resolveWorkspaceKeyOfConversation(AIConversation $conversation): ?string
    {
        $runWorkspaceKey = (string)((isset($conversation->runSettings) ? $conversation->runSettings->workspaceKey : null) ?? '');
        if ($runWorkspaceKey !== '') {
            return preg_match(SandboxWorkspacesService::WORKSPACE_KEY_PATTERN, $runWorkspaceKey) === 1 ? $runWorkspaceKey : null;
        }
        $agentSpaceId = (int)($conversation->agentSpaceId ?? 0);
        if ($agentSpaceId > 0) {
            return static::WORKSPACE_KEY_PREFIX_SPACE . $agentSpaceId;
        }
        $accountId = (int)($conversation->accountId ?? 0);
        return $accountId > 0 ? static::WORKSPACE_KEY_PREFIX_ACCOUNT . $accountId : null;
    }

    /**
     * @param int $rootConversationId the root of a delegation tree
     * @return string the conversation key of its shell (`conversation-<id>`)
     */
    public static function buildConversationKey(int $rootConversationId): string
    {
        return static::CONVERSATION_KEY_PREFIX . $rootConversationId;
    }

    /**
     * @param string $workspaceKey
     * @return int|null the agent space of a `space-<id>` key
     */
    public function findAgentSpaceIdOfWorkspaceKey(string $workspaceKey): ?int
    {
        if (!str_starts_with($workspaceKey, static::WORKSPACE_KEY_PREFIX_SPACE)) {
            return null;
        }
        $agentSpaceId = (int)substr($workspaceKey, strlen(static::WORKSPACE_KEY_PREFIX_SPACE));
        return $agentSpaceId > 0 ? $agentSpaceId : null;
    }

    /**
     * @param string $workspaceKey
     * @return int|null the account of an `account-<id>` key, or the owner of the space of a `space-<id>` key
     */
    public function findOwnerAccountIdOfWorkspaceKey(string $workspaceKey): ?int
    {
        if (str_starts_with($workspaceKey, static::WORKSPACE_KEY_PREFIX_ACCOUNT)) {
            $accountId = (int)substr($workspaceKey, strlen(static::WORKSPACE_KEY_PREFIX_ACCOUNT));
            return $accountId > 0 ? $accountId : null;
        }
        $agentSpaceId = $this->findAgentSpaceIdOfWorkspaceKey($workspaceKey);
        if ($agentSpaceId === null) {
            return null;
        }
        /** @var AgentSpace|null $agentSpace */
        $agentSpace = AgentSpace::getService()->find($agentSpaceId);
        $ownerAccountId = $agentSpace?->findOwnerAccount()?->id;
        return $ownerAccountId !== null ? (int)$ownerAccountId : null;
    }

    /**
     * Whose workspace it is, as one phrase of the state note.
     *
     * @param string $workspaceKey
     * @return string
     */
    public function describeWorkspaceOwner(string $workspaceKey): string
    {
        if (str_starts_with($workspaceKey, static::WORKSPACE_KEY_PREFIX_ACCOUNT)) {
            return 'your personal workspace, persistent: conversations without a space share it';
        }
        if (str_starts_with($workspaceKey, static::WORKSPACE_KEY_PREFIX_SPACE)) {
            return 'the workspace of this space, persistent: shared by every conversation of this space';
        }
        return 'persistent';
    }
}
