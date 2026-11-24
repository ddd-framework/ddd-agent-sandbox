<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Every sandbox workspace the administration knows, running or offloaded.
 *
 * @property SandboxWorkspace[] $elements
 * @method SandboxWorkspace|null first()
 * @method SandboxWorkspace|null getByUniqueKey(string $uniqueKey)
 * @method SandboxWorkspace[] getElements()
 */
class SandboxWorkspaces extends ObjectSet
{
    /**
     * @return int[] the agent spaces of the workspaces, each once
     */
    public function getAgentSpaceIds(): array
    {
        $agentSpaceIds = [];
        foreach ($this->getElements() as $sandboxWorkspace) {
            if ($sandboxWorkspace->agentSpaceId !== null) {
                $agentSpaceIds[$sandboxWorkspace->agentSpaceId] = $sandboxWorkspace->agentSpaceId;
            }
        }
        return array_values($agentSpaceIds);
    }
}
