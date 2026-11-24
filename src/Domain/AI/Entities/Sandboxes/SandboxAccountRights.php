<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * The account rights of the sandbox's agent part, named by this package. The MCP package `agent.sandbox.shell` carries
 * `#[MCPRequiresAccountRights(SandboxAccountRights::USE_SANDBOX)]` and the skill `Agent.Sandbox.Shell` names the same
 * right in `requiredAccountRights`. An application grants it through ddd-agents (`AI.accountRights`, by default to the
 * superadmins) or its own rights model.
 */
class SandboxAccountRights
{
    /** @var string Run commands and work with files in the agent's sandbox (`Agent.Sandbox.*`) */
    public const string USE_SANDBOX = 'useSandbox';

    /** @var string[] Every right of this package */
    public const array RIGHTS = [self::USE_SANDBOX];
}
