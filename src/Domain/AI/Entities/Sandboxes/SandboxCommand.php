<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One command the agent ran in a workspace, with the tails of its output. Command and output are foreign text,
 * truncated by the runner; secrets of the session's environment are masked by the runner.
 */
class SandboxCommand extends ValueObject
{
    /** @var string Still running (no exit code yet) */
    public const string STATUS_RUNNING = 'RUNNING';

    /** @var string Ended by itself; the exit code says how */
    public const string STATUS_EXITED = 'EXITED';

    /** @var string Killed (a kill of its job, the end of the session, a memory limit) */
    public const string STATUS_KILLED = 'KILLED';

    /** @var string Stopped by the runner's time limit */
    public const string STATUS_TIMED_OUT = 'TIMED_OUT';

    public const array STATUSES = [self::STATUS_RUNNING, self::STATUS_EXITED, self::STATUS_KILLED, self::STATUS_TIMED_OUT];

    /** @var int The command's sequence number in the session */
    public int $seq = 0;

    /** @var string The command (truncated) */
    public string $command = '';

    /** @var DateTime|null When it started */
    public ?DateTime $startedAt = null;

    /** @var int|null How long it ran, in milliseconds; null while it runs */
    public ?int $durationMs = null;

    /** @var int|null The exit code; null while it runs or when it was killed without one */
    public ?int $exitCode = null;

    /** @var string|null The end of its standard output */
    public ?string $stdoutTail = null;

    /** @var string|null The end of its error output */
    public ?string $stderrTail = null;

    /** @var bool The command or an output was cut */
    public bool $isTruncated = false;

    /**
     * @var string|null RUNNING, EXITED, KILLED or TIMED_OUT, filled from the runner: an exit code →
     *      EXITED, a killed job → KILLED, the runner's timeout → TIMED_OUT, otherwise RUNNING. Null = the administration does not
     *      know (a screen must not read null as running).
     */
    #[Choice(choices: self::STATUSES)]
    public ?string $status = null;

    /** @var string|null The background job of the command */
    public ?string $jobId = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->seq);
    }
}
