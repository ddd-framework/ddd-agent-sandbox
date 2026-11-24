<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes\Drive;

use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DDD\Tests\AgentSandbox\Fakes\FakeSandboxRunnerClientService;

/**
 * The fake runner pool with the drive protocol: drive claims, drive calls and the CLI pause are recorded; answers are
 * scripted per drive call.
 */
class FakeDriveRunnerClientService extends FakeSandboxRunnerClientService
{
    /** @var array<int, array<string, mixed>> the drive blocks of the drive claims, in order */
    public array $driveClaimBlocks = [];

    /** @var array<int, array<string, string>> the environments of the drive claims, in order */
    public array $driveClaimEnvironments = [];

    /** @var array<int, array{method: string, call: string, body: array<string, mixed>|null}> every drive call */
    public array $driveCalls = [];

    /** @var array<string, array<int, array<string, mixed>>> scripted answers by drive call (shifted; the last one stays) */
    public array $driveAnswersByCall = [];

    /** @var array<string, mixed> the claim answer's `drive` part */
    public array $claimDriveResult = [];

    /** @var array<string, mixed>|null the answer of the next command starting with `drive ` (a CLI pause) */
    public ?array $pausedExecAnswer = null;

    /** @var array<int, array<int, array<string, mixed>>> the responses of every continue call */
    public array $continuedResponses = [];

    public function claimDriveSession(string $runnerAddress, string $workspaceKey, array $environment, array $driveBlock): array
    {
        if (isset($this->sessionIdsByAddress[$runnerAddress])) {
            throw SandboxRunnerException::fromRunner(503, SandboxRunnerException::RUNNER_BUSY, 'busy');
        }
        $this->claimCount++;
        $sessionId = 's' . $this->claimCount;
        $this->sessionIdsByAddress[$runnerAddress] = $sessionId;
        $this->environmentsBySessionId[$sessionId] = $environment;
        $this->filesBySessionId[$sessionId] = [];
        $this->driveClaimBlocks[] = $driveBlock;
        $this->driveClaimEnvironments[] = $environment;
        return ['sessionId' => $sessionId, 'uid' => 10001, 'workspace' => '/workspace', 'mode' => 'drive', 'transfer' => 'proxy', 'drive' => $this->claimDriveResult];
    }

    public function callDrive(string $runnerAddress, string $sessionId, string $method, string $driveCall, ?array $body = null): array
    {
        $this->requireSession($runnerAddress, $sessionId);
        $this->driveCalls[] = ['method' => $method, 'call' => $driveCall, 'body' => $body];
        $answers = $this->driveAnswersByCall[$driveCall] ?? [];
        if ($answers === []) {
            return [];
        }
        return count($answers) > 1 ? array_shift($this->driveAnswersByCall[$driveCall]) : $answers[0];
    }

    public function execute(string $runnerAddress, string $sessionId, string $command, string $shellName, ?string $cwd, int $timeoutSeconds, bool $background): array
    {
        if ($this->pausedExecAnswer !== null && str_starts_with($command, 'drive ')) {
            $this->requireSession($runnerAddress, $sessionId);
            $this->executedCommands[] = $command;
            $pausedExecAnswer = $this->pausedExecAnswer;
            $this->pausedExecAnswer = null;
            return $pausedExecAnswer;
        }
        return parent::execute($runnerAddress, $sessionId, $command, $shellName, $cwd, $timeoutSeconds, $background);
    }

    public function continueExec(string $runnerAddress, string $sessionId, int $seq, array $responses, int $timeoutSeconds): array
    {
        $this->requireSession($runnerAddress, $sessionId);
        $this->continuedResponses[] = $responses;
        return ['status' => 'OK', 'exitCode' => 0, 'durationMs' => 3, 'cwd' => '/workspace', 'stdout' => ['head' => 'done', 'tail' => ''], 'stderr' => ['head' => '', 'tail' => '']];
    }

    /** @return string[] the drive calls by name, in order */
    public function findDriveCallNames(): array
    {
        return array_map(static fn(array $driveCall): string => $driveCall['call'], $this->driveCalls);
    }

    /** @return array<int, array<string, mixed>|null> the bodies of one drive call */
    public function findDriveCallBodies(string $driveCall): array
    {
        $bodies = [];
        foreach ($this->driveCalls as $recordedCall) {
            if ($recordedCall['call'] === $driveCall) {
                $bodies[] = $recordedCall['body'];
            }
        }
        return $bodies;
    }
}
