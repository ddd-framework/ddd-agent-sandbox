<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Domain\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerContract;
use DDD\Domain\AI\Services\Sandboxes\SandboxImageConfigService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;

class SandboxImageConfigServiceTest extends TestCase
{
    protected string $projectRootDirectory;

    protected function setUp(): void
    {
        $this->projectRootDirectory = sys_get_temp_dir() . '/sandbox-image-test-' . getmypid() . '-' . random_int(1, 99999);
        mkdir($this->projectRootDirectory . '/config/app/AI/Sandbox', 0777, true);
        mkdir($this->projectRootDirectory . '/resources/Sandbox/fonts', 0777, true);
        mkdir($this->projectRootDirectory . '/resources/Sandbox/bin', 0777, true);
        file_put_contents($this->projectRootDirectory . '/resources/Sandbox/fonts/Brand-Regular.ttf', 'font bytes');
        file_put_contents($this->projectRootDirectory . '/resources/Sandbox/bin/report-tool', "#!/bin/sh\necho ok\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectRootDirectory));
    }

    public function testWithoutImageYamlTheModuleImageIsUsed(): void
    {
        $sandboxImageConfigService = new SandboxImageConfigService();
        self::assertNull($sandboxImageConfigService->findImageConfig($this->projectRootDirectory));
        self::assertSame(SandboxRunnerContract::CONTRACT_VERSION, $sandboxImageConfigService->buildImageTag(null));
    }

    public function testTheConfigurationRendersAPinnedDockerfileAndAStableHash(): void
    {
        file_put_contents($this->projectRootDirectory . '/config/app/AI/Sandbox/image.yaml', <<<'YAML'
base: full
apt: [fonts-firacode]
python: [reportlab==4.4.4]
npm: [pptxgenjs@4.0.1]
fonts: [resources/Sandbox/fonts/*.ttf]
files: {resources/Sandbox/bin/report-tool: /usr/local/bin/report-tool}
env: {REPORT_LOCALE: en_GB}
YAML);
        $sandboxImageConfigService = new SandboxImageConfigService();
        $sandboxImageConfig = $sandboxImageConfigService->findImageConfig($this->projectRootDirectory);
        self::assertSame(['resources/Sandbox/fonts/Brand-Regular.ttf'], $sandboxImageConfig->fontPaths);
        $dockerfile = $sandboxImageConfigService->renderDockerfile($sandboxImageConfig);
        self::assertStringContainsString('FROM ghcr.io/ddd-framework/ddd-agent-sandbox-runner:' . SandboxRunnerContract::CONTRACT_VERSION . '-full', $dockerfile);
        self::assertStringContainsString('pip install --no-cache-dir reportlab==4.4.4', $dockerfile);
        self::assertStringContainsString('COPY files/1 /usr/local/bin/report-tool', $dockerfile);
        self::assertStringContainsString('ENV REPORT_LOCALE="en_GB"', $dockerfile);
        self::assertStringContainsString($sandboxImageConfig->configHash . '" > /etc/sandbox-image-config-hash', $dockerfile);
        self::assertSame($sandboxImageConfig->configHash, $sandboxImageConfigService->findImageConfig($this->projectRootDirectory)->configHash, 'the same input gives the same hash');
        file_put_contents($this->projectRootDirectory . '/resources/Sandbox/fonts/Brand-Regular.ttf', 'other font bytes');
        self::assertNotSame($sandboxImageConfig->configHash, $sandboxImageConfigService->findImageConfig($this->projectRootDirectory)->configHash, 'a changed font changes the hash');
        $contextDirectory = $this->projectRootDirectory . '/context';
        $sandboxImageConfigService->writeBuildContext($sandboxImageConfig, $this->projectRootDirectory, $contextDirectory);
        self::assertFileExists($contextDirectory . '/Dockerfile');
        self::assertFileExists($contextDirectory . '/fonts/Brand-Regular.ttf');
        self::assertFileExists($contextDirectory . '/files/1');
    }

    /**
     * @dataProvider provideInvalidConfigurations
     */
    public function testInvalidConfigurationsAreRefused(string $imageYaml): void
    {
        file_put_contents($this->projectRootDirectory . '/config/app/AI/Sandbox/image.yaml', $imageYaml);
        $this->expectException(BadRequestException::class);
        (new SandboxImageConfigService())->findImageConfig($this->projectRootDirectory);
    }

    /**
     * @return string[][]
     */
    public static function provideInvalidConfigurations(): array
    {
        return [
            'loose python pin' => ['python: [reportlab>=4]'],
            'npm on base' => ['npm: [pptxgenjs@4.0.1]'],
            'unknown key' => ['packages: [x]'],
            'file outside the project' => ['files: {../secret: /x}'],
            'target in the workspace' => ['files: {resources/Sandbox/bin/report-tool: /workspace/x}'],
            'shell in an apt name' => ['apt: ["curl; rm -rf /"]'],
            'unknown base' => ['base: huge'],
        ];
    }
}
