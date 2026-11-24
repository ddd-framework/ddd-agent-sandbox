<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerPool;
use DDD\Domain\AI\Entities\Sandboxes\SandboxSessionRules;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;

/**
 * The per-call rules of a workspace: pool, timeout caps, background jobs, session environment. The default reads
 * `AI.Sandbox.runner` (timeouts, timezone, network) for every workspace alike. A host whose rules differ per agent
 * space, role or workspace overrides this service (same-path subclass under `App\`) and answers
 * {@see findRulesOfWorkspace()} per key.
 *
 * Network: with `AI.Sandbox.runner.network.mode` = `allowList` the session gets `HTTP_PROXY` / `HTTPS_PROXY` pointing to
 * the egress proxy (`network.egressProxyUrl`); the allow-list itself is enforced there and by the NetworkPolicy
 * (deploy/k8s/egress-proxy). With `off` (the default) the session gets no proxy and the NetworkPolicy lets nothing out.
 */
class SandboxSessionRulesService extends Service
{
    public const string NETWORK_MODE_OFF = 'off';

    public const string NETWORK_MODE_ALLOW_LIST = 'allowList';

    public const string DEFAULT_TIMEZONE = 'UTC';

    /**
     * The rules of the calls of one workspace.
     *
     * @param string $workspaceKey
     * @return SandboxSessionRules
     */
    public function findRulesOfWorkspace(string $workspaceKey): SandboxSessionRules
    {
        $sandboxSessionRules = new SandboxSessionRules();
        $sandboxSessionRules->poolName = SandboxRunnerPool::DEFAULT_POOL_NAME;
        $sandboxSessionRules->maxCommandTimeoutSeconds = max(1, (int)(Config::get('AI.Sandbox.runner.maxCommandTimeoutSeconds') ?? 600));
        $sandboxSessionRules->maxJobTimeoutSeconds = max(1, (int)(Config::get('AI.Sandbox.runner.maxJobTimeoutSeconds') ?? 3600));
        $sandboxSessionRules->allowsBackgroundJobs = (bool)(Config::get('AI.Sandbox.runner.allowBackgroundJobs') ?? true);
        $sandboxSessionRules->environment = $this->buildSessionEnvironment();
        return $sandboxSessionRules;
    }

    /**
     * The timeout a command runs with: the asked one, capped by the rules (a background job by its own cap).
     *
     * @param string $workspaceKey
     * @param int $requestedTimeoutSeconds
     * @param bool $background
     * @return int
     */
    public function capCommandTimeoutSeconds(string $workspaceKey, int $requestedTimeoutSeconds, bool $background): int
    {
        $sandboxSessionRules = $this->findRulesOfWorkspace($workspaceKey);
        $maxTimeoutSeconds = $background ? $sandboxSessionRules->maxJobTimeoutSeconds : $sandboxSessionRules->maxCommandTimeoutSeconds;
        return max(1, min($requestedTimeoutSeconds, $maxTimeoutSeconds));
    }

    /**
     * The network mode of `AI.Sandbox.runner.network.mode`: off (default) or allowList.
     *
     * @return string
     */
    public function getNetworkMode(): string
    {
        $networkMode = (string)(Config::get('AI.Sandbox.runner.network.mode') ?? self::NETWORK_MODE_OFF);
        return $networkMode === self::NETWORK_MODE_ALLOW_LIST ? self::NETWORK_MODE_ALLOW_LIST : self::NETWORK_MODE_OFF;
    }

    /**
     * @return string[] environment variables by name: TZ, and the egress proxy when the network is an allow-list
     */
    protected function buildSessionEnvironment(): array
    {
        $timezone = trim((string)(Config::get('AI.Sandbox.runner.timezone') ?? ''));
        $environment = ['TZ' => $timezone !== '' ? $timezone : self::DEFAULT_TIMEZONE];
        $egressProxyUrl = trim((string)(Config::get('AI.Sandbox.runner.network.egressProxyUrl') ?? ''));
        if ($this->getNetworkMode() === self::NETWORK_MODE_ALLOW_LIST && $egressProxyUrl !== '') {
            $environment['HTTP_PROXY'] = $egressProxyUrl;
            $environment['HTTPS_PROXY'] = $egressProxyUrl;
            $environment['http_proxy'] = $egressProxyUrl;
            $environment['https_proxy'] = $egressProxyUrl;
            $environment['NO_PROXY'] = 'localhost,127.0.0.1';
        }
        return $environment;
    }
}
