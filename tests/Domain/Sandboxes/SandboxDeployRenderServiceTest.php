<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Domain\Sandboxes;

use DDD\Domain\AI\Services\Sandboxes\SandboxDeployRenderService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class SandboxDeployRenderServiceTest extends TestCase
{
    public function testTheOverlayReferencesTheBaseAndSetsImageAndLimits(): void
    {
        $sandboxDeployRenderService = new SandboxDeployRenderService();
        $overlayDirectory = dirname(__DIR__, 3) . '/var/test-overlay';
        $overlayFiles = $sandboxDeployRenderService->renderOverlayFiles($overlayDirectory, 'ghcr.io/example/project-sandbox-runner', '0.1.0-abc');
        self::assertSame(['kustomization.yaml', 'network-policy-patch.yaml', 'runner-patch.yaml'], array_keys($overlayFiles));
        $kustomization = Yaml::parse($overlayFiles['kustomization.yaml']);
        self::assertSame(['../../deploy/k8s/base'], $kustomization['resources']);
        self::assertSame('0.1.0-abc', $kustomization['images'][0]['newTag']);
        $runnerPatch = Yaml::parse($overlayFiles['runner-patch.yaml']);
        self::assertSame('gvisor', $runnerPatch['spec']['template']['spec']['runtimeClassName']);
        self::assertSame((string)(int)floor(4 * 1024 ** 3 * 0.9), $runnerPatch['spec']['template']['spec']['containers'][0]['env'][0]['value']);
        self::assertDirectoryExists($sandboxDeployRenderService->getModuleDeployDirectory() . '/base');
    }

    public function testQuantities(): void
    {
        self::assertSame(4 * 1024 ** 3, SandboxDeployRenderService::parseQuantityBytes('4Gi'));
        self::assertSame(500 * 1024 ** 2, SandboxDeployRenderService::parseQuantityBytes('500Mi'));
        self::assertSame(2_000_000_000, SandboxDeployRenderService::parseQuantityBytes('2G'));
    }
}
