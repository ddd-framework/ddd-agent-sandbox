<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerAnswerReader;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;

/**
 * The disk usage of a session from the exact walk (`GET /v1/sessions/{id}/disk`): used bytes below the quota
 * directories (`/workspace`, `/tmp`), the quota, the effective limit (the smaller of quota and what the volume still
 * holds) and the free space of the workspace volume.
 */
class SandboxRunnerDiskUsage
{
    public int $usedBytes = 0;

    public int $quotaBytes = 0;

    public int $limitBytes = 0;

    public int $freeBytes = 0;

    /** @var array<string, int> directory → bytes */
    public array $bytesByDirectory = [];

    /**
     * @param array<string, mixed> $data
     * @return static
     * @throws SandboxRunnerException
     */
    public static function fromArray(array $data): static
    {
        $reader = SandboxRunnerAnswerReader::of($data, 'disk answer');
        $diskUsage = new static();
        $diskUsage->usedBytes = $reader->requireInt('usedBytes');
        $diskUsage->quotaBytes = $reader->requireInt('quotaBytes');
        $diskUsage->limitBytes = $reader->requireInt('limitBytes');
        $diskUsage->freeBytes = $reader->requireInt('freeBytes');
        $diskUsage->bytesByDirectory = $reader->findIntMap('byDirectory');
        return $diskUsage;
    }
}
