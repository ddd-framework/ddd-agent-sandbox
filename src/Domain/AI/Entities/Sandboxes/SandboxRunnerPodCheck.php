<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/** The deploy check of one runner pod: its health and what does not fit this package or the project */
class SandboxRunnerPodCheck
{
    public string $poolName = SandboxRunnerPool::DEFAULT_POOL_NAME;

    public string $runnerAddress = '';

    public ?SandboxRunnerHealth $health = null;

    /** @var string[] */
    public array $problems = [];

    /** @return array<string, mixed> the JSON row of the check (the command's output format) */
    public function toOutputRow(): array
    {
        return [
            'pool' => $this->poolName,
            'runner' => $this->runnerAddress,
            'status' => $this->health?->status,
            'state' => $this->health?->state,
            'contractVersion' => $this->health?->contractVersion,
            'toolkit' => $this->health?->toolkit,
            'imageConfigHash' => $this->health?->imageConfigHash,
            'capabilities' => [
                SandboxRunnerContract::CAPABILITY_DRIVE => $this->health?->supportsDrive,
                SandboxRunnerContract::CAPABILITY_FUSE => $this->health?->supportsFuse,
                SandboxRunnerContract::CAPABILITY_TRANSFER_PROXY => $this->health?->hasTransferProxy,
            ],
            'problems' => $this->problems,
        ];
    }
}
