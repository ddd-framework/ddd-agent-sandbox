<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Domain\Sandboxes\Installation;

use DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntry;
use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider;
use DDD\Modules\AgentSandbox\AgentSandboxModule;
use DDD\Tests\AgentSandbox\Fakes\SwitchableSandboxFeatureTableConditionProviderService;
use PHPUnit\Framework\TestCase;

/** The sandbox tables are required by app:agent:check-installation of ddd-agents only while the sandbox is switched on */
class SandboxFeatureTableConditionProviderTest extends TestCase
{
    public function testTheActionLogTableIsNotRequiredWhileTheSandboxIsOff(): void
    {
        $providerService = new SwitchableSandboxFeatureTableConditionProviderService();

        $reason = $providerService->getFeatureTableConditions()->findReasonTableIsNotRequired(SandboxActionLogEntry::class, []);

        self::assertNotNull($reason);
        self::assertStringContainsString('switched off', $reason);
    }

    public function testTheActionLogTableIsRequiredWhileTheSandboxIsOn(): void
    {
        $providerService = new SwitchableSandboxFeatureTableConditionProviderService();
        $providerService->sandboxEnabled = true;

        self::assertNull($providerService->getFeatureTableConditions()->findReasonTableIsNotRequired(SandboxActionLogEntry::class, []));
    }

    public function testEveryDatabaseEntityOfThePackageHasACondition(): void
    {
        $sourceDirectory = dirname(__DIR__, 4) . '/src/Domain/AI/Entities';
        $databaseEntityClassNames = [];
        $directoryIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($directoryIterator as $fileInfo) {
            $source = (string)file_get_contents($fileInfo->getPathname());
            if (str_contains($source, 'LazyLoadRepo::DB') && preg_match('/^namespace (.+);$/m', $source, $namespaceMatch) === 1
                && !str_ends_with($fileInfo->getBasename('.php'), 's')) {
                $databaseEntityClassNames[] = $namespaceMatch[1] . '\\' . $fileInfo->getBasename('.php');
            }
        }

        self::assertSame([SandboxActionLogEntry::class], $databaseEntityClassNames);
        self::assertSame($databaseEntityClassNames, SwitchableSandboxFeatureTableConditionProviderService::SANDBOX_TABLE_ENTITY_CLASSES);
    }

    public function testTheProviderRegistersOnlyBesideTheTableConditionPort(): void
    {
        self::assertInstanceOf(AgentFeatureTableConditionProvider::class, new SwitchableSandboxFeatureTableConditionProviderService());
        self::assertSame(
            AgentFeatureTableConditionProvider::class,
            AgentSandboxModule::getNamespacesRequiringClasses()['DDD\\Domain\\AI\\Services\\Sandboxes\\Installation\\']
        );
    }
}
