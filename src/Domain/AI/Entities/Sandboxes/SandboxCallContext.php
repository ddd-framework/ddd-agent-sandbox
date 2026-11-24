<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * Who a workspace call is for: the workspace and conversation, the turn, the agent space and the operator whose rights
 * the store's writes run with. The agent part sets it per tool call ({@see
 * \DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService::setCallContext()}); a console or a job sets none.
 */
class SandboxCallContext
{
    public string $workspaceKey = '';

    public string $conversationKey = '';

    /** @var string|null The turn of the call (the owner message it answers); null without a turn */
    public ?string $turnKey = null;

    /** @var int|null The agent space of the call */
    public ?int $agentSpaceId = null;

    /** @var int|null The account whose rights the store's reads and writes use (the conversation's owner) */
    public ?int $operatorAccountId = null;

    /** @var object|null The caller's own description of the call (beside ddd-agents its AgentToolCallProvenance) */
    public ?object $callProvenance = null;
}
