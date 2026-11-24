<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerAnswerReader;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration\SandboxRunnerDirectoryListing;
use DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration\SandboxRunnerDiskUsage;
use DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration\SandboxRunnerExecRecord;
use DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration\SandboxRunnerProcess;
use DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration\SandboxRunnerSessionEvent;
use DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration\SandboxRunnerSessionList;
use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerHealth;
use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerPool;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP calls of the runner contract (runner/README.md of this package) against ONE runner address. The runner pods
 * sit behind a headless Service: {@see resolveRunnerAddresses()} answers one address per pod, a session is claimed on
 * one of them and every later call of that session goes to the same address (SandboxWorkspacesService keeps it).
 *
 * Every method answers the decoded JSON of a 2xx answer or throws {@see SandboxRunnerException} with the runner's
 * status and error code (status 0: no answer). Nothing here retries; the caller decides (next pod, claim again).
 */
class SandboxRunnerClientService extends Service
{
    /** @var float Seconds to open a connection to a runner pod. In-cluster a live pod connects in milliseconds; right
     * after a rollout a new pod's SYNs can be dropped for a while, so the caller moves on to the next pod (claim) or
     * retries once (a session's pod) after one second instead of waiting */
    public const float CONNECT_TIMEOUT_SECONDS = 1.0;

    /** @var int Seconds a call may take beyond the command's own timeout (runner bookkeeping, the kill grace) */
    public const int CALL_TIMEOUT_MARGIN_SECONDS = 20;

    /** @var int Seconds for calls without a command (claim, files, manifest, release) */
    public const int DEFAULT_CALL_TIMEOUT_SECONDS = 60;

    /** @var int Seconds for an administration read (the admin screen asks every pod; a busy pod must not hold the list) */
    public const int ADMINISTRATION_CALL_TIMEOUT_SECONDS = 10;

    /** @var int The commands a runner keeps per session (its ring buffer) */
    public const int MAX_RECENT_EXECS = 100;

    /** @var int The events a runner keeps per session (its ring buffer) */
    public const int MAX_SESSION_EVENTS = 200;

    /** @var Client[] by address */
    protected array $clientsByAddress = [];

    /**
     * The address of every runner pod (A records of the headless Service), in random order so claims spread.
     *
     * @param string|null $poolName a pool of `AI.Sandbox.runner.pools`; null = the default pool
     * @return string[] `ip:port`
     * @throws SandboxRunnerException when the name resolves to nothing
     */
    public function resolveRunnerAddresses(?string $poolName = null): array
    {
        $runnerPool = $this->getRunnerPool($poolName);
        $runnerPort = $runnerPool->port;
        if ($runnerPool->host === '') {
            throw SandboxRunnerException::fromRunner(0, SandboxRunnerException::UNREACHABLE, 'no runner host is configured (AI.Sandbox.runner.host, AGENT_SANDBOX_RUNNER_HOST)');
        }
        $ipAddresses = filter_var($runnerPool->host, FILTER_VALIDATE_IP) !== false ? [$runnerPool->host] : gethostbynamel($runnerPool->host);
        if ($ipAddresses === false || $ipAddresses === []) {
            throw SandboxRunnerException::fromRunner(0, SandboxRunnerException::UNREACHABLE, 'the runner Service resolves to no pod');
        }
        shuffle($ipAddresses);
        $runnerAddresses = [];
        foreach ($ipAddresses as $ipAddress) {
            $runnerAddresses[] = $ipAddress . ':' . $runnerPort;
        }
        return $runnerAddresses;
    }

