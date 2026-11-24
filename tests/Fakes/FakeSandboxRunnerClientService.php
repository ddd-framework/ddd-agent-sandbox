<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes;

use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DDD\Domain\AI\Services\Sandboxes\SandboxRunnerClientService;

/**
 * A runner pool in memory: one session per address, files per session, `true`, `mkdir -p` and `rm -f --` as commands;
 * every other command exits 0 and may be given a scripted effect.
 */
class FakeSandboxRunnerClientService extends SandboxRunnerClientService
{
    /** @var string[] */
    public array $runnerAddresses = ['10.0.0.1:8080'];

    /** @var bool Whether the sandbox counts as enabled */
    public bool $isSandboxEnabledForTest = true;

    /** @var array<string, array<string, string>> files (path => bytes) by session id */
    public array $filesBySessionId = [];

    /** @var string[] session id by runner address */
    public array $sessionIdsByAddress = [];

    /** @var array<string, array<string, string>> the environment of each claimed session */
    public array $environmentsBySessionId = [];

    /** @var string[] every command run, in order */
    public array $executedCommands = [];

    /** @var int */
    public int $claimCount = 0;

    public function resolveRunnerAddresses(?string $poolName = null): array
    {
        return $this->runnerAddresses;
    }

    public function claimSession(string $runnerAddress, string $workspaceKey, array $environment, array $inlineFiles): array
    {
        if (isset($this->sessionIdsByAddress[$runnerAddress])) {
            throw SandboxRunnerException::fromRunner(503, SandboxRunnerException::RUNNER_BUSY, 'busy');
        }
        $this->claimCount++;
        $sessionId = 's' . $this->claimCount;
        $this->sessionIdsByAddress[$runnerAddress] = $sessionId;
        $this->environmentsBySessionId[$sessionId] = $environment;
        $this->filesBySessionId[$sessionId] = [];
        foreach ($inlineFiles as $inlineFile) {
            $this->filesBySessionId[$sessionId][$inlineFile['path']] = base64_decode($inlineFile['contentBase64']);
        }
        return ['sessionId' => $sessionId, 'uid' => 10001, 'workspace' => '/workspace', 'restoredFiles' => count($inlineFiles)];
    }

    public function execute(string $runnerAddress, string $sessionId, string $command, string $shellName, ?string $cwd, int $timeoutSeconds, bool $background): array
    {
        $this->requireSession($runnerAddress, $sessionId);
        $this->executedCommands[] = $command;
        if (str_starts_with($command, 'rm -f -- ')) {
            foreach (str_getcsv(substr($command, strlen('rm -f -- ')), ' ', "'") as $removedPath) {
                unset($this->filesBySessionId[$sessionId][$removedPath]);
            }
        }
        if (preg_match('/^echo (\S+) > (\S+)$/', $command, $matches) === 1) {
            $this->filesBySessionId[$sessionId][$matches[2]] = $matches[1] . "\n";
        }
        return ['status' => 'OK', 'exitCode' => 0, 'durationMs' => 5, 'cwd' => $cwd ?? '/workspace', 'stdout' => ['head' => '', 'tail' => ''], 'stderr' => ['head' => '', 'tail' => '']];
    }

    public function readFileBytes(string $runnerAddress, string $sessionId, string $relativePath): string
    {
        $this->requireSession($runnerAddress, $sessionId);
        if (!isset($this->filesBySessionId[$sessionId][$relativePath])) {
            throw SandboxRunnerException::fromRunner(404, SandboxRunnerException::FILE_NOT_FOUND, 'no such file');
        }
        return $this->filesBySessionId[$sessionId][$relativePath];
    }

    public function writeFileBytes(string $runnerAddress, string $sessionId, string $relativePath, string $bytes, ?string $mode = null, ?string $mtime = null): array
    {
        $this->requireSession($runnerAddress, $sessionId);
        $this->filesBySessionId[$sessionId][$relativePath] = $bytes;
        return ['path' => $relativePath, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    public function readManifest(string $runnerAddress, string $sessionId): array
    {
        $this->requireSession($runnerAddress, $sessionId);
        $manifestFiles = [];
        foreach ($this->filesBySessionId[$sessionId] as $path => $bytes) {
            $manifestFiles[] = ['path' => $path, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'mtime' => '2026-11-24T11:00:00Z', 'mode' => '0644'];
        }
        return ['files' => $manifestFiles, 'count' => count($manifestFiles), 'totalBytes' => 0];
    }

    public function releaseSession(string $runnerAddress, string $sessionId): array
    {
        $this->requireSession($runnerAddress, $sessionId);
        unset($this->sessionIdsByAddress[$runnerAddress], $this->filesBySessionId[$sessionId]);
        return ['released' => true, 'durationMs' => 1];
    }

    /** The runner forgets the session (pod restarted) */
    public function dropSessionOf(string $runnerAddress): void
    {
        $sessionId = $this->sessionIdsByAddress[$runnerAddress] ?? null;
        unset($this->sessionIdsByAddress[$runnerAddress]);
        if ($sessionId !== null) {
            unset($this->filesBySessionId[$sessionId]);
        }
    }

    protected function requireSession(string $runnerAddress, string $sessionId): void
    {
        if (($this->sessionIdsByAddress[$runnerAddress] ?? null) !== $sessionId) {
            throw SandboxRunnerException::fromRunner(404, SandboxRunnerException::SESSION_NOT_FOUND, 'no such session');
        }
    }

    public function isSandboxEnabled(): bool
    {
        return $this->isSandboxEnabledForTest;
    }
}
