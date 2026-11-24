<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerAnswerReader;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;

/**
 * The answer of `GET /v1/sessions` of one runner pod: its name, its state (idle, busy, releasing, broken) and its
 * session (none or one; a runner holds one session at a time).
 */
class SandboxRunnerSessionList
{
    /** @var string The address the answer came from (`ip:port`) */
    public string $runnerAddress = '';

    public string $pod = '';

    public string $state = '';

    /** @var SandboxRunnerSessionSummary[] */
    public array $sessions = [];

    /**
     * @param array<string, mixed> $data
     * @param string $runnerAddress
     * @return static
     * @throws SandboxRunnerException
     */
    public static function fromArray(array $data, string $runnerAddress): static
    {
        $reader = SandboxRunnerAnswerReader::of($data, 'sessions answer');
        $sessionList = new static();
        $sessionList->runnerAddress = $runnerAddress;
        $sessionList->pod = $reader->requireString('pod');
        $sessionList->state = $reader->requireString('state');
        foreach ($reader->requireObjectList('sessions') as $sessionReader) {
            $sessionList->sessions[] = SandboxRunnerSessionSummary::fromReader($sessionReader);
        }
        return $sessionList;
    }
}
