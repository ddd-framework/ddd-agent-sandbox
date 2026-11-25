<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * The HTTP contract between this package and the sandbox runner (image `ghcr.io/ddd-framework/ddd-agent-sandbox-runner`,
 * source `runner/` of the module's repository). The runner reports its contract version in `GET /healthz`; it is the
 * runner's package version, released with the same tag as this package.
 *
 * Compatibility: the same major and at least {@see MINIMUM_CONTRACT_VERSION}; while the major is 0, also the same minor
 * (a 0.x minor may break the contract). Deploy the runner first, then the PHP release that reads a new field.
 */
class SandboxRunnerContract
{
    /** @var string The contract this package release speaks (= the package version without a prerelease suffix) */
    public const string CONTRACT_VERSION = '0.2.5';

    /** @var string The lowest runner contract this package works with */
    public const string MINIMUM_CONTRACT_VERSION = '0.2.0';

    public const string ROUTE_HEALTH = '/healthz';

    /** @var string Capability: the runner speaks the drive protocol (sessions on a drive working copy) */
    public const string CAPABILITY_DRIVE = 'drive';

    /** @var string Capability: /workspace can be a FUSE mount (lazy file loading); without it files are copied */
    public const string CAPABILITY_FUSE = 'fuse';

    /** @var string Capability: object store transfers run through the transfer proxy */
    public const string CAPABILITY_TRANSFER_PROXY = 'transferProxy';

    /**
     * Whether a runner that reports `$runnerContractVersion` serves this package: the same major, at least the minimum,
     * and while the major is 0 the same minor as the minimum.
     *
     * @param string|null $runnerContractVersion
     * @return bool
     */
    public static function isCompatibleContractVersion(?string $runnerContractVersion): bool
    {
        $runnerCoreVersion = static::extractCoreVersion($runnerContractVersion);
        if ($runnerCoreVersion === null) {
            return false;
        }
        [$runnerMajor, $runnerMinor] = array_map('intval', explode('.', $runnerCoreVersion));
        [$minimumMajor, $minimumMinor] = array_map('intval', explode('.', static::MINIMUM_CONTRACT_VERSION));
        if ($runnerMajor !== $minimumMajor || version_compare($runnerCoreVersion, static::MINIMUM_CONTRACT_VERSION, '<')) {
            return false;
        }
        return $runnerMajor !== 0 || $runnerMinor === $minimumMinor;
    }

    /**
     * `1.2.3` of `1.2.3-rc.1+build`; null when the text is no version.
     *
     * @param string|null $version
     * @return string|null
     */
    public static function extractCoreVersion(?string $version): ?string
    {
        if ($version === null || trim($version) === '') {
            return null;
        }
        $coreVersion = (string)preg_replace('/[-+].*$/', '', trim($version));
        return preg_match('/^\d+\.\d+\.\d+$/', $coreVersion) === 1 ? $coreVersion : null;
    }
}
