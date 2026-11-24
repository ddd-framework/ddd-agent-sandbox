<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxImageConfig;
use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerContract;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\ContainedPath;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the project's `config/app/AI/Sandbox/image.yaml` and renders the build context of the project's runner image:
 * a Dockerfile `FROM ghcr.io/ddd-framework/ddd-agent-sandbox-runner:<module version>[-full]` that adds the declared
 * packages, fonts, files and environment, and writes the configuration hash into `/etc/sandbox-image-config-hash`
 * (reported by `/healthz`; `app:agent-sandbox:check-runner` compares it). A project without image.yaml runs the module's
 * image as it is (hash empty).
 *
 *     base: full                      # base (default) or full
 *     apt: [fonts-firacode]
 *     python: [reportlab==4.4.4]      # exact pins only
 *     npm: [pptxgenjs@4.0.1]          # full only
 *     fonts: [resources/Sandbox/fonts/*.ttf]
 *     files: {resources/Sandbox/bin/report-tool: /usr/local/bin/report-tool}
 *     env: {REPORT_LOCALE: en_GB}
 */
class SandboxImageConfigService extends Service
{
    /** @var string The image of the module the project's image builds on */
    public const string BASE_IMAGE = 'ghcr.io/ddd-framework/ddd-agent-sandbox-runner';

    /** @var string The configuration file relative to the project root */
    public const string IMAGE_CONFIG_PATH = 'config/app/AI/Sandbox/image.yaml';

    /** @var string[] The keys image.yaml may hold */
    public const array KNOWN_KEYS = ['base', 'apt', 'python', 'npm', 'fonts', 'files', 'env'];

    public const string APT_PACKAGE_PATTERN = '/^[a-z0-9][a-z0-9.+-]{0,99}$/';

    public const string PYTHON_PIN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}(\[[A-Za-z0-9,._-]+\])?==[0-9][A-Za-z0-9.+!-]{0,49}$/';

    public const string NPM_PIN_PATTERN = '/^(@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]{0,99}@[0-9]+\.[0-9]+\.[0-9]+[A-Za-z0-9.+-]*$/';

    public const string ENV_NAME_PATTERN = '/^[A-Z_][A-Z0-9_]{0,99}$/';

    /**
     * The project's image configuration; null when the project has no image.yaml.
     *
     * @param string|null $projectRootDirectory null = the application's root
     * @return SandboxImageConfig|null
     * @throws BadRequestException when image.yaml is not valid
     */
    public function findImageConfig(?string $projectRootDirectory = null): ?SandboxImageConfig
    {
        $projectRootDirectory ??= DDDService::instance()->getRootDir();
        $configFilePath = rtrim($projectRootDirectory, '/') . '/' . static::IMAGE_CONFIG_PATH;
        if (!is_file($configFilePath)) {
            return null;
        }
        $parsedConfig = Yaml::parseFile($configFilePath);
        if ($parsedConfig === null) {
            $parsedConfig = [];
        }
        if (!is_array($parsedConfig)) {
            throw new BadRequestException(static::IMAGE_CONFIG_PATH . ' holds no mapping');
        }
        return $this->buildImageConfig($parsedConfig, $projectRootDirectory);
    }

    /**
     * @param array<string, mixed> $parsedConfig
     * @param string $projectRootDirectory
     * @return SandboxImageConfig
     * @throws BadRequestException
     */
    public function buildImageConfig(array $parsedConfig, string $projectRootDirectory): SandboxImageConfig
    {
        $unknownKeys = array_diff(array_map('strval', array_keys($parsedConfig)), static::KNOWN_KEYS);
        if ($unknownKeys !== []) {
            throw new BadRequestException('image.yaml: unknown keys ' . implode(', ', $unknownKeys) . ' (known: ' . implode(', ', static::KNOWN_KEYS) . ')');
        }
        $sandboxImageConfig = new SandboxImageConfig();
        $toolkit = (string)($parsedConfig['base'] ?? SandboxImageConfig::TOOLKIT_BASE);
        if (!in_array($toolkit, [SandboxImageConfig::TOOLKIT_BASE, SandboxImageConfig::TOOLKIT_FULL], true)) {
            throw new BadRequestException('image.yaml: base is base or full, not ' . $toolkit);
        }
        $sandboxImageConfig->toolkit = $toolkit;
        $sandboxImageConfig->aptPackages = $this->readPatternList($parsedConfig, 'apt', static::APT_PACKAGE_PATTERN, 'a Debian package name');
        $sandboxImageConfig->pythonPackages = $this->readPatternList($parsedConfig, 'python', static::PYTHON_PIN_PATTERN, 'an exact pin name==1.2.3');
        $sandboxImageConfig->npmPackages = $this->readPatternList($parsedConfig, 'npm', static::NPM_PIN_PATTERN, 'an exact pin name@1.2.3');
        if ($sandboxImageConfig->npmPackages !== [] && $toolkit !== SandboxImageConfig::TOOLKIT_FULL) {
            throw new BadRequestException('image.yaml: npm packages need base: full (the base image has no Node)');
        }
        $sandboxImageConfig->fontPaths = $this->resolveFontPaths($parsedConfig['fonts'] ?? [], $projectRootDirectory);
        $sandboxImageConfig->targetPathsBySourcePath = $this->readFiles($parsedConfig['files'] ?? [], $projectRootDirectory);
        $sandboxImageConfig->environment = $this->readEnvironment($parsedConfig['env'] ?? []);
        $sandboxImageConfig->configHash = $this->computeConfigHash($sandboxImageConfig, $projectRootDirectory);
        return $sandboxImageConfig;
    }

    /**
     * The image tag of the project's image: `<module version>-<short hash>` (`<version>` alone without image.yaml).
     */
    public function buildImageTag(?SandboxImageConfig $sandboxImageConfig): string
    {
        return SandboxRunnerContract::CONTRACT_VERSION . ($sandboxImageConfig !== null ? '-' . $sandboxImageConfig->getShortHash() : '');
    }

    /**
     * The Dockerfile of the project's image; its context holds `fonts/<n>` and `files/<n>` as {@see writeBuildContext()} writes them.
     */
    public function renderDockerfile(SandboxImageConfig $sandboxImageConfig): string
    {
        $baseTag = SandboxRunnerContract::CONTRACT_VERSION . ($sandboxImageConfig->toolkit === SandboxImageConfig::TOOLKIT_FULL ? '-full' : '');
        $lines = [
            '# Rendered by app:agent-sandbox:build-image from ' . static::IMAGE_CONFIG_PATH . ' — do not edit, change image.yaml',
            'FROM ' . static::BASE_IMAGE . ':' . $baseTag,
            'USER 0:0',
        ];
        if ($sandboxImageConfig->aptPackages !== []) {
            $lines[] = 'RUN apt-get update && apt-get install -y --no-install-recommends ' . implode(' ', $sandboxImageConfig->aptPackages)
                . ' && rm -rf /var/lib/apt/lists/*';
        }
        if ($sandboxImageConfig->pythonPackages !== []) {
            // the module image ships without pip (no package manager at run time): installed for this layer only
            $lines[] = 'RUN python -m ensurepip && python -m pip install --no-cache-dir ' . implode(' ', $sandboxImageConfig->pythonPackages)
                . ' && python -m pip uninstall -y pip';
        }
        if ($sandboxImageConfig->npmPackages !== []) {
            $lines[] = 'RUN npm install -g --no-fund --no-audit ' . implode(' ', $sandboxImageConfig->npmPackages) . ' && npm cache clean --force';
        }
        if ($sandboxImageConfig->fontPaths !== []) {
            $lines[] = 'COPY fonts/ /usr/local/share/fonts/project/';
            $lines[] = 'RUN fc-cache -f';
        }
        $fileIndex = 0;
        foreach ($sandboxImageConfig->targetPathsBySourcePath as $targetPath) {
            $fileIndex++;
            $lines[] = 'COPY files/' . $fileIndex . ' ' . $targetPath;
        }
        foreach ($sandboxImageConfig->environment as $environmentName => $environmentValue) {
            $lines[] = 'ENV ' . $environmentName . '=' . json_encode($environmentValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $lines[] = 'RUN echo "' . $sandboxImageConfig->configHash . '" > /etc/sandbox-image-config-hash';
        return implode("\n", $lines) . "\n";
    }

    /**
     * Writes the build context (Dockerfile, fonts/, files/) into an empty or new directory.
     *
     * @param SandboxImageConfig $sandboxImageConfig
     * @param string $projectRootDirectory
     * @param string $contextDirectory
     * @return void
     * @throws BadRequestException when the directory is not empty
     */
    public function writeBuildContext(SandboxImageConfig $sandboxImageConfig, string $projectRootDirectory, string $contextDirectory): void
    {
        if (is_dir($contextDirectory) && array_diff((array)scandir($contextDirectory), ['.', '..']) !== []) {
            throw new BadRequestException('The build context directory is not empty: ' . $contextDirectory);
        }
        @mkdir($contextDirectory . '/fonts', 0755, true);
        @mkdir($contextDirectory . '/files', 0755, true);
        file_put_contents($contextDirectory . '/Dockerfile', $this->renderDockerfile($sandboxImageConfig));
        foreach ($sandboxImageConfig->fontPaths as $fontPath) {
            copy(rtrim($projectRootDirectory, '/') . '/' . $fontPath, $contextDirectory . '/fonts/' . basename($fontPath));
        }
        $fileIndex = 0;
        foreach (array_keys($sandboxImageConfig->targetPathsBySourcePath) as $sourcePath) {
            $fileIndex++;
            copy(rtrim($projectRootDirectory, '/') . '/' . $sourcePath, $contextDirectory . '/files/' . $fileIndex);
        }
    }

    /**
     * @param array<string, mixed> $parsedConfig
     * @param string $key
     * @param string $pattern
     * @param string $expectation
     * @return string[]
     * @throws BadRequestException
     */
    protected function readPatternList(array $parsedConfig, string $key, string $pattern, string $expectation): array
    {
        $values = $parsedConfig[$key] ?? [];
        if (!is_array($values) || !array_is_list($values)) {
            throw new BadRequestException('image.yaml: ' . $key . ' is a list');
        }
        $validValues = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                throw new BadRequestException('image.yaml: ' . $key . ' entry ' . json_encode($value) . ' is no ' . $expectation);
            }
            $validValues[] = $value;
        }
        return $validValues;
    }

    /**
     * @param mixed $fontPatterns
     * @param string $projectRootDirectory
     * @return string[] font paths relative to the root, sorted
     * @throws BadRequestException
     */
    protected function resolveFontPaths(mixed $fontPatterns, string $projectRootDirectory): array
    {
        if (!is_array($fontPatterns) || !array_is_list($fontPatterns)) {
            throw new BadRequestException('image.yaml: fonts is a list of paths or globs');
        }
        $fontPaths = [];
        foreach ($fontPatterns as $fontPattern) {
            if (!is_string($fontPattern) || !ContainedPath::isSafeRelativePath(str_replace(['*', '?'], 'x', $fontPattern))) {
                throw new BadRequestException('image.yaml: font ' . json_encode($fontPattern) . ' is no path inside the project');
            }
            $matchedPaths = glob(rtrim($projectRootDirectory, '/') . '/' . $fontPattern) ?: [];
            if ($matchedPaths === []) {
                throw new BadRequestException('image.yaml: font ' . $fontPattern . ' matches no file');
            }
            foreach ($matchedPaths as $matchedPath) {
                if (is_file($matchedPath) && preg_match('/\.(ttf|otf|ttc|woff2?)$/i', $matchedPath) === 1) {
                    $fontPaths[] = substr($matchedPath, strlen(rtrim($projectRootDirectory, '/')) + 1);
                }
            }
        }
        $fontPaths = array_values(array_unique($fontPaths));
        sort($fontPaths);
        return $fontPaths;
    }

    /**
     * @param mixed $files
     * @param string $projectRootDirectory
     * @return string[] target path by source path
     * @throws BadRequestException
     */
    protected function readFiles(mixed $files, string $projectRootDirectory): array
    {
        if (!is_array($files)) {
            throw new BadRequestException('image.yaml: files maps a project path to an absolute path in the image');
        }
        $targetPathsBySourcePath = [];
        foreach ($files as $sourcePath => $targetPath) {
            $sourcePath = (string)$sourcePath;
            if (!ContainedPath::isSafeRelativePath($sourcePath) || !is_file(rtrim($projectRootDirectory, '/') . '/' . $sourcePath)) {
                throw new BadRequestException('image.yaml: file ' . $sourcePath . ' is no file inside the project');
            }
            if (!is_string($targetPath) || preg_match('#^/[A-Za-z0-9._/-]+$#', $targetPath) !== 1 || str_contains($targetPath, '/../') || str_starts_with($targetPath, '/workspace')) {
                throw new BadRequestException('image.yaml: target ' . json_encode($targetPath) . ' is no absolute path in the image (outside /workspace)');
            }
            $targetPathsBySourcePath[$sourcePath] = $targetPath;
        }
        ksort($targetPathsBySourcePath);
        return $targetPathsBySourcePath;
    }

    /**
     * @param mixed $environment
     * @return string[] value by name
     * @throws BadRequestException
     */
    protected function readEnvironment(mixed $environment): array
    {
        if (!is_array($environment)) {
            throw new BadRequestException('image.yaml: env maps names to values');
        }
        $validEnvironment = [];
        foreach ($environment as $environmentName => $environmentValue) {
            if (preg_match(static::ENV_NAME_PATTERN, (string)$environmentName) !== 1 || !is_scalar($environmentValue)) {
                throw new BadRequestException('image.yaml: env ' . $environmentName . ' is no NAME: value');
            }
            $validEnvironment[(string)$environmentName] = (string)$environmentValue;
        }
        ksort($validEnvironment);
        return $validEnvironment;
    }

    protected function computeConfigHash(SandboxImageConfig $sandboxImageConfig, string $projectRootDirectory): string
    {
        $fileHashes = [];
        foreach (array_merge($sandboxImageConfig->fontPaths, array_keys($sandboxImageConfig->targetPathsBySourcePath)) as $relativePath) {
            $fileHashes[$relativePath] = hash_file('sha256', rtrim($projectRootDirectory, '/') . '/' . $relativePath);
        }
        ksort($fileHashes);
        $canonicalConfig = [
            'moduleVersion' => SandboxRunnerContract::CONTRACT_VERSION,
            'toolkit' => $sandboxImageConfig->toolkit,
            'apt' => $sandboxImageConfig->aptPackages,
            'python' => $sandboxImageConfig->pythonPackages,
            'npm' => $sandboxImageConfig->npmPackages,
            'files' => $sandboxImageConfig->targetPathsBySourcePath,
            'env' => $sandboxImageConfig->environment,
            'contents' => $fileHashes,
        ];
        return hash('sha256', (string)json_encode($canonicalConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
