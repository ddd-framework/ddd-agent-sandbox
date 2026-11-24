<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Sandbox\Agents\Drive;

use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceSyncResult;
use DDD\Domain\AI\Services\Sandboxes\Agents\Drive\SandboxDriveMigrationService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Moves the workspaces of agent spaces (`space-<id>`) from the manifest store into each space's own drive, before or
 * after switching `AI.Sandbox.workspaces.store` to drive. Idempotent; the manifest store is left as it is. Run it with
 * `--dryRun` first.
 */
#[AsCommand(
    name: 'app:agent-sandbox:migrate-to-drive',
    description: 'Moves the manifest-store workspaces of agent spaces (space-<id>) into each space\'s own drive (stage, upload, commit with origin MIGRATED; idempotent; --dryRun counts only)',
)]
class SandboxMigrateToDrive extends Command
{
    protected function configure(): void
    {
        $this->addOption('workspaceKey', null, InputOption::VALUE_REQUIRED, 'Only this workspace key (default: every key of the manifest store)');
        $this->addOption('dryRun', null, InputOption::VALUE_NONE, 'Count what would move, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(3600);
        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        if ($defaultAccount) {
            AuthService::instance()->setAccount($defaultAccount);
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        $onlyWorkspaceKey = $input->getOption('workspaceKey');
        try {
            /** @var SandboxDriveMigrationService $sandboxDriveMigrationService */
            $sandboxDriveMigrationService = DDDService::instance()->getService(SandboxDriveMigrationService::class);
            $sandboxDriveMigrationReports = $sandboxDriveMigrationService->migrateManifestWorkspaces($onlyWorkspaceKey !== null ? (string)$onlyWorkspaceKey : null, (bool)$input->getOption('dryRun'));
        } catch (Throwable $throwable) {
            $symfonyStyle->error($throwable->getMessage());
            return Command::FAILURE;
        }
        $table = new Table($output);
        $table->setHeaders(['workspace', 'drive', 'files', 'size', 'staged', 'uploaded', 'known', 'unchanged', 'committed', 'conflicts', 'state']);
        $hasConflicts = false;
        foreach ($sandboxDriveMigrationReports->getElements() as $sandboxDriveMigrationReport) {
            $hasConflicts = $hasConflicts || $sandboxDriveMigrationReport->conflictCount > 0;
            $table->addRow([
                $sandboxDriveMigrationReport->workspaceKey,
                $sandboxDriveMigrationReport->driveSpaceId ?? '-',
                $sandboxDriveMigrationReport->fileCount,
                SandboxWorkspaceSyncResult::formatBytes($sandboxDriveMigrationReport->byteCount),
                $sandboxDriveMigrationReport->stagedCount,
                $sandboxDriveMigrationReport->uploadedCount,
                $sandboxDriveMigrationReport->knownCount,
                $sandboxDriveMigrationReport->unchangedCount,
                $sandboxDriveMigrationReport->committedCount,
                $sandboxDriveMigrationReport->conflictCount,
                $sandboxDriveMigrationReport->state,
            ]);
        }
        $table->render();
        if ($hasConflicts) {
            $symfonyStyle->warning('Some files conflicted with the drive; they stay staged in the migration-<key> working copy.');
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
