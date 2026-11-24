<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Symfony\Commands;

use DDD\DDDBundle;
use DDD\Domain\AI\Services\Sandboxes\SandboxManifestWorkspaceStoreService;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Symfony\Commands\Sandbox\SandboxSeed;
use DDD\Tests\AgentSandbox\Fakes\TestableSandboxWorkspacesService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:agent-sandbox:seed` on a host whose sandbox is off or whose workspace store is not configured skips the preload
 * workspace with a note and exits 0; a configured store is seeded, and its errors still fail the command.
 */
class SandboxSeedTest extends TestCase
{
    protected string $temporaryDirectory;

    protected TestableSandboxWorkspacesService $sandboxWorkspacesService;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/sandbox-seed-' . bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory . '/preload/kits', 0777, true);
        file_put_contents($this->temporaryDirectory . '/preload/README.md', "# Library\n");
        file_put_contents($this->temporaryDirectory . '/preload/kits/report.py', "print('report')\n");
        $this->sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $this->setContainerInstance(SandboxWorkspacesService::class, $this->sandboxWorkspacesService);
    }

    protected function tearDown(): void
    {
        $this->setContainerInstance(SandboxWorkspacesService::class, null);
        exec('rm -r ' . escapeshellarg($this->temporaryDirectory));
    }

    protected function setContainerInstance(string $className, ?object $instance): void
    {
        $container = (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
        $testInstancesProperty = new ReflectionProperty($container, 'testInstances');
        $testInstances = $testInstancesProperty->getValue($container);
        if ($instance === null) {
            unset($testInstances[$className]);
        } else {
            $testInstances[$className] = $instance;
        }
        $testInstancesProperty->setValue($container, $testInstances);
    }

    protected function runSeed(): CommandTester
    {
        $commandTester = new CommandTester(new SandboxSeed());
        $commandTester->execute(['--directory' => $this->temporaryDirectory . '/preload']);
        return $commandTester;
    }

    protected function normalizedDisplayOf(CommandTester $commandTester): string
    {
        return (string)preg_replace('/\s+/', ' ', str_replace(['!', '[NOTE]', '[OK]'], ' ', $commandTester->getDisplay()));
    }

    public function testADisabledSandboxSkipsThePreloadWorkspaceAndSucceeds(): void
    {
        $this->sandboxWorkspacesService->fakeSandboxRunnerClientService->isSandboxEnabledForTest = false;
        $commandTester = $this->runSeed();
        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('preload workspace skipped: the sandbox is disabled', $this->normalizedDisplayOf($commandTester));
        self::assertSame(0, $this->sandboxWorkspacesService->inMemorySandboxWorkspaceStore->readManifest(SandboxWorkspacesService::PRELOAD_WORKSPACE_KEY)->countFiles(), 'nothing written to the workspace store');
    }

    public function testAMissingObjectStoreSkipsThePreloadWorkspaceAndSucceeds(): void
    {
        $unconfiguredManifestStore = new SandboxManifestWorkspaceStoreService();
        $this->sandboxWorkspacesService->setWorkspaceStore($unconfiguredManifestStore);
        self::assertFalse($unconfiguredManifestStore->isObjectStorageConfigured());
        $commandTester = $this->runSeed();
        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $display = $this->normalizedDisplayOf($commandTester);
        self::assertStringContainsString('preload workspace skipped: the object store "sandbox-workspaces" of the sandbox workspaces is not configured', $display);
        self::assertStringContainsString('OBJECT_STORAGE_SANDBOX_WORKSPACES_*', $display);
    }

    public function testAConfiguredStoreIsSeeded(): void
    {
        $manifestStore = new SandboxManifestWorkspaceStoreService();
        $manifestStore->setObjectStorage(new FilesystemObjectStorage($this->temporaryDirectory . '/store'));
        $this->sandboxWorkspacesService->setWorkspaceStore($manifestStore);
        $commandTester = $this->runSeed();
        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        self::assertStringContainsString('preload: 2 files', $this->normalizedDisplayOf($commandTester));
        self::assertSame(2, $manifestStore->readManifest(SandboxWorkspacesService::PRELOAD_WORKSPACE_KEY)->countFiles());
    }

    public function testAnErrorOfAConfiguredStoreStillFails(): void
    {
        $manifestStore = new SandboxManifestWorkspaceStoreService();
        $manifestStore->setObjectStorage(new class ($this->temporaryDirectory . '/store') extends FilesystemObjectStorage {
            public function putObject(string $key, string $content, ?string $contentType = null): void
            {
                throw new \RuntimeException('the store refused the write');
            }
        });
        $this->sandboxWorkspacesService->setWorkspaceStore($manifestStore);
        $commandTester = $this->runSeed();
        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        self::assertStringContainsString('the store refused the write', $this->normalizedDisplayOf($commandTester));
    }
}
