<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Sandbox;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerContract;
use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerPodCheck;
use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerPool;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DDD\Domain\AI\Services\Sandboxes\SandboxImageConfigService;
use DDD\Domain\AI\Services\Sandboxes\SandboxManifestWorkspaceStoreService;
use DDD\Domain\AI\Services\Sandboxes\SandboxRunnerClientService;
use DDD\Domain\AI\Services\Sandboxes\SandboxSessionRulesService;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The deploy check of the sandbox runner: every pod of every pool answers `/healthz` ready, with a contract version this
 * package works with (same major, at least the minimum, while the major is 0 the same minor), the image configuration
 * hash of the project's image.yaml (none without one), and `drive` when a store other than the manifest store needs
 * it. Exit 1 on any problem.
 *
 * `--isolation` claims a throwaway session and checks from inside it that the network is closed as configured: no DNS,
 * no public address, no cluster DNS, no API server (with an allow-list: only the egress proxy answers). Exit 1 when a
 * session reaches what it must not.
 */
#[AsCommand(
    name: 'app:agent-sandbox:check-runner',
    description: 'Checks the sandbox runner pods: /healthz ready, contract version (SandboxRunnerContract), image configuration hash of image.yaml, capabilities; --isolation probes the network from inside a session. Exit 1 on a problem',
)]
class SandboxCheckRunner extends Command
{
    /** @var string The workspace key of the isolation probe (removed afterwards) */
    public const string ISOLATION_PROBE_WORKSPACE_KEY_PREFIX = 'isolation-probe-';

