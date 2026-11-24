<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxConversations;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What the sandbox administration asks about the users of a workspace: the agent space a key belongs to and its names,
 * the conversations of a space, and whether a turn runs there now (an admin action then needs force and a reason).
 *
 * The agent part of this module implements it beside `mgamadeus/ddd-agents`
 * ({@see \DDD\Domain\AI\Services\Sandboxes\Agents\SandboxAgentWorkspaceActivityService}). Without an implementation no
 * turns exist: every action runs and the screen shows no conversations. A host may register its own (autoconfigured by
 * the tag); the first registered one answers.
 */
#[AutoconfigureTag(SandboxWorkspaceActivity::TAG)]
interface SandboxWorkspaceActivity
{
    public const string TAG = 'ai.sandbox_workspace_activity';

    /** The agent space a workspace key belongs to; null for a key of no space */
    public function findAgentSpaceIdOfWorkspaceKey(string $workspaceKey): ?int;

    /** The name of the workspace as the admin knows it (its space's name); null = show the key */
    public function findDisplayNameOfWorkspaceKey(string $workspaceKey): ?string;

    /** The name of the account the workspace belongs to; null when unknown */
    public function findOwnerDisplayNameOfWorkspaceKey(string $workspaceKey): ?string;

    /**
     * @param int[] $agentSpaceIds
     * @return int[] root conversation count by agent space id; a space without conversations is missing
     */
    public function countConversationsPerAgentSpaceId(array $agentSpaceIds): array;

    /**
     * @param int[] $agentSpaceIds
     * @return int[] the spaces among them on which a conversation holds a live turn now
     */
    public function findAgentSpaceIdsWithALiveTurn(array $agentSpaceIds): array;

    /** The newest root conversations of a space, newest change first */
    public function findNewestConversationsOfAgentSpace(int $agentSpaceId, int $limit): SandboxConversations;
}
