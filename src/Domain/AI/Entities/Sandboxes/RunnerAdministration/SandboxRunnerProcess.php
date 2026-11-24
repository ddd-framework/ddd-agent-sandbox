<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerAnswerReader;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DateTimeImmutable;

/**
 * One process of a session (`GET /v1/sessions/{id}/processes`): argv (cut to 512 characters, env values masked as
 * `***`, never the environment), start, CPU time, resident memory, and the background job it belongs to.
 */
class SandboxRunnerProcess
{
    public int $pid = 0;

    public string $argv = '';

    /** @var bool argv was cut */
    public bool $isTruncated = false;

    /** @var DateTimeImmutable|null null when /proc gave no start */
    public ?DateTimeImmutable $startedAt = null;

    public float $cpuSeconds = 0.0;

    public int $rssBytes = 0;

    /** @var string|null The job whose bash is an ancestor */
    public ?string $jobId = null;

    /**
     * @param SandboxRunnerAnswerReader $reader
     * @return static
     * @throws SandboxRunnerException
     */
    public static function fromReader(SandboxRunnerAnswerReader $reader): static
    {
        $process = new static();
        $process->pid = $reader->requireInt('pid');
        $process->argv = $reader->requireString('argv');
        $process->isTruncated = $reader->requireBool('truncated');
        $process->startedAt = $reader->findMoment('startedAt');
        $process->cpuSeconds = $reader->requireNumber('cpuSeconds');
        $process->rssBytes = $reader->requireInt('rssBytes');
        $process->jobId = $reader->findString('jobId');
        return $process;
    }
}
