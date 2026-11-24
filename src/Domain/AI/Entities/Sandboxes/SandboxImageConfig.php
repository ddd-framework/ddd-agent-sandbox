<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * A project's sandbox image as `config/app/AI/Sandbox/image.yaml` declares it: the module image it builds on (base or
 * full), Debian packages, Python and npm packages (exact pins only), fonts, files and environment variables; and the
 * hash of all of it (the configuration and the bytes of every font and file), written into the image and reported by
 * `/healthz` as `imageConfigHash`.
 */
class SandboxImageConfig
{
    public const string TOOLKIT_BASE = 'base';

    public const string TOOLKIT_FULL = 'full';

    /** @var string base or full */
    public string $toolkit = self::TOOLKIT_BASE;

    /** @var string[] Debian package names */
    public array $aptPackages = [];

    /** @var string[] `name==1.2.3` */
    public array $pythonPackages = [];

    /** @var string[] `name@1.2.3` (full toolkit only: the base image has no Node) */
    public array $npmPackages = [];

    /** @var string[] font files, paths relative to the project root */
    public array $fontPaths = [];

    /** @var string[] target path in the image by source path relative to the project root */
    public array $targetPathsBySourcePath = [];

    /** @var string[] value by environment variable name */
    public array $environment = [];

    /** @var string sha256 of the canonical configuration and the bytes of every font and file */
    public string $configHash = '';

    /** The first 12 characters of the hash, the image tag's suffix */
    public function getShortHash(): string
    {
        return substr($this->configHash, 0, 12);
    }
}
