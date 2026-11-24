<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerAnswerReader;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DateTimeImmutable;

/**
 * One command of the session history (`GET /v1/sessions/{id}/execs`, the last 100): a foreground exec or a background
 * job, its status, exit code and the ends of its output (each at most 8 KB, env values masked as `***`).
 */
class SandboxRunnerExecRecord
{
    /** @var string The status of a command that still runs */
    public const string STATUS_RUNNING = 'running';

    public int $seq = 0;

    /** @var string At most 2 KB */
    public string $command = '';

    public string $shell = '';

    public DateTimeImmutable $startedAt;

    /** @var int So far while it runs */
    public int $durationMs = 0;

    /** @var string running, an exec status (OK, TIMED_OUT, SHELL_EXITED, QUOTA_EXCEEDED, MEMORY_EXCEEDED) or a job state (exited, timedOut, killed, quotaExceeded, memoryExceeded) */
    public string $status = self::STATUS_RUNNING;

    public ?int $exitCode = null;

    public string $stdoutTail = '';

    public string $stderrTail = '';

    /** @var bool The command or an output tail was cut */
    public bool $isTruncated = false;

    /** @var string|null The job id of a background command */
    public ?string $jobId = null;

    /**
     * @param SandboxRunnerAnswerReader $reader
     * @return static
     * @throws SandboxRunnerException
     */
    public static function fromReader(SandboxRunnerAnswerReader $reader): static
    {
        $execRecord = new static();
        $execRecord->seq = $reader->requireInt('seq');
        $execRecord->command = $reader->requireString('command');
        $execRecord->shell = $reader->requireString('shell');
        $execRecord->startedAt = $reader->requireMoment('startedAt');
        $execRecord->durationMs = $reader->requireInt('durationMs');
        $execRecord->status = $reader->requireString('status');
        $execRecord->exitCode = $reader->findInt('exitCode');
        $execRecord->stdoutTail = $reader->requireString('stdoutTail');
        $execRecord->stderrTail = $reader->requireString('stderrTail');
        $execRecord->isTruncated = $reader->requireBool('truncated');
        $execRecord->jobId = $reader->findString('jobId');
        return $execRecord;
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->status === static::STATUS_RUNNING;
    }
}
