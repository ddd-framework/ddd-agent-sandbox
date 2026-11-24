<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes;

use DDD\Domain\AI\Services\Sandboxes\SandboxRunnerClientService;
use DDD\Domain\AI\Services\Sandboxes\SandboxSessionRulesService;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Infrastructure\Cache\Cache;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** The workspace service on a fake runner, an in-memory store and cache; flush processes are counted, not started */
class TestableSandboxWorkspacesService extends SandboxWorkspacesService
{
    public FakeSandboxRunnerClientService $fakeSandboxRunnerClientService;

    public InMemorySandboxCache $inMemorySandboxCache;

    public InMemorySandboxWorkspaceStore $inMemorySandboxWorkspaceStore;

    /** @var string[] workspace keys of the flush processes started */
    public array $startedFlushProcesses = [];

    public function __construct()
    {
        $this->fakeSandboxRunnerClientService = new FakeSandboxRunnerClientService();
        $this->inMemorySandboxCache = new InMemorySandboxCache();
        $this->inMemorySandboxWorkspaceStore = new InMemorySandboxWorkspaceStore();
        $this->setWorkspaceStore($this->inMemorySandboxWorkspaceStore);
    }

    protected function getCache(): Cache
    {
        return $this->inMemorySandboxCache;
    }

    protected function getSandboxRunnerClientService(): SandboxRunnerClientService
    {
        return $this->fakeSandboxRunnerClientService;
    }

    protected function getSandboxSessionRulesService(): SandboxSessionRulesService
    {
        return new SandboxSessionRulesService();
    }

    protected function startFlushProcess(string $workspaceKey): void
    {
        $this->startedFlushProcesses[] = $workspaceKey;
    }

    protected function pauseBeforeRetry(): void
    {
    }

    public function getTimingLogger(): LoggerInterface
    {
        return new NullLogger();
    }
}
