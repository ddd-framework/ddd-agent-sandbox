<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerAnswerReader;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DateTimeImmutable;

/**
 * One session of a runner pod (`GET /v1/sessions`, services/sandbox-runner/README.md "Administration"): its key, when
 * it was claimed and last used, memory and disk now, how the drive mounts appear and what runs.
 */
class SandboxRunnerSessionSummary
{
    /** @var string The drive mounts are the placeholder file system (every file listed, streamed) */
    public const string MOUNT_MODE_FUSE = 'FUSE';

    /** @var string Plan B (local mount files copied in) or a legacy session without mounts; the reason says which */
    public const string MOUNT_MODE_PLAIN = 'PLAIN';

    public string $sessionId = '';

    public string $workspaceKey = '';

    public DateTimeImmutable $createdAt;

    public DateTimeImmutable $lastActivityAt;

    public int $memoryBytes = 0;

    public int $memoryPeakBytes = 0;

    public int $memoryLimitBytes = 0;

    /** @var string cgroup-v1, cgroup-v2, rss or off */
    public string $memoryMeasure = '';

    public int $diskUsedBytes = 0;

    public int $diskQuotaBytes = 0;

    /** @var string FUSE or PLAIN */
    public string $mountMode = self::MOUNT_MODE_PLAIN;

    public string $mountModeReason = '';

    /** @var string legacy or drive */
    public string $mode = '';

    /** @var string The pod's name (HOSTNAME) */
    public string $pod = '';

    public int $runningJobCount = 0;

    public int $runningExecCount = 0;

    public int $shellCount = 0;

    /**
     * @param SandboxRunnerAnswerReader $reader
     * @return static
     * @throws SandboxRunnerException
     */
    public static function fromReader(SandboxRunnerAnswerReader $reader): static
    {
        $summary = new static();
        $summary->sessionId = $reader->requireString('sessionId');
        $summary->workspaceKey = $reader->requireString('workspaceKey');
        $summary->createdAt = $reader->requireMoment('createdAt');
        $summary->lastActivityAt = $reader->requireMoment('lastActivityAt');
        $summary->memoryBytes = $reader->requireInt('memoryBytes');
        $summary->memoryPeakBytes = $reader->requireInt('memoryPeakBytes');
        $summary->memoryLimitBytes = $reader->requireInt('memoryLimitBytes');
        $summary->memoryMeasure = $reader->requireString('memoryMeasure');
        $summary->diskUsedBytes = $reader->requireInt('diskUsedBytes');
        $summary->diskQuotaBytes = $reader->requireInt('diskQuotaBytes');
        $summary->mountMode = $reader->requireString('mountMode');
        if (!in_array($summary->mountMode, [static::MOUNT_MODE_FUSE, static::MOUNT_MODE_PLAIN], true)) {
            throw SandboxRunnerAnswerReader::invalid($reader->context . '.mountMode', 'is neither FUSE nor PLAIN');
        }
        $summary->mountModeReason = $reader->requireString('mountModeReason');
        $summary->mode = $reader->requireString('mode');
        $summary->pod = $reader->requireString('pod');
        $summary->runningJobCount = $reader->requireInt('runningJobCount');
        $summary->runningExecCount = $reader->requireInt('runningExecCount');
        $summary->shellCount = $reader->requireInt('shellCount');
        return $summary;
    }
}