    /**
     * POST /v1/sessions: binds the runner to the workspace and a fresh uid.
     *
     * @param string $runnerAddress
     * @param string $workspaceKey
     * @param array<string, string> $environment variables of every shell of the session
     * @param array<int, array{path: string, contentBase64: string, mode?: string, mtime?: string}> $inlineFiles
     * @return array{sessionId: string, uid: int, workspace: string, restoredFiles: int}
     * @throws SandboxRunnerException
     */
    public function claimSession(string $runnerAddress, string $workspaceKey, array $environment, array $inlineFiles): array
    {
        $body = ['workspaceKey' => $workspaceKey];
        if ($environment !== []) {
            $body['env'] = $environment;
        }
        if ($inlineFiles !== []) {
            $body['files'] = $inlineFiles;
        }
        return $this->requestJson($runnerAddress, 'POST', '/v1/sessions', [RequestOptions::JSON => $body], static::DEFAULT_CALL_TIMEOUT_SECONDS);
    }

    /**
     * POST /v1/sessions in drive mode (runner/README.md › "Protocol (drive mode)"): /workspace becomes the working copy
     * of a drive, further spaces are mounted below drivespaces/. The body carries no files.
     *
     * @param string $runnerAddress
     * @param string $workspaceKey
     * @param array<string, string> $environment variables of every shell of the session
     * @param array<string, mixed> $driveBlock the runner's `drive` block (spaceId, cursor, thresholdBytes, mirrorBudgetBytes, ignore, entries, mounts)
     * @return array<string, mixed> the claim answer (sessionId, uid, workspace, mode, transfer, drive)
     * @throws SandboxRunnerException
     */
    public function claimDriveSession(string $runnerAddress, string $workspaceKey, array $environment, array $driveBlock): array
    {
        $body = ['workspaceKey' => $workspaceKey, 'mode' => 'drive', 'drive' => $driveBlock];
        if ($environment !== []) {
            $body['env'] = $environment;
        }
        return $this->requestJson($runnerAddress, 'POST', '/v1/sessions', [RequestOptions::JSON => $body], static::DEFAULT_CALL_TIMEOUT_SECONDS);
    }

    /**
     * One drive call of a session in drive mode: GET …/drive/status, POST …/drive/stage, upload, commit, pull,
     * materialize, resolve.
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @param string $method GET or POST
     * @param string $driveCall status, stage, upload, commit, pull, materialize, resolve
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws SandboxRunnerException
     */
    public function callDrive(string $runnerAddress, string $sessionId, string $method, string $driveCall, ?array $body = null): array
    {
        $options = $body !== null ? [RequestOptions::JSON => (object)$body] : [];
        return $this->requestJson($runnerAddress, $method, '/v1/sessions/' . rawurlencode($sessionId) . '/drive/' . rawurlencode($driveCall), $options, static::DEFAULT_CALL_TIMEOUT_SECONDS * 3);
    }

    /**
     * POST /v1/sessions/{id}/execs/{seq}/continue: the answers to the drive requests of a paused exec (the in-session
     * `drive` CLI); answers the exec result (final, or DRIVE_REQUESTS again).
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @param int $seq
     * @param array<int, array<string, mixed>> $responses
     * @param int $timeoutSeconds the command's timeout (the rest of it runs inside this call)
     * @return array<string, mixed>
     * @throws SandboxRunnerException
     */
    public function continueExec(string $runnerAddress, string $sessionId, int $seq, array $responses, int $timeoutSeconds): array
    {
        return $this->requestJson(
            $runnerAddress,
            'POST',
            '/v1/sessions/' . rawurlencode($sessionId) . '/execs/' . $seq . '/continue',
            [RequestOptions::JSON => ['responses' => $responses]],
            $timeoutSeconds + static::CALL_TIMEOUT_MARGIN_SECONDS
        );
    }

    /**
     * POST /v1/sessions/{id}/exec.
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @param string $command
     * @param string $shellName
     * @param string|null $cwd relative to /workspace or absolute
     * @param int $timeoutSeconds
     * @param bool $background
     * @return array<string, mixed> the exec result (foreground) or `{jobId, state}` (background)
     * @throws SandboxRunnerException
     */
    public function execute(string $runnerAddress, string $sessionId, string $command, string $shellName, ?string $cwd, int $timeoutSeconds, bool $background): array
    {
        $body = ['command' => $command, 'shell' => $shellName, 'timeoutSeconds' => $timeoutSeconds];
        if ($cwd !== null && $cwd !== '') {
            $body['cwd'] = $cwd;
        }
        if ($background) {
            $body['background'] = true;
        }
        $callTimeoutSeconds = $background ? static::DEFAULT_CALL_TIMEOUT_SECONDS : $timeoutSeconds + static::CALL_TIMEOUT_MARGIN_SECONDS;
        return $this->requestJson($runnerAddress, 'POST', '/v1/sessions/' . rawurlencode($sessionId) . '/exec', [RequestOptions::JSON => $body], $callTimeoutSeconds);
    }

