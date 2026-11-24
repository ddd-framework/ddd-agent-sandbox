<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Sandbox;

use DDD\Domain\AI\Services\Sandboxes\SandboxDeployRenderService;
use DDD\Domain\AI\Services\Sandboxes\SandboxImageConfigService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Writes the project's Kustomize overlay of the runner pool (deploy/k8s/base of this package plus the project's
 * namespace, image, replicas, limits, callers and, with a host allow-list, the egress proxy) into a folder of the
 * project; Devops applies it with `kubectl apply -k <folder>`.
 */
#[AsCommand(
    name: 'app:agent-sandbox:render-deploy',
    description: 'Writes the Kustomize overlay of the sandbox runner pool (namespace, image tag, replicas, limits, callers, egress proxy) from config/app/AI/Sandbox/runner.php into a project folder',
)]
class SandboxRenderDeploy extends Command
{
    protected function configure(): void
    {
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'The overlay folder, relative to the project root', 'deploy/sandbox');
        $this->addOption('image', null, InputOption::VALUE_REQUIRED, 'The registry path of the project\'s own image (with image.yaml); default the module image');
        $this->addOption('stdout', null, InputOption::VALUE_NONE, 'Print the files instead of writing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        /** @var SandboxDeployRenderService $sandboxDeployRenderService */
        $sandboxDeployRenderService = DDDService::instance()->getService(SandboxDeployRenderService::class);
        /** @var SandboxImageConfigService $sandboxImageConfigService */
        $sandboxImageConfigService = DDDService::instance()->getService(SandboxImageConfigService::class);
        try {
            $overlayFolder = trim((string)$input->getOption('output'), '/');
            $overlayDirectory = DDDService::instance()->getRootDir() . '/' . $overlayFolder;
            $sandboxImageConfig = $sandboxImageConfigService->findImageConfig();
            $imageRepository = $sandboxDeployRenderService->buildImageRepository($sandboxImageConfig, $input->getOption('image'));
            $imageTag = $imageRepository === SandboxImageConfigService::BASE_IMAGE
                ? $sandboxImageConfigService->buildImageTag(null) . ($sandboxImageConfig?->toolkit === 'full' ? '-full' : '')
                : $sandboxImageConfigService->buildImageTag($sandboxImageConfig);
            $overlayFiles = $sandboxDeployRenderService->renderOverlayFiles($overlayDirectory, $imageRepository, $imageTag);
            if ($input->getOption('stdout')) {
                foreach ($overlayFiles as $fileName => $fileContent) {
                    $output->writeln('--- ' . $fileName);
                    $output->write($fileContent);
                }
                return Command::SUCCESS;
            }
            if (!is_dir($overlayDirectory)) {
                mkdir($overlayDirectory, 0755, true);
            }
            foreach ($overlayFiles as $fileName => $fileContent) {
                file_put_contents($overlayDirectory . '/' . $fileName, $fileContent);
            }
            $symfonyStyle->success(sprintf('wrote %s (%s), image %s:%s; apply with kubectl apply -k %s', $overlayFolder, implode(', ', array_keys($overlayFiles)), $imageRepository, $imageTag, $overlayFolder));
            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $symfonyStyle->error($throwable->getMessage());
            return Command::FAILURE;
        }
    }
}
