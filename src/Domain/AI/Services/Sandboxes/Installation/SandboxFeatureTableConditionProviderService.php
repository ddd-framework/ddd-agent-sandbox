<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes\Installation;

use DDD\Domain\AI\Entities\Installation\AgentFeatureTableCondition;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntry;
use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider;
use DDD\Domain\AI\Services\Sandboxes\SandboxRunnerClientService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The tables of the sandbox for `app:agent:check-installation` of ddd-agents (>= 0.5.0): `SandboxActionLogEntries` is
 * read and written only while the sandbox is switched on (`AI.Sandbox.runner.enabled`, or its env variable
 * AGENT_SANDBOX_ENABLED), so a host with the sandbox off may leave it out. Registered only beside ddd-agents 0.5.0 or
 * later (`AgentSandboxModule::getNamespacesRequiringClasses()`).
 */
class SandboxFeatureTableConditionProviderService extends Service implements AgentFeatureTableConditionProvider
{
    /** @var string What reads the tables, for the reason of the check */
    public const string SANDBOX_FEATURE_DESCRIPTION = 'the sandbox (AI.Sandbox.runner.enabled / AGENT_SANDBOX_ENABLED)';

    /** @var string[] The entities whose tables only the switched-on sandbox reads */
    public const array SANDBOX_TABLE_ENTITY_CLASSES = [SandboxActionLogEntry::class];

    public function getFeatureTableConditions(): AgentFeatureTableConditions
    {
        $agentFeatureTableConditions = new AgentFeatureTableConditions();
        $isSandboxEnabled = $this->isSandboxEnabled();
        foreach (static::SANDBOX_TABLE_ENTITY_CLASSES as $entityClassName) {
            $sandboxTableCondition = AgentFeatureTableCondition::create($entityClassName, static::SANDBOX_FEATURE_DESCRIPTION, $isSandboxEnabled);
            $agentFeatureTableConditions->add($sandboxTableCondition);
        }
        return $agentFeatureTableConditions;
    }

    /** Whether the sandbox is switched on */
    protected function isSandboxEnabled(): bool
    {
        /** @var SandboxRunnerClientService $sandboxRunnerClientService */
        $sandboxRunnerClientService = DDDService::instance()->getService(SandboxRunnerClientService::class);
        return $sandboxRunnerClientService->isSandboxEnabled();
    }
}