    /**
     * GET (or DELETE = kill) /v1/sessions/{id}/jobs/{job}.
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @param string $jobId
     * @param bool $kill
     * @return array<string, mixed> the job view
     * @throws SandboxRunnerException
     */
    public function readJob(string $runnerAddress, string $sessionId, string $jobId, bool $kill): array
    {
        return $this->requestJson(
            $runnerAddress,
            $kill ? 'DELETE' : 'GET',
            '/v1/sessions/' . rawurlencode($sessionId) . '/jobs/' . rawurlencode($jobId),
            [],
            static::DEFAULT_CALL_TIMEOUT_SECONDS
        );
    }

    /**
     * GET /v1/sessions/{id}/files/{path}.
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @param string $relativePath
     * @return string the bytes
     * @throws SandboxRunnerException
     */
    public function readFileBytes(string $runnerAddress, string $sessionId, string $relativePath): string
    {
        $response = $this->request($runnerAddress, 'GET', $this->buildFilePath($sessionId, $relativePath), [], static::DEFAULT_CALL_TIMEOUT_SECONDS);
        return (string)$response->getBody();
    }

    /**
     * PUT /v1/sessions/{id}/files/{path}: writes the file, creating its parents.
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @param string $relativePath
     * @param string $bytes
     * @param string|null $mode octal, e.g. 0644
     * @param string|null $mtime RFC 3339
     * @return array{path: string, size: int, sha256: string}
     * @throws SandboxRunnerException
     */
    public function writeFileBytes(string $runnerAddress, string $sessionId, string $relativePath, string $bytes, ?string $mode = null, ?string $mtime = null): array
    {
        $query = [];
        if ($mode !== null && $mode !== '') {
            $query['mode'] = $mode;
        }
        if ($mtime !== null && $mtime !== '') {
            $query['mtime'] = $mtime;
        }
        return $this->requestJson(
            $runnerAddress,
            'PUT',
            $this->buildFilePath($sessionId, $relativePath),
            [RequestOptions::BODY => $bytes, RequestOptions::QUERY => $query, RequestOptions::HEADERS => ['Content-Length' => (string)strlen($bytes), 'Content-Type' => 'application/octet-stream']],
            static::DEFAULT_CALL_TIMEOUT_SECONDS
        );
    }

    /**
     * GET /v1/sessions/{id}/manifest: every regular file of the workspace with its sha256.
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @return array{files: array<int, array{path: string, size: int, sha256: string, mtime: string, mode: string}>, count: int, totalBytes: int}
     * @throws SandboxRunnerException
     */
    public function readManifest(string $runnerAddress, string $sessionId): array
    {
        return $this->requestJson($runnerAddress, 'GET', '/v1/sessions/' . rawurlencode($sessionId) . '/manifest', [], static::DEFAULT_CALL_TIMEOUT_SECONDS);
    }

    /**
     * DELETE /v1/sessions/{id}: kills every process of the session, wipes it, frees the runner.
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @return array{released: bool, durationMs: int}
     * @throws SandboxRunnerException
     */
    public function releaseSession(string $runnerAddress, string $sessionId): array
    {
        return $this->requestJson($runnerAddress, 'DELETE', '/v1/sessions/' . rawurlencode($sessionId), [], static::DEFAULT_CALL_TIMEOUT_SECONDS);
    }

