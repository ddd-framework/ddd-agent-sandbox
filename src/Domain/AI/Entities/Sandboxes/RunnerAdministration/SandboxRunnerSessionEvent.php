<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerAnswerReader;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DateTimeImmutable;

/**
 * One entry of a session's event log (`GET /v1/sessions/{id}/events`, the last 200): SESSION_CLAIMED, TIMEOUT,
 * SHELL_EXITED, JOB_KILLED, QUOTA_EXCEEDED, MEMORY_EXCEEDED, OOM_SUSPECTED, FUSE_LOST. The detail holds codes and
 * numbers only.
 */
class SandboxRunnerSessionEvent
{
    public int $seq = 0;

    public DateTimeImmutable $occurredAt;

    public string $type = '';

    public string $detail = '';

    /**
     * @param SandboxRunnerAnswerReader $reader
     * @return static
     * @throws SandboxRunnerException
     */
    public static function fromReader(SandboxRunnerAnswerReader $reader): static
    {
        $sessionEvent = new static();
        $sessionEvent->seq = $reader->requireInt('seq');
        $sessionEvent->occurredAt = $reader->requireMoment('occurredAt');
        $sessionEvent->type = $reader->requireString('type');
        $sessionEvent->detail = $reader->requireString('detail');
        return $sessionEvent;
    }
}
