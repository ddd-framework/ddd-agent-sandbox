<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Sandbox;

use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceSyncResult;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Makes the preloaded content equal to the project's preload folder (`AI.Sandbox.workspaces.preloadDirectory`, default
 * `resources/Sandbox/preload`): templates, kits, sample files. Every later claim restores it read-only below
 * `$SANDBOX_PRELOAD` (/workspace/.preload); a `start.sh` at its top runs once at the start of every session. Runs in
 * every deploy; an unchanged folder writes nothing. Symbolic links and files above 50 MB are refused. With the drive
 * store (`AI.Sandbox.workspaces.store` = drive) the folder also becomes the library space every drive workspace mounts
 * below drivespaces/ (only new or changed files are written; the drive keeps the history).
 */
#[AsCommand(
    name: 'app:agent-sandbox:seed',
    description: 'Makes the sandbox preload (read-only below $SANDBOX_PRELOAD in every session; start.sh runs at session start) equal to the project folder AI.Sandbox.workspaces.preloadDirectory; run in every deploy',
)]
class SandboxSeed extends Command
{
    /** @var int The largest preloaded file */
    public const int MAX_PRELOAD_FILE_BYTES = 52428800;

    /** @var int The most preloaded bytes in all */
    public const int MAX_PRELOAD_TOTAL_BYTES = 524288000;

    protected function configure(): void
    {
        $this->addOption('directory', null, InputOption::VALUE_REQUIRED, 'The preload folder (default: AI.Sandbox.workspaces.preloadDirectory, relative to the project root)');
        $this->addOption('dryRun', null, InputOption::VALUE_NONE, 'List what would be preloaded, store nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');
        $symfonyStyle = new SymfonyStyle($input, $output);
        $configuredDirectory = (string)($input->getOption('directory') ?? Config::get('AI.Sandbox.workspaces.preloadDirectory') ?? '');
        if (trim($configuredDirectory) === '') {
            $symfonyStyle->writeln('no preload folder configured (AI.Sandbox.workspaces.preloadDirectory); the preload is emptied');
        }
        $preloadDirectory = $configuredDirectory === '' ? '' : (str_starts_with($configuredDirectory, '/') ? $configuredDirectory : DDDService::instance()->getRootDir() . '/' . $configuredDirectory);
        try {
            $contentsByPath = $preloadDirectory !== '' && is_dir($preloadDirectory) ? $this->readPreloadFiles($preloadDirectory) : [];
            if ($preloadDirectory !== '' && !is_dir($preloadDirectory)) {
                $symfonyStyle->writeln('the preload folder ' . $configuredDirectory . ' does not exist; the preload is emptied');
            }
            $totalBytes = array_sum(array_map('strlen', $contentsByPath));
            /** @var SandboxWorkspacesService $sandboxWorkspacesService */
            $sandboxWorkspacesService = DDDService::instance()->getService(SandboxWorkspacesService::class);
            if ($input->getOption('dryRun')) {
                $symfonyStyle->listing(array_keys($contentsByPath));
                $symfonyStyle->writeln(sprintf('%d files, %s (dry run, nothing stored)', count($contentsByPath), SandboxWorkspaceSyncResult::formatBytes($totalBytes)));
                foreach ($sandboxWorkspacesService->seedPreloadForLiveWorkspaces($contentsByPath, true) as $liveSeedLine) {
                    $symfonyStyle->writeln($liveSeedLine);
                }
                return Command::SUCCESS;
            }
            // the drive store's workspaces mount the preload as a library space (drivespaces/<name>/)
            foreach ($sandboxWorkspacesService->seedPreloadForLiveWorkspaces($contentsByPath, false) as $liveSeedLine) {
                $symfonyStyle->writeln($liveSeedLine);
            }
            $preloadManifest = $sandboxWorkspacesService->seedPreloadContent($contentsByPath);
            $symfonyStyle->success(sprintf(
                'preload: %d files, %s, manifest version %d%s',
                $preloadManifest->countFiles(),
                SandboxWorkspaceSyncResult::formatBytes($preloadManifest->sumBytes()),
                $preloadManifest->version,
                isset($contentsByPath[SandboxWorkspacesService::PRELOAD_START_SCRIPT]) ? ', start.sh runs at session start' : ''
            ));
            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $symfonyStyle->error($throwable->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * @param string $preloadDirectory
     * @return string[] content by path relative to the folder
     */
    protected function readPreloadFiles(string $preloadDirectory): array
    {
        $preloadDirectory = rtrim((string)realpath($preloadDirectory), '/');
        $contentsByPath = [];
        $totalBytes = 0;
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($preloadDirectory, FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $fileInfo) {
            $relativePath = substr($fileInfo->getPathname(), strlen($preloadDirectory) + 1);
            if ($fileInfo->isLink()) {
                throw new \RuntimeException('The preload folder holds a symbolic link, which is not preloaded: ' . $relativePath);
            }
            if (!$fileInfo->isFile() || SandboxWorkspacesService::isExcludedPath($relativePath) || basename($relativePath) === '.DS_Store') {
                continue;
            }
            if ($fileInfo->getSize() > static::MAX_PRELOAD_FILE_BYTES) {
                throw new \RuntimeException('A preload file is larger than 50 MB: ' . $relativePath);
            }
            $totalBytes += $fileInfo->getSize();
            if ($totalBytes > static::MAX_PRELOAD_TOTAL_BYTES) {
                throw new \RuntimeException('The preload folder holds more than 500 MB');
            }
            $contentsByPath[$relativePath] = (string)file_get_contents($fileInfo->getPathname());
        }
        ksort($contentsByPath);
        return $contentsByPath;
    }
}