    /**
     * GET /v1/sessions: the session of one runner pod (none or one) with memory, disk and mount mode
     * (runner/README.md, "Administration"). Admin reads never count as activity of the session.
     *
     * @param string $runnerAddress
     * @return SandboxRunnerSessionList
     * @throws SandboxRunnerException
     */
    public function listRunnerSessions(string $runnerAddress): SandboxRunnerSessionList
    {
        return SandboxRunnerSessionList::fromArray(
            $this->requestJson($runnerAddress, 'GET', '/v1/sessions', [], static::ADMINISTRATION_CALL_TIMEOUT_SECONDS),
            $runnerAddress
        );
    }

    /**
     * GET /v1/sessions/{id}/processes: every process of the session (argv masked and cut, never the environment).
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @return SandboxRunnerProcess[]
     * @throws SandboxRunnerException
     */
    public function findProcesses(string $runnerAddress, string $sessionId): array
    {
        $answer = $this->requestJson($runnerAddress, 'GET', $this->buildSessionPath($sessionId, 'processes'), [], static::ADMINISTRATION_CALL_TIMEOUT_SECONDS);
        $processes = [];
        foreach (SandboxRunnerAnswerReader::of($answer, 'processes answer')->requireObjectList('processes') as $processReader) {
            $processes[] = SandboxRunnerProcess::fromReader($processReader);
        }
        return $processes;
    }

    /**
     * GET /v1/sessions/{id}/execs?limit=: the last commands of the session, the newest first.
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @param int $limit 1 … 100
     * @return SandboxRunnerExecRecord[]
     * @throws SandboxRunnerException
     */
    public function findRecentExecs(string $runnerAddress, string $sessionId, int $limit = self::MAX_RECENT_EXECS): array
    {
        $answer = $this->requestJson(
            $runnerAddress,
            'GET',
            $this->buildSessionPath($sessionId, 'execs'),
            [RequestOptions::QUERY => ['limit' => max(1, min($limit, static::MAX_RECENT_EXECS))]],
            static::ADMINISTRATION_CALL_TIMEOUT_SECONDS
        );
        $execRecords = [];
        foreach (SandboxRunnerAnswerReader::of($answer, 'execs answer')->requireObjectList('execs') as $execReader) {
            $execRecords[] = SandboxRunnerExecRecord::fromReader($execReader);
        }
        return $execRecords;
    }

    /**
     * GET /v1/sessions/{id}/events?limit=: the session's event log, the newest first.
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @param int $limit 1 … 200
     * @return SandboxRunnerSessionEvent[]
     * @throws SandboxRunnerException
     */
    public function findSessionEvents(string $runnerAddress, string $sessionId, int $limit = self::MAX_SESSION_EVENTS): array
    {
        $answer = $this->requestJson(
            $runnerAddress,
            'GET',
            $this->buildSessionPath($sessionId, 'events'),
            [RequestOptions::QUERY => ['limit' => max(1, min($limit, static::MAX_SESSION_EVENTS))]],
            static::ADMINISTRATION_CALL_TIMEOUT_SECONDS
        );
        $sessionEvents = [];
        foreach (SandboxRunnerAnswerReader::of($answer, 'events answer')->requireObjectList('events') as $eventReader) {
            $sessionEvents[] = SandboxRunnerSessionEvent::fromReader($eventReader);
        }
        return $sessionEvents;
    }

    /**
     * GET /v1/sessions/{id}/disk: the exact disk usage of the session.
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @return SandboxRunnerDiskUsage
     * @throws SandboxRunnerException
     */
    public function readDiskUsage(string $runnerAddress, string $sessionId): SandboxRunnerDiskUsage
    {
        return SandboxRunnerDiskUsage::fromArray(
            $this->requestJson($runnerAddress, 'GET', $this->buildSessionPath($sessionId, 'disk'), [], static::DEFAULT_CALL_TIMEOUT_SECONDS)
        );
    }

