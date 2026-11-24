<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer of one runner pod's `GET /healthz`: readiness, contract version, toolkit, the hash of the project's image
 * configuration (`image.yaml`), capabilities and limits.
 */
class SandboxRunnerHealth extends ValueObject
{
    /** @var string `ip:port` of the pod */
    public string $runnerAddress = '';

    public int $httpStatusCode = 0;

    /** @var string|null ok or broken */
    public ?string $status = null;

    /** @var string|null free, busy, … */
    public ?string $state = null;

    public ?string $contractVersion = null;

    /** @var string|null base or full (the toolkit the image was built with) */
    public ?string $toolkit = null;

    /** @var string|null The hash of the project's image configuration baked into the image; empty for the module's image */
    public ?string $imageConfigHash = null;

    public bool $supportsDrive = false;

    public bool $supportsFuse = false;

    public bool $hasTransferProxy = false;

    public ?SandboxRunnerLimits $limits = null;

    /**
     * @param array<string, mixed> $healthAnswer the decoded JSON of `/healthz`
     * @param string $runnerAddress
     * @param int $httpStatusCode
     * @return static
     */
    public static function fromArray(array $healthAnswer, string $runnerAddress, int $httpStatusCode): static
    {
        $sandboxRunnerHealth = new static();
        $sandboxRunnerHealth->runnerAddress = $runnerAddress;
        $sandboxRunnerHealth->httpStatusCode = $httpStatusCode;
        $sandboxRunnerHealth->status = is_string($healthAnswer['status'] ?? null) ? $healthAnswer['status'] : null;
        $sandboxRunnerHealth->state = is_string($healthAnswer['state'] ?? null) ? $healthAnswer['state'] : null;
        $sandboxRunnerHealth->contractVersion = is_string($healthAnswer['contractVersion'] ?? null) ? $healthAnswer['contractVersion'] : null;
        $sandboxRunnerHealth->toolkit = is_string($healthAnswer['toolkit'] ?? null) ? $healthAnswer['toolkit'] : null;
        $sandboxRunnerHealth->imageConfigHash = is_string($healthAnswer['imageConfigHash'] ?? null) ? $healthAnswer['imageConfigHash'] : null;
        $capabilities = is_array($healthAnswer['capabilities'] ?? null) ? $healthAnswer['capabilities'] : [];
        $sandboxRunnerHealth->supportsDrive = ($capabilities[SandboxRunnerContract::CAPABILITY_DRIVE] ?? false) === true;
        $sandboxRunnerHealth->supportsFuse = ($capabilities[SandboxRunnerContract::CAPABILITY_FUSE] ?? false) === true;
        $sandboxRunnerHealth->hasTransferProxy = ($capabilities[SandboxRunnerContract::CAPABILITY_TRANSFER_PROXY] ?? false) === true;
        if (is_array($healthAnswer['limits'] ?? null)) {
            $limitsAnswer = $healthAnswer['limits'];
            $sandboxRunnerLimits = new SandboxRunnerLimits();
            foreach (SandboxRunnerLimits::LIMIT_NAMES as $limitName) {
                if (is_int($limitsAnswer[$limitName] ?? null)) {
                    $sandboxRunnerLimits->$limitName = $limitsAnswer[$limitName];
                }
            }
            $sandboxRunnerHealth->limits = $sandboxRunnerLimits;
        }
        return $sandboxRunnerHealth;
    }

    public function isReady(): bool
    {
        return $this->httpStatusCode === 200 && $this->status === 'ok';
    }
}
