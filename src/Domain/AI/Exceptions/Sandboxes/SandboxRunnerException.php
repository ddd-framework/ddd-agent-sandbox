<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Sandboxes;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * A call to a sandbox runner that did not get the answer it needs: the runner's status and error code (runner/README.md of
 * this package: `{"error": "<CODE>", "message": "…"}`), or status 0 when no runner answered. The
 * runner's messages never carry commands, output or file names, so the message is safe to log.
 */
class SandboxRunnerException extends InternalErrorException
{
    /** @var string The runner holds another session (503, a claim tries the next pod) */
    public const string RUNNER_BUSY = 'RUNNER_BUSY';

    /** @var string The session ended on the runner (released, idle, pod restarted): claim again and restore */
    public const string SESSION_NOT_FOUND = 'SESSION_NOT_FOUND';

    /** @var string The path names no file of the workspace */
    public const string FILE_NOT_FOUND = 'FILE_NOT_FOUND';

    /** @var string A command already runs in this shell */
    public const string SHELL_BUSY = 'SHELL_BUSY';

    /** @var string No connection to the runner (refused, connect timeout, no address): the request never reached it */
    public const string UNREACHABLE = 'UNREACHABLE';

    /** @var string The connection was open, but no answer came (read timeout, reset): the runner may have acted on it */
    public const string NO_ANSWER = 'NO_ANSWER';

    /** @var string The workspace would pass its disk quota (PUT 413; an exec answers it as its status) */
    public const string QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';

    public int $runnerStatusCode = 0;

    /** @var array<string, mixed> The runner's error answer as decoded (e.g. totalBytes and limitBytes of QUOTA_EXCEEDED) */
    public array $runnerErrorBody = [];

    public string $runnerErrorCode = self::UNREACHABLE;

    /**
     * @param int $runnerStatusCode
     * @param string $runnerErrorCode
     * @param string $message
     * @param array<string, mixed> $runnerErrorBody
     * @return static
     */
    public static function fromRunner(int $runnerStatusCode, string $runnerErrorCode, string $message, array $runnerErrorBody = []): static
    {
        $sandboxRunnerException = new static("Sandbox runner answered {$runnerStatusCode} {$runnerErrorCode}: {$message}");
        $sandboxRunnerException->runnerStatusCode = $runnerStatusCode;
        $sandboxRunnerException->runnerErrorCode = $runnerErrorCode;
        $sandboxRunnerException->runnerErrorBody = $runnerErrorBody;
        return $sandboxRunnerException;
    }

    /**
     * @return bool Whether the session is gone on its runner (claim again, restore, retry)
     */
    public function isSessionGone(): bool
    {
        return $this->runnerErrorCode === static::SESSION_NOT_FOUND || $this->runnerErrorCode === static::UNREACHABLE;
    }
}