    /**
     * GET /v1/sessions/{id}/files?path=: one directory of the workspace (empty path: the root). The runner refuses
     * `..`, absolute paths and symlinks that lead out (400 PATH_REFUSED), a file (409 NOT_A_DIRECTORY), a missing
     * folder (404 FILE_NOT_FOUND).
     *
     * @param string $runnerAddress
     * @param string $sessionId
     * @param string $relativePath workspace-relative
     * @return SandboxRunnerDirectoryListing
     * @throws SandboxRunnerException
     */
    public function listDirectory(string $runnerAddress, string $sessionId, string $relativePath): SandboxRunnerDirectoryListing
    {
        $options = $relativePath !== '' ? [RequestOptions::QUERY => ['path' => $relativePath]] : [];
        return SandboxRunnerDirectoryListing::fromArray(
            $this->requestJson($runnerAddress, 'GET', $this->buildSessionPath($sessionId, 'files'), $options, static::DEFAULT_CALL_TIMEOUT_SECONDS)
        );
    }

    /**
     * GET /healthz of one runner pod: contract version, toolkit, image configuration hash, capabilities, limits.
     *
     * @param string $runnerAddress
     * @return SandboxRunnerHealth
     * @throws SandboxRunnerException when the pod does not answer (a broken pod answers 503 with its health)
     */
    public function readHealth(string $runnerAddress): SandboxRunnerHealth
    {
        $response = $this->getClient($runnerAddress)->request('GET', '/healthz', [
            RequestOptions::TIMEOUT => static::ADMINISTRATION_CALL_TIMEOUT_SECONDS,
            RequestOptions::CONNECT_TIMEOUT => static::CONNECT_TIMEOUT_SECONDS * 3,
            RequestOptions::HTTP_ERRORS => false,
        ]);
        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded)) {
            throw SandboxRunnerException::fromRunner($response->getStatusCode(), 'INVALID_ANSWER', 'the runner answered no JSON object on /healthz');
        }
        return SandboxRunnerHealth::fromArray($decoded, $runnerAddress, $response->getStatusCode());
    }

    /**
     * The runner pool of a name: `AI.Sandbox.runner.pools.<name>` ({host, port}); null or an unknown name = the default
     * pool (`AI.Sandbox.runner.host` / `.port`).
     *
     * @param string|null $poolName
     * @return SandboxRunnerPool
     */
    public function getRunnerPool(?string $poolName = null): SandboxRunnerPool
    {
        $runnerPool = new SandboxRunnerPool();
        $runnerPool->name = SandboxRunnerPool::DEFAULT_POOL_NAME;
        $runnerPool->host = trim((string)(static::readSettingWithEnvOverride('host') ?? ''));
        $runnerPool->port = (int)(static::readSettingWithEnvOverride('port') ?? 8080);
        if ($poolName === null || $poolName === SandboxRunnerPool::DEFAULT_POOL_NAME) {
            return $runnerPool;
        }
        $configuredPools = Config::get('AI.Sandbox.runner.pools');
        $configuredPool = is_array($configuredPools) ? ($configuredPools[$poolName] ?? null) : null;
        if (!is_array($configuredPool) || trim((string)($configuredPool['host'] ?? '')) === '') {
            return $runnerPool;
        }
        $runnerPool->name = $poolName;
        $runnerPool->host = trim((string)$configuredPool['host']);
        $runnerPool->port = (int)($configuredPool['port'] ?? $runnerPool->port);
        return $runnerPool;
    }

    /**
     * Whether the sandbox may be used: `AI.Sandbox.runner.enabled`, replaced by its env variable (`enabledEnv`,
     * AGENT_SANDBOX_ENABLED) when that is set.
     *
     * @return bool
     */
    public function isSandboxEnabled(): bool
    {
        return (bool)static::readSettingWithEnvOverride('enabled');
    }

    /**
     * A setting of `AI.Sandbox.runner`, replaced by the env variable `<name>Env` names when that variable is set.
     *
     * @param string $settingName enabled, host or port
     * @return bool|int|float|string|null
     */
    public static function readSettingWithEnvOverride(string $settingName): bool|int|float|string|null
    {
        $envVariableName = Config::get('AI.Sandbox.runner.' . $settingName . 'Env');
        if (is_string($envVariableName) && $envVariableName !== '') {
            $envValue = Config::getEnv($envVariableName);
            if ($envValue !== null && $envValue !== '') {
                return $envValue;
            }
        }
        $configuredValue = Config::get('AI.Sandbox.runner.' . $settingName);
        return is_scalar($configuredValue) ? $configuredValue : null;
    }

    /**
     * Uses the given HTTP client for one address (tests); null goes back to the default client.
     *
     * @param string $runnerAddress
     * @param Client|null $client
     * @return void
     */
    public function setClientForAddress(string $runnerAddress, ?Client $client): void
    {
        if ($client === null) {
            unset($this->clientsByAddress[$runnerAddress]);
            return;
        }
        $this->clientsByAddress[$runnerAddress] = $client;
    }

    /**
     * @param string $sessionId
     * @param string $call processes, execs, events, disk, files
     * @return string
     */
    protected function buildSessionPath(string $sessionId, string $call): string
    {
        return '/v1/sessions/' . rawurlencode($sessionId) . '/' . $call;
    }

    /**
     * @param string $sessionId
     * @param string $relativePath
     * @return string
     */
    protected function buildFilePath(string $sessionId, string $relativePath): string
    {
        $encodedSegments = array_map('rawurlencode', explode('/', $relativePath));
        return '/v1/sessions/' . rawurlencode($sessionId) . '/files/' . implode('/', $encodedSegments);
    }

    /**
     * @param string $runnerAddress
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $options
     * @param int $timeoutSeconds
     * @return array<string, mixed>
     * @throws SandboxRunnerException
     */
    protected function requestJson(string $runnerAddress, string $method, string $path, array $options, int $timeoutSeconds): array
    {
        $response = $this->request($runnerAddress, $method, $path, $options, $timeoutSeconds);
        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded)) {
            throw SandboxRunnerException::fromRunner($response->getStatusCode(), 'INVALID_ANSWER', 'the runner answered no JSON object');
        }
        return $decoded;
    }

    /**
     * @param string $runnerAddress
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $options
     * @param int $timeoutSeconds
     * @return ResponseInterface a 2xx answer
     * @throws SandboxRunnerException
     */
    protected function request(string $runnerAddress, string $method, string $path, array $options, int $timeoutSeconds): ResponseInterface
    {
        $options[RequestOptions::TIMEOUT] = $timeoutSeconds;
        $options[RequestOptions::CONNECT_TIMEOUT] = static::CONNECT_TIMEOUT_SECONDS;
        $options[RequestOptions::HTTP_ERRORS] = false;
        try {
            $response = $this->getClient($runnerAddress)->request($method, $path, $options);
        } catch (GuzzleException $guzzleException) {
            throw SandboxRunnerException::fromRunner(
                0,
                static::wasConnected($guzzleException) ? SandboxRunnerException::NO_ANSWER : SandboxRunnerException::UNREACHABLE,
                'no answer from ' . $runnerAddress . ' (' . get_class($guzzleException) . ')'
            );
        }
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            return $response;
        }
        $decoded = json_decode((string)$response->getBody(), true);
        throw SandboxRunnerException::fromRunner(
            $statusCode,
            is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : 'HTTP_' . $statusCode,
            is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : 'no message',
            is_array($decoded) ? $decoded : []
        );
    }

    /**
     * Whether the request reached the runner before it failed (curl opened the connection). Then the runner may have
     * acted on it (a command ran), and the call is not repeated: NO_ANSWER instead of UNREACHABLE.
     *
     * @param GuzzleException $guzzleException
     * @return bool
     */
    public static function wasConnected(GuzzleException $guzzleException): bool
    {
        if (!$guzzleException instanceof RequestException && !$guzzleException instanceof ConnectException) {
            return false;
        }
        return (float)($guzzleException->getHandlerContext()['connect_time'] ?? 0.0) > 0.0;
    }

    /**
     * @param string $runnerAddress
     * @return Client
     */
    protected function getClient(string $runnerAddress): Client
    {
        return $this->clientsByAddress[$runnerAddress] ??= new Client(['base_uri' => 'http://' . $runnerAddress]);
    }
}
