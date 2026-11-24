<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Sandbox;

use DDD\Domain\AI\Services\Sandboxes\SandboxImageConfigService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The project's runner image from `config/app/AI/Sandbox/image.yaml`: writes the build context (Dockerfile `FROM` the
 * module's image of the installed version, fonts/, files/) and prints the tag `<module version>-<config hash>`; with
 * `--build` it runs `docker buildx build` on it (the host's CI, beside its app image). No hand-written Dockerfile and
 * no installs in a session: image.yaml is the one place.
 */
#[AsCommand(
    name: 'app:agent-sandbox:build-image',
    description: 'Writes the build context of the project\'s sandbox runner image from config/app/AI/Sandbox/image.yaml (FROM the module image, packages, fonts, files, configuration hash) and prints its tag; --build runs docker buildx build',
)]
class SandboxBuildImage extends Command
{
    protected function configure(): void
    {
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'An empty or new folder for the build context', sys_get_temp_dir() . '/sandbox-image-' . getmypid());
        $this->addOption('image', null, InputOption::VALUE_REQUIRED, 'The registry path of the project\'s image (e.g. ghcr.io/<org>/<project>-sandbox-runner)');
        $this->addOption('build', null, InputOption::VALUE_NONE, 'Run docker buildx build on the context (needs --image)');
        $this->addOption('push', null, InputOption::VALUE_NONE, 'With --build: push the image');
        $this->addOption('platform', null, InputOption::VALUE_REQUIRED, 'With --build: the platform', 'linux/amd64');
        $this->addOption('printTag', null, InputOption::VALUE_NONE, 'Print only the tag (for CI)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        /** @var SandboxImageConfigService $sandboxImageConfigService */
        $sandboxImageConfigService = DDDService::instance()->getService(SandboxImageConfigService::class);
        try {
            $projectRootDirectory = DDDService::instance()->getRootDir();
            $sandboxImageConfig = $sandboxImageConfigService->findImageConfig($projectRootDirectory);
            if ($sandboxImageConfig === null) {
                $symfonyStyle->writeln('no ' . SandboxImageConfigService::IMAGE_CONFIG_PATH . ': the project runs the module image ' . SandboxImageConfigService::BASE_IMAGE . ':' . $sandboxImageConfigService->buildImageTag(null));
                return Command::SUCCESS;
            }
            $imageTag = $sandboxImageConfigService->buildImageTag($sandboxImageConfig);
            if ($input->getOption('printTag')) {
                $output->writeln($imageTag);
                return Command::SUCCESS;
            }
            $contextDirectory = (string)$input->getOption('output');
            $sandboxImageConfigService->writeBuildContext($sandboxImageConfig, $projectRootDirectory, $contextDirectory);
            $symfonyStyle->writeln(sprintf('build context in %s, tag %s, configuration hash %s', $contextDirectory, $imageTag, $sandboxImageConfig->configHash));
            if (!$input->getOption('build')) {
                return Command::SUCCESS;
            }
            $imageRepository = trim((string)$input->getOption('image'));
            if ($imageRepository === '' || preg_match('#^[a-z0-9.-]+(:[0-9]+)?(/[a-z0-9._-]+)+$#', $imageRepository) !== 1) {
                $symfonyStyle->error('--build needs --image=<registry path of the project\'s image>');
                return Command::FAILURE;
            }
            $buildArguments = ['docker', 'buildx', 'build', '--platform', (string)$input->getOption('platform'), '--tag', $imageRepository . ':' . $imageTag];
            $buildArguments[] = $input->getOption('push') ? '--push' : '--load';
            $buildArguments[] = $contextDirectory;
            $buildProcess = new Process($buildArguments, null, null, null, 3600);
            $buildProcess->run(static function (string $outputType, string $outputText) use ($output): void {
                $output->write($outputText);
            });
            if (!$buildProcess->isSuccessful()) {
                $symfonyStyle->error('docker buildx build failed (exit ' . $buildProcess->getExitCode() . ')');
                return Command::FAILURE;
            }
            $symfonyStyle->success('built ' . $imageRepository . ':' . $imageTag);
            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $symfonyStyle->error($throwable->getMessage());
            return Command::FAILURE;
        }
    }
}
