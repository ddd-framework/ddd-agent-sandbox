<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Sandbox;

use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceSyncResult;
use DDD\Domain\AI\Services\Sandboxes\SandboxRunnerClientService;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The sandbox sessions: list them, release one or every idle one (saved first), and the worker loops.
 *
 *   php bin/console app:agent-sandbox:sessions                      (list)
 *   php bin/console app:agent-sandbox:sessions --sweep              (worker loop, every minute)
 *   php bin/console app:agent-sandbox:sessions --expire [--dryRun]  (worker loop, once a day)
 *   php bin/console app:agent-sandbox:sessions --offload            (before a runner rollout: save and release all)
 *   php bin/console app:agent-sandbox:sessions --flush=space-12     (the flush process a call starts)
 *
 * --expire deletes the saved files whose lifetime is over (SandboxWorkspaceZones: work/ and the root 7 days after the
 * last activity, out/ and inbox/ 30 days, lib/ and NOTES.md never) and, over the stored quota, the oldest scratch.
 *
 * --bench runs shell `true`, a file write and a read + write N times each in the throwaway workspace `bench-<pid>` and
 * prints the phase milliseconds per call, then deletes the workspace.
 */
#[AsCommand(
    name: 'app:agent-sandbox:sessions',
    description: 'List the sandbox sessions; release one or every idle one (saved first); --sweep and --expire are the worker loops, --offload saves and releases every session before a runner rollout',
)]
class SandboxSessions extends Command
{
    protected function configure(): void
    {
        $this->addOption('release', null, InputOption::VALUE_REQUIRED, 'Save and release the session of this workspace key');
        $this->addOption('release-idle', null, InputOption::VALUE_NONE, 'Save and release every session idle for longer than AI.Sandbox.workspaces.idleReleaseSeconds');
        $this->addOption('sweep', null, InputOption::VALUE_NONE, 'The worker loop: save overdue unsaved changes, then save and release idle sessions; nothing while the sandbox is not enabled');
        $this->addOption('offload', null, InputOption::VALUE_NONE, 'Save and release every session (before a runner rollout)');
        $this->addOption('flush', null, InputOption::VALUE_REQUIRED, 'Save the unsaved changes of this workspace key to the store (the flush process a call starts)');
        $this->addOption('expire', null, InputOption::VALUE_NONE, 'Delete the saved files whose lifetime is over, and the oldest scratch over the quota');
        $this->addOption('dryRun', null, InputOption::VALUE_NONE, 'With --expire: list what would go, delete nothing');
        $this->addOption('bench', null, InputOption::VALUE_REQUIRED, 'Time N calls each of shell true, a file write and an edit in a throwaway workspace');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json (the list)', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        $symfonyStyle = new SymfonyStyle($input, $output);
        /** @var SandboxWorkspacesService $sandboxWorkspacesService */
        $sandboxWorkspacesService = DDDService::instance()->getService(SandboxWorkspacesService::class);
        /** @var SandboxRunnerClientService $sandboxRunnerClientService */
        $sandboxRunnerClientService = DDDService::instance()->getService(SandboxRunnerClientService::class);
        try {
            $workspaceKeyToFlush = trim((string)$input->getOption('flush'));
            if ($workspaceKeyToFlush !== '') {
                $rounds = $sandboxWorkspacesService->flushPendingChanges($workspaceKeyToFlush);
                $symfonyStyle->writeln(sprintf('flushed %s (%d save rounds)', $workspaceKeyToFlush, $rounds));
                return Command::SUCCESS;
            }
            if (($input->getOption('sweep') || $input->getOption('expire')) && !$sandboxRunnerClientService->isSandboxEnabled()) {
                $symfonyStyle->writeln('the sandbox is not enabled here, nothing to do');
                return Command::SUCCESS;
            }
            $workspaceKeyToRelease = trim((string)$input->getOption('release'));
            if ($workspaceKeyToRelease !== '') {
                $released = $sandboxWorkspacesService->releaseSession($workspaceKeyToRelease);
                $symfonyStyle->writeln($released ? "released $workspaceKeyToRelease (saved first)" : "$workspaceKeyToRelease has no session");
            }
            if ($input->getOption('release-idle')) {
                $releasedWorkspaceKeys = $sandboxWorkspacesService->releaseIdleSessions();
                $symfonyStyle->writeln('released idle: ' . ($releasedWorkspaceKeys === [] ? 'none' : implode(', ', $releasedWorkspaceKeys)));
            }
            if ($input->getOption('offload')) {
                $offloadedWorkspaceKeys = $sandboxWorkspacesService->offloadAllSessions();
                $symfonyStyle->writeln('offloaded: ' . ($offloadedWorkspaceKeys === [] ? 'none' : implode(', ', $offloadedWorkspaceKeys)));
                return Command::SUCCESS;
            }
            if ($input->getOption('sweep')) {
                $sandboxSweepResult = $sandboxWorkspacesService->sweepWorkspaces();
                $symfonyStyle->writeln(sprintf(
                    'sweep: flushed %s · released %s',
                    $sandboxSweepResult->flushedWorkspaceKeys === [] ? 'none' : implode(', ', $sandboxSweepResult->flushedWorkspaceKeys),
                    $sandboxSweepResult->releasedWorkspaceKeys === [] ? 'none' : implode(', ', $sandboxSweepResult->releasedWorkspaceKeys)
                ));
                return Command::SUCCESS;
            }
            if ($input->getOption('expire')) {
                $this->printExpiry($symfonyStyle, $sandboxWorkspacesService, (bool)$input->getOption('dryRun'));
                return Command::SUCCESS;
            }
            $benchCalls = (int)$input->getOption('bench');
            if ($benchCalls > 0) {
                $this->runBench($symfonyStyle, $sandboxWorkspacesService, $benchCalls);
                return Command::SUCCESS;
            }
            $this->printSessions($symfonyStyle, $output, $sandboxWorkspacesService, (string)$input->getOption('format') === 'json');
            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $symfonyStyle->error($throwable->getMessage());
            return Command::FAILURE;
        }
    }