    /** @var string[] The probes run inside the session: name => command; each must FAIL for an isolated session */
    public const array ISOLATION_PROBES = [
        'public DNS' => 'getent hosts example.com',
        'public address' => 'timeout 5 bash -c "exec 3<>/dev/tcp/1.1.1.1/443"',
        'cluster DNS' => 'getent hosts kubernetes.default.svc.cluster.local',
        'API server' => 'timeout 5 bash -c "exec 3<>/dev/tcp/${KUBERNETES_SERVICE_HOST:-10.96.0.1}/${KUBERNETES_SERVICE_PORT:-443}"',
        'service account token' => 'test -e /var/run/secrets/kubernetes.io/serviceaccount/token',
    ];

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
        $this->addOption('isolation', null, InputOption::VALUE_NONE, 'Also probe the network isolation from inside a throwaway session');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        /** @var SandboxRunnerClientService $sandboxRunnerClientService */
        $sandboxRunnerClientService = DDDService::instance()->getService(SandboxRunnerClientService::class);
        $expectedImageConfigHash = $this->findExpectedImageConfigHash($symfonyStyle);
        $requiresDrive = (string)(Config::get('AI.Sandbox.workspaces.store') ?? SandboxManifestWorkspaceStoreService::STORE_NAME) !== SandboxManifestWorkspaceStoreService::STORE_NAME;
        $podChecks = [];
        foreach ($this->findPoolNames() as $poolName) {
            try {
                $runnerAddresses = $sandboxRunnerClientService->resolveRunnerAddresses($poolName);
            } catch (SandboxRunnerException $sandboxRunnerException) {
                $podCheck = new SandboxRunnerPodCheck();
                $podCheck->poolName = $poolName;
                $podCheck->runnerAddress = $sandboxRunnerClientService->getRunnerPool($poolName)->host;
                $podCheck->problems[] = 'no pod resolves: ' . $sandboxRunnerException->getMessage();
                $podChecks[] = $podCheck;
                continue;
            }
            sort($runnerAddresses);
            foreach ($runnerAddresses as $runnerAddress) {
                $podChecks[] = $this->checkPod($sandboxRunnerClientService, $poolName, $runnerAddress, $expectedImageConfigHash, $requiresDrive);
            }
        }
        $isolationProblems = $input->getOption('isolation') ? $this->probeIsolation($symfonyStyle) : [];
        $isHealthy = $podChecks !== [] && $isolationProblems === [];
        foreach ($podChecks as $podCheck) {
            $isHealthy = $isHealthy && $podCheck->problems === [];
        }
        if ($input->getOption('format') === 'json') {
            $output->writeln((string)json_encode([
                'packageContractVersion' => SandboxRunnerContract::CONTRACT_VERSION,
                'minimumContractVersion' => SandboxRunnerContract::MINIMUM_CONTRACT_VERSION,
                'expectedImageConfigHash' => $expectedImageConfigHash,
                'healthy' => $isHealthy,
                'pods' => array_map(static fn(SandboxRunnerPodCheck $podCheck): array => $podCheck->toOutputRow(), $podChecks),
                'isolationProblems' => $isolationProblems,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $isHealthy ? Command::SUCCESS : Command::FAILURE;
        }
        $tableRows = [];
        foreach ($podChecks as $podCheck) {
            $tableRows[] = [
                $podCheck->poolName,
                $podCheck->runnerAddress,
                $podCheck->health?->status ?? '—',
                $podCheck->health?->state ?? '—',
                $podCheck->health?->contractVersion ?? '—',
                $podCheck->health?->toolkit ?? '—',
                $podCheck->health?->imageConfigHash !== null && $podCheck->health->imageConfigHash !== '' ? substr($podCheck->health->imageConfigHash, 0, 12) : '—',
                $podCheck->problems === [] ? 'OK' : implode('; ', $podCheck->problems),
            ];
        }
        $symfonyStyle->table(['Pool', 'Pod', 'Status', 'State', 'Contract', 'Toolkit', 'Image config', 'Check'], $tableRows);
        foreach ($isolationProblems as $isolationProblem) {
            $symfonyStyle->error('isolation: ' . $isolationProblem);
        }
        if (!$isHealthy) {
            $symfonyStyle->error('The sandbox runner does not fit this package or project (contract ' . SandboxRunnerContract::CONTRACT_VERSION . ', at least ' . SandboxRunnerContract::MINIMUM_CONTRACT_VERSION . ').');
            return Command::FAILURE;
        }
        $symfonyStyle->success('Every runner pod answers with a matching contract' . ($input->getOption('isolation') ? ' and the sessions are isolated as configured.' : '.'));
        return Command::SUCCESS;
    }

    /**
     * @return string[] the default pool and every configured pool
     */
    protected function findPoolNames(): array
    {
        $poolNames = [SandboxRunnerPool::DEFAULT_POOL_NAME];
        $configuredPools = Config::get('AI.Sandbox.runner.pools');
        foreach (is_array($configuredPools) ? array_keys($configuredPools) : [] as $poolName) {
            $poolNames[] = (string)$poolName;
        }
        return array_values(array_unique($poolNames));
    }

    /**
     * @return string the hash the project's image.yaml gives ('' without one); null when image.yaml is broken
     */
    protected function findExpectedImageConfigHash(SymfonyStyle $symfonyStyle): ?string
    {
        /** @var SandboxImageConfigService $sandboxImageConfigService */
        $sandboxImageConfigService = DDDService::instance()->getService(SandboxImageConfigService::class);
        try {
            return $sandboxImageConfigService->findImageConfig()?->configHash ?? '';
        } catch (Throwable $throwable) {
            $symfonyStyle->warning('image.yaml is not valid: ' . $throwable->getMessage());
            return null;
        }
    }

    protected function checkPod(SandboxRunnerClientService $sandboxRunnerClientService, string $poolName, string $runnerAddress, ?string $expectedImageConfigHash, bool $requiresDrive): SandboxRunnerPodCheck
    {
        $podCheck = new SandboxRunnerPodCheck();
        $podCheck->poolName = $poolName;
        $podCheck->runnerAddress = $runnerAddress;
        try {
            $podCheck->health = $sandboxRunnerClientService->readHealth($runnerAddress);
        } catch (Throwable $throwable) {
            $podCheck->problems[] = 'no answer (' . $throwable->getMessage() . ')';
            return $podCheck;
        }
        $sandboxRunnerHealth = $podCheck->health;
        if (!$sandboxRunnerHealth->isReady()) {
            $podCheck->problems[] = 'not ready (HTTP ' . $sandboxRunnerHealth->httpStatusCode . ', status ' . ($sandboxRunnerHealth->status ?? '?') . ')';
        }
        if (!SandboxRunnerContract::isCompatibleContractVersion($sandboxRunnerHealth->contractVersion)) {
            $podCheck->problems[] = 'contract ' . ($sandboxRunnerHealth->contractVersion ?? 'unknown') . ' does not serve this package (needs '
                . SandboxRunnerContract::MINIMUM_CONTRACT_VERSION . ' or newer, same major, while 0.x the same minor)';
        }
        if ($expectedImageConfigHash === null) {
            $podCheck->problems[] = 'image.yaml is not valid, the image configuration cannot be compared';
        } elseif ((string)$sandboxRunnerHealth->imageConfigHash !== $expectedImageConfigHash) {
            $podCheck->problems[] = $expectedImageConfigHash === ''
                ? 'the pod runs a project image (configuration ' . substr((string)$sandboxRunnerHealth->imageConfigHash, 0, 12) . ') but the project has no image.yaml'
                : 'image configuration drift: the pod runs ' . (($sandboxRunnerHealth->imageConfigHash ?? '') !== '' ? substr((string)$sandboxRunnerHealth->imageConfigHash, 0, 12) : 'the module image')
                    . ', image.yaml gives ' . substr($expectedImageConfigHash, 0, 12) . ' (build and deploy the image: app:agent-sandbox:build-image)';
        }
        if ($requiresDrive && !$sandboxRunnerHealth->supportsDrive) {
            $podCheck->problems[] = 'the workspace store needs the capability drive, the pod has none';
        }
        return $podCheck;
    }

    /**
     * @return string[] what a session reached that it must not
     */
    protected function probeIsolation(SymfonyStyle $symfonyStyle): array
    {
        /** @var SandboxWorkspacesService $sandboxWorkspacesService */
        $sandboxWorkspacesService = DDDService::instance()->getService(SandboxWorkspacesService::class);
        /** @var SandboxSessionRulesService $sandboxSessionRulesService */
        $sandboxSessionRulesService = DDDService::instance()->getService(SandboxSessionRulesService::class);
        $isAllowList = $sandboxSessionRulesService->getNetworkMode() === SandboxSessionRulesService::NETWORK_MODE_ALLOW_LIST;
        $workspaceKey = static::ISOLATION_PROBE_WORKSPACE_KEY_PREFIX . getmypid();
        $isolationProblems = [];
        try {
            foreach (static::ISOLATION_PROBES as $probeName => $probeCommand) {
                if ($isAllowList && $probeName === 'public DNS') {
                    // with an allow-list the sessions resolve the proxy's name: DNS is open on purpose
                    continue;
                }
                $sandboxCommandRun = $sandboxWorkspacesService->runShellCommand($workspaceKey, SandboxWorkspacesService::CONVERSATION_KEY_WITHOUT_CONVERSATION, $probeCommand, 15, false);
                $exitCode = $sandboxCommandRun->execAnswer['exitCode'] ?? null;
                $symfonyStyle->writeln(sprintf('isolation probe %-22s exit %s %s', $probeName, $exitCode === null ? '?' : (string)$exitCode, $exitCode === 0 ? '(REACHED)' : '(refused)'));
                if ($exitCode === 0) {
                    $isolationProblems[] = 'a session reached ' . $probeName;
                }
            }
        } catch (Throwable $throwable) {
            $isolationProblems[] = 'the probe session failed: ' . $throwable->getMessage();
        } finally {
            try {
                $sandboxWorkspacesService->deleteWorkspace($workspaceKey);
            } catch (Throwable) {
                // the probe workspace holds nothing; a leftover expires
            }
        }
        return $isolationProblems;
    }
}
