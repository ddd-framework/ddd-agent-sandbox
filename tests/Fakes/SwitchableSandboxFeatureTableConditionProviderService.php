<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes;

use DDD\Domain\AI\Services\Sandboxes\Installation\SandboxFeatureTableConditionProviderService;

/** The sandbox table conditions with the sandbox switch set by the test */
class SwitchableSandboxFeatureTableConditionProviderService extends SandboxFeatureTableConditionProviderService
{
    public bool $sandboxEnabled = false;

    protected function isSandboxEnabled(): bool
    {
        return $this->sandboxEnabled;
    }
}