    protected function printSessions(SymfonyStyle $symfonyStyle, OutputInterface $output, SandboxWorkspacesService $sandboxWorkspacesService, bool $asJson): void
    {
        $sessionRows = [];
        foreach ($sandboxWorkspacesService->findSessions() as $sandboxSession) {
            $sessionRows[] = [
                'workspaceKey' => $sandboxSession->workspaceKey,
                'pool' => $sandboxSession->poolName,
                'runner' => $sandboxSession->runnerAddress,
                'sessionId' => $sandboxSession->sessionId,
                'claimedAt' => gmdate('Y-m-d H:i:s', $sandboxSession->claimedAt) . ' UTC',
                'idleSeconds' => time() - $sandboxSession->lastUsedAt,
                'conversations' => count($sandboxSession->shellNamesByConversation),
            ];
        }
        if ($asJson) {
            $output->writeln((string)json_encode(['sessions' => $sessionRows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $symfonyStyle->table(['workspace', 'pool', 'runner', 'session', 'claimed', 'idle s', 'conversations'], array_map('array_values', $sessionRows));
    }

    protected function printExpiry(SymfonyStyle $symfonyStyle, SandboxWorkspacesService $sandboxWorkspacesService, bool $dryRun): void
    {
        $tableRows = [];
        foreach ($sandboxWorkspacesService->expireWorkspaces($dryRun)->getElements() as $expiryReport) {
            $tableRows[] = [
                $expiryReport->workspaceKey,
                $expiryReport->skippedReason ?? ($dryRun ? 'would remove' : 'removed'),
                (string)count($expiryReport->expiredPaths),
                (string)count($expiryReport->pressurePaths),
                SandboxWorkspaceSyncResult::formatBytes($expiryReport->freedBytes),
                (string)$expiryReport->keptFiles,
                SandboxWorkspaceSyncResult::formatBytes($expiryReport->libBytes),
                implode(', ', array_slice(array_merge($expiryReport->expiredPaths, $expiryReport->pressurePaths), 0, 5)),
            ];
        }
        $symfonyStyle->table(['workspace', 'state', 'expired', 'quota pressure', 'freed', 'kept files', 'lib/', 'first paths'], $tableRows);
    }

    protected function runBench(SymfonyStyle $symfonyStyle, SandboxWorkspacesService $sandboxWorkspacesService, int $benchCalls): void
    {
        $workspaceKey = 'bench-' . getmypid();
        $conversationKey = 'conversation-bench';
        // ten files, so the first call measures a claim that restores ten contents
        $benchFiles = ['list.txt' => "line 1\nline 2\nline 3\n"];
        for ($fileIndex = 1; $fileIndex <= 9; $fileIndex++) {
            $benchFiles['data/file-' . $fileIndex . '.csv'] = str_repeat("id;name;value\n{$fileIndex};row;" . random_int(1, 999) . "\n", 100);
        }
        $sandboxWorkspacesService->createWorkspaceFromFiles($workspaceKey, $benchFiles);
        $tableRows = [];
        $totalsByTool = [];
        try {
            foreach (['claim' => 1, 'shell' => $benchCalls, 'writeFile' => $benchCalls, 'editFile' => $benchCalls] as $toolName => $callCount) {
                for ($callIndex = 1; $callIndex <= $callCount; $callIndex++) {
                    $sandboxWorkspacesService->startCallTimings();
                    $startedAt = hrtime(true);
                    $this->runBenchCall($sandboxWorkspacesService, $workspaceKey, $conversationKey, $toolName, $callIndex);
                    $totalMilliseconds = (int)((hrtime(true) - $startedAt) / 1_000_000);
                    $totalsByTool[$toolName][] = $totalMilliseconds;
                    $phaseParts = [];
                    foreach ($sandboxWorkspacesService->callTimings as $phase => $milliseconds) {
                        $phaseParts[] = "$phase=$milliseconds";
                    }
                    $tableRows[] = [$toolName, (string)$callIndex, (string)$totalMilliseconds, implode(' ', $phaseParts)];
                }
            }
            $flushWaitStartedAt = hrtime(true);
            while ($sandboxWorkspacesService->findDirtyMark($workspaceKey) !== null && (hrtime(true) - $flushWaitStartedAt) < 60_000_000_000) {
                usleep(100_000);
            }
            $flushWaitMilliseconds = (int)((hrtime(true) - $flushWaitStartedAt) / 1_000_000);
            $savedManifest = $sandboxWorkspacesService->getSavedManifestFromStore($workspaceKey);
            $currentManifest = $sandboxWorkspacesService->findCurrentManifest($workspaceKey);
            $storeMatches = $savedManifest->countFiles() === $currentManifest->countFiles();
            foreach ($currentManifest->files as $path => $currentFile) {
                $storeMatches = $storeMatches && $savedManifest->files->findByPath((string)$path)?->sha256 === $currentFile->sha256;
            }
        } finally {
            $sandboxWorkspacesService->deleteWorkspace($workspaceKey);
        }
        $symfonyStyle->table(['tool', '#', 'totalMs', 'phases (ms)'], $tableRows);
        $symfonyStyle->writeln(sprintf(
            'background flush: the store was current %d ms after the last call; store %s the runner (%d files)',
            $flushWaitMilliseconds,
            $storeMatches ? 'matches' : 'DIFFERS FROM',
            $currentManifest->countFiles()
        ));
        foreach ($totalsByTool as $toolName => $totals) {
            sort($totals);
            $symfonyStyle->writeln(sprintf('%-9s n=%d median=%d ms max=%d ms', $toolName, count($totals), $totals[intdiv(count($totals), 2)], max($totals)));
        }
    }

    protected function runBenchCall(SandboxWorkspacesService $sandboxWorkspacesService, string $workspaceKey, string $conversationKey, string $toolName, int $callIndex): void
    {
        if ($toolName === 'claim' || $toolName === 'shell') {
            $sandboxWorkspacesService->runShellCommand($workspaceKey, $conversationKey, 'true', 10, false);
            return;
        }
        if ($toolName === 'writeFile') {
            $sandboxWorkspacesService->writeWorkspaceFileBytes($workspaceKey, 'note.txt', "note $callIndex\n");
            return;
        }
        $bytes = (string)$sandboxWorkspacesService->readWorkspaceFileBytes($workspaceKey, 'list.txt');
        $sandboxWorkspacesService->writeWorkspaceFileBytes($workspaceKey, 'list.txt', (string)preg_replace('/^line 3.*$/m', "line 3 state $callIndex", $bytes));
    }
}
