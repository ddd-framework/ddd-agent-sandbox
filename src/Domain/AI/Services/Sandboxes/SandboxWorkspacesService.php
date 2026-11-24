<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxCommandRun;
use DDD\Domain\AI\Entities\Sandboxes\SandboxDirtyMark;
use DDD\Domain\AI\Entities\Sandboxes\SandboxFileWrite;
use DDD\Domain\AI\Entities\Sandboxes\SandboxFreedScratch;
use DDD\Domain\AI\Entities\Sandboxes\SandboxJobRead;
use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerClaim;
use DDD\Domain\AI\Entities\Sandboxes\SandboxSession;
use DDD\Domain\AI\Entities\Sandboxes\SandboxSweepResult;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceActivityRecord;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceExpiryReport;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceExpiryReports;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceManifest;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceManifestFile;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceManifestFiles;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceSyncResult;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceZones;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Persistent workspaces in the sandbox, addressed by an explicit workspace key (`[A-Za-z0-9][A-Za-z0-9._-]{0,127}`).
 * The caller decides the key: the agent part of this module uses `space-<agentSpaceId>` / `account-<accountId>`, a
 * host's report job may use its own. Every call names the conversation it belongs to (its shell and cwd); a call
 * without one uses {@see CONVERSATION_KEY_WITHOUT_CONVERSATION}.
 *
 * - **Session.** The workspace is bound to one runner pod while it is in use: claimed on the first pod of the pool's
 *   headless Service that answers 201, then every call goes to that pod. Session id, pod and the shell and cwd per
 *   conversation live in the shared cache (`AI.Sandbox.runner.cacheGroup`), so every worker and process reuses it. A
 *   session that ended on its runner (released, idle, pod restarted) is claimed again and the call repeated once.
 * - **Never from zero.** A claim restores every file of the stored manifest from the workspace store
 *   ({@see SandboxWorkspaceStore}, `AI.Sandbox.workspaces.store`).
 * - **Saved after every call, in the background.** After each call that may change files the runner's manifest is
 *   compared by sha256 with the state the workspace was last seen in; the answer names the changes at once. The store
 *   upload runs in a flush process the call starts (`AI.Sandbox.workspaces.uploadInBackground`). Until it ends the runner
 *   session holds the only copy of the change: a PHP process that dies loses nothing (the mark stays and the next call,
 *   release or claim flushes from the session); only a runner session lost inside that window (seconds) loses that
 *   call's change, which the next claim logs. Release, claim, a call of another conversation and the state note flush
 *   first. Reads save nothing.
 * - **Capacity.** Every pod holds one session. When every pod is busy the idle sessions are released once and the
 *   claim tried again; then the call is refused with a clear message (no queue).
 * - **Release after idle.** A session without a call for `idleReleaseSeconds` is saved and released by the next claim
 *   of any workspace (at most one sweep a minute) or by `app:agent-sandbox:sessions --sweep`.
 */
class SandboxWorkspacesService extends Service
{
    /** @var string The conversation key of a call without a conversation (a console, a job, the evaluation harness) */
    public const string CONVERSATION_KEY_WITHOUT_CONVERSATION = 'harness';

    /** @var string The shape of a workspace key (it becomes a store key prefix and a cache key part) */
    public const string WORKSPACE_KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    /** @var int Seconds a session entry of the cache lives beyond its last use (the runner releases after 2 h itself) */
    public const int SESSION_CACHE_SECONDS = 3 * 3600;

    /** @var int Seconds the saved manifest stays in the cache */
    public const int MANIFEST_CACHE_SECONDS = 86400;

    /** @var int Seconds a manifest token lives (refreshed by every write; far longer than an entry, so a lapsed token finds no old entry) */
    public const int MANIFEST_TOKEN_CACHE_SECONDS = 30 * 86400;

    /** @var int Seconds the "state note shown" mark of a conversation lives */
    public const int STATE_NOTE_MARK_SECONDS = 7 * 86400;

    /** @var int Seconds between two idle sweeps */
    public const int IDLE_SWEEP_INTERVAL_SECONDS = 60;

    /** @var int Seconds a claim or save lock is held at most */
    public const int LOCK_SECONDS = 180;

    /** @var int Seconds a caller waits for the claim or save lock of a workspace */
    public const int LOCK_WAIT_SECONDS = 90;

    /** @var int Raw bytes restored inline with the claim; the rest goes one PUT per file (the claim body is capped at 64 MiB of base64) */
    public const int INLINE_RESTORE_BYTES = 40 * 1024 * 1024;

    /** @var string The store key of the preloaded content (app:agent-sandbox:seed); no caller workspace may use it */
    public const string PRELOAD_WORKSPACE_KEY = 'system.preload';

    /** @var string The prefix of reserved store keys */
    public const string RESERVED_WORKSPACE_KEY_PREFIX = 'system.';

    /** @var string The folder below /workspace the preloaded content is restored into (read-only by mode, never saved) */
    public const string PRELOAD_DIRECTORY = '.preload';

    /** @var string The start script of the preloaded content, run once at the start of every session */
    public const string PRELOAD_START_SCRIPT = 'start.sh';

    /** @var int Seconds the start script may take */
    public const int PRELOAD_START_SCRIPT_TIMEOUT_SECONDS = 120;

    /** @var string[] Path segments never saved (caches, installed packages, the preloaded content; lost+found of an ext4 volume at /workspace) */
    public const array EXCLUDED_PATH_SEGMENTS = ['.runner', 'node_modules', '__pycache__', '.cache', '.ipynb_checkpoints', 'lost+found', self::PRELOAD_DIRECTORY];

    /** @var int Save rounds of one flush before it leaves the rest to the next one (a workspace written without pause) */
    public const int MAX_FLUSH_ROUNDS = 5;

    /** @var int Seconds after which an unsaved mark counts as left behind by a dead flush process; the next call saves first */
    public const int FLUSH_OVERDUE_SECONDS = 30;

    /** @var string The workspace memory file */
    public const string NOTES_PATH = 'NOTES.md';

    /** @var int Bytes of saved files a workspace keeps before the expiry removes the oldest scratch (≈ 90 % of a 4Gi runner volume) */
    public const int DEFAULT_STORED_WORKSPACE_QUOTA_BYTES = 3_600_000_000;

    /** @var int Seconds the cache keeps the activity of a workspace (longer than scratch lasts, so the expiry sees it) */
    public const int ACTIVITY_CACHE_SECONDS = 10 * 86400;

    /** @var int The most paths one call notes as read */
    public const int MAX_READ_PATHS_NOTED = 200;

    /** @var int Microseconds before the one retry on a session's pod that took no connection */
    public const int UNREACHABLE_RETRY_PAUSE_MICROSECONDS = 1_000_000;

    /** @var string The tool error when the session's pod takes no connection while a change of it is not saved */
    public const string UNREACHABLE_WITH_UNSAVED_CHANGE_MESSAGE = 'The sandbox runner is unreachable; the last change is not saved yet. Try again in a moment; nothing was run.';

    /** @var string The refusal when every runner pod is busy after the idle sessions were released */
    public const string ALL_SLOTS_BUSY_MESSAGE = 'Every sandbox slot is in use by another workspace right now. Try again in a minute; nothing was run.';

    /** @var string The console command the background flush runs */
    public const string FLUSH_COMMAND_NAME = 'app:agent-sandbox:sessions';

    /** @var bool Whether the last call of this process had to claim a session (measurement) */
    public bool $lastCallClaimedSession = false;

    /** @var int Milliseconds the last claim took, restore included (measurement) */
    public int $lastClaimMilliseconds = 0;

    /** @var bool Whether the current call lost its session and runs on a new one (files from the store, shells and cwd fresh) */
    public bool $lastCallRestartedSession = false;

    /** @var int[] Milliseconds (and counts) per phase of the current call, by phase name (measurement) */
    public array $callTimings = [];

    /** @var SandboxSession|null The session the last {@see runOnSession()} ran on (a new one after a restart) */
    protected ?SandboxSession $lastSessionOfCall = null;

    /** @var string|null A cache group set by a test (null = AI.Sandbox.runner.cacheGroup) */
    protected ?string $cacheGroupOverride = null;

    /** @var bool|null Whether calls leave the store upload to a flush process, set by a test (null = config) */
    protected ?bool $uploadInBackgroundOverride = null;

    /** @var iterable<SandboxWorkspaceStore> The stores registered by tag */
    protected iterable $sandboxWorkspaceStores = [];

    /** @var SandboxWorkspaceStore|null A store set by a test */
    protected ?SandboxWorkspaceStore $sandboxWorkspaceStoreOverride = null;

    /**
     * @param iterable<SandboxWorkspaceStore> $sandboxWorkspaceStores
     */
    #[Required]
    public function setSandboxWorkspaceStores(
        #[AutowireIterator(SandboxWorkspaceStore::TAG)]
        iterable $sandboxWorkspaceStores
    ): void {
        $this->sandboxWorkspaceStores = $sandboxWorkspaceStores;
    }

    /**
     * @throws BadRequestException when the key is no plain workspace key
     */
    public static function requireValidWorkspaceKey(string $workspaceKey): void
    {
        if (preg_match(static::WORKSPACE_KEY_PATTERN, $workspaceKey) !== 1) {
            throw new BadRequestException('The workspace key is no plain key (letters, digits, ".", "_", "-"; at most 128): ' . mb_substr($workspaceKey, 0, 140));
        }
        if (str_starts_with($workspaceKey, static::RESERVED_WORKSPACE_KEY_PREFIX)) {
            throw new BadRequestException('Workspace keys starting with "' . static::RESERVED_WORKSPACE_KEY_PREFIX . '" are reserved: ' . $workspaceKey);
        }
    }

    /**
     * Makes the preloaded content equal to the given files (`app:agent-sandbox:seed`): every later claim restores them
     * below /workspace/.preload (`$SANDBOX_PRELOAD`), read-only by mode and never saved; a running session keeps the
     * content it was claimed with.
     *
     * @param string[] $contentsByPath content by path relative to the preload folder
     * @return SandboxWorkspaceManifest the stored preload manifest
     * @throws InternalErrorException
     */
    public function seedPreloadContent(array $contentsByPath): SandboxWorkspaceManifest
    {
        $lockToken = $this->waitForLock('sync.' . static::PRELOAD_WORKSPACE_KEY);
        try {
            $sandboxWorkspaceStore = $this->getWorkspaceStore();
            $storedManifest = $sandboxWorkspaceStore->readManifest(static::PRELOAD_WORKSPACE_KEY);
            if ($this->isManifestOfContents($storedManifest, $contentsByPath)) {
                $this->cacheManifestAfterWrite(static::PRELOAD_WORKSPACE_KEY, $storedManifest);
                return $storedManifest;
            }
            $preloadManifest = $contentsByPath === []
                ? new SandboxWorkspaceManifest()
                : $sandboxWorkspaceStore->createWorkspaceFromFiles(static::PRELOAD_WORKSPACE_KEY, $contentsByPath);
            if ($contentsByPath === []) {
                $sandboxWorkspaceStore->deleteWorkspace(static::PRELOAD_WORKSPACE_KEY);
            }
            $this->cacheManifestAfterWrite(static::PRELOAD_WORKSPACE_KEY, $preloadManifest);
            return $preloadManifest;
        } finally {
            $this->getCache()->releaseLock($this->buildCacheKey('lock', 'sync.' . static::PRELOAD_WORKSPACE_KEY), $lockToken);
        }
    }

    /**
     * @param SandboxWorkspaceManifest $sandboxWorkspaceManifest
     * @param string[] $contentsByPath
     * @return bool Whether the manifest holds exactly these paths with these contents
     */
    protected function isManifestOfContents(SandboxWorkspaceManifest $sandboxWorkspaceManifest, array $contentsByPath): bool
    {
        if ($sandboxWorkspaceManifest->countFiles() !== count($contentsByPath)) {
            return false;
        }
        foreach ($contentsByPath as $path => $content) {
            if ($sandboxWorkspaceManifest->files->findByPath(ltrim((string)$path, '/'))?->sha256 !== hash('sha256', $content)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Starts the phase timings of one call.
     *
     * @return void
     */
    public function startCallTimings(): void
    {
        $this->callTimings = [];
        $this->lastCallRestartedSession = false;
    }

    /**
     * Adds the milliseconds since $startedAt to a phase of the current call.
     *
     * @param string $phase
     * @param int|float $startedAt hrtime(true)
     * @return void
     */
    protected function addCallTiming(string $phase, int|float $startedAt): void
    {
        $this->callTimings[$phase] = ($this->callTimings[$phase] ?? 0) + (int)((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * Runs a command in the conversation's shell (its cwd), then saves what changed. A background command answers its
     * job at once and saves nothing yet.
     *
     * @param string $workspaceKey
     * @param string $conversationKey
     * @param string $command
     * @param int $timeoutSeconds capped by the session rules
     * @param bool $background
     * @return SandboxCommandRun
     * @throws BadRequestException a background job where the rules allow none
     * @throws ConflictException every shell is busy, or no slot is free
     * @throws InternalErrorException
     */
    public function runShellCommand(string $workspaceKey, string $conversationKey, string $command, int $timeoutSeconds, bool $background): SandboxCommandRun
    {
        $sandboxSessionRulesService = $this->getSandboxSessionRulesService();
        if ($background && !$sandboxSessionRulesService->findRulesOfWorkspace($workspaceKey)->allowsBackgroundJobs) {
            throw new BadRequestException('Background jobs are not allowed in this workspace; run the command in the foreground. Nothing was run.');
        }
        $cappedTimeoutSeconds = $sandboxSessionRulesService->capCommandTimeoutSeconds($workspaceKey, $timeoutSeconds, $background);
        $sandboxSession = $this->ensureSession($workspaceKey);
        $this->flushBeforeCall($workspaceKey, $conversationKey);
        $sandboxCommandRun = $this->runOnSession(
            $workspaceKey,
            $sandboxSession,
            fn(SandboxSession $session): SandboxCommandRun => $this->runCommandInConversationShell($session, $conversationKey, $command, $cappedTimeoutSeconds, $background)
        );
        $sandboxSession = $this->lastSessionOfCall ?? $sandboxSession;
        $sandboxCommandRun->syncResult = $background ? null : $this->syncWorkspace($sandboxSession, $conversationKey);
        return $sandboxCommandRun;
    }

    /**
     * The state of a background job (or kills it), then saves what changed when it ended.
     *
     * @param string $workspaceKey
     * @param string $jobId
     * @param bool $kill
     * @return SandboxJobRead
     * @throws ConflictException
     * @throws InternalErrorException
     */
    public function readBackgroundJob(string $workspaceKey, string $jobId, bool $kill): SandboxJobRead
    {
        $sandboxSession = $this->ensureSession($workspaceKey);
        try {
            $jobAnswer = $this->getSandboxRunnerClientService()->readJob($sandboxSession->runnerAddress, $sandboxSession->sessionId, $jobId, $kill);
        } catch (SandboxRunnerException $sandboxRunnerException) {
            if ($sandboxRunnerException->runnerErrorCode === SandboxRunnerException::SESSION_NOT_FOUND) {
                // the job ended with its session; the next call claims a new one (an unreachable pod may still run it)
                $this->forgetSession($workspaceKey);
            }
            throw $sandboxRunnerException;
        }
        $this->touchSession($sandboxSession);
        $sandboxJobRead = new SandboxJobRead();
        $sandboxJobRead->jobAnswer = $jobAnswer;
        $sandboxJobRead->syncResult = $sandboxJobRead->isRunning() ? null : $this->syncWorkspace($sandboxSession);
        return $sandboxJobRead;
    }

    /**
     * Runs a command of the file tools in their own shell (a text view, a search). Saves nothing: a file tool's
     * command writes only below /tmp.
     *
     * @param string $workspaceKey
     * @param string $command
     * @param int $timeoutSeconds
     * @return array<string, mixed> the runner's exec answer
     * @throws ConflictException
     * @throws InternalErrorException
     */
    public function runFilesToolCommand(string $workspaceKey, string $command, int $timeoutSeconds): array
    {
        $sandboxSession = $this->ensureSession($workspaceKey);
        $sandboxRunnerClientService = $this->getSandboxRunnerClientService();
        $execAnswer = $this->runOnSession($workspaceKey, $sandboxSession, function (SandboxSession $session) use ($sandboxRunnerClientService, $command, $timeoutSeconds): array {
            $runnerStartedAt = hrtime(true);
            try {
                return $sandboxRunnerClientService->execute($session->runnerAddress, $session->sessionId, $command, SandboxSession::FILES_SHELL_NAME, '/workspace', $timeoutSeconds, false);
            } finally {
                $this->addCallTiming('runnerMs', $runnerStartedAt);
            }
        });
        $this->touchSession($this->lastSessionOfCall ?? $sandboxSession);
        return $execAnswer;
    }

    /**
     * @param string $workspaceKey
     * @param string $relativePath
     * @return string|null the bytes; null when there is no such file
     * @throws ConflictException
     * @throws InternalErrorException
     */
    public function readWorkspaceFileBytes(string $workspaceKey, string $relativePath): ?string
    {
        $sandboxSession = $this->ensureSession($workspaceKey);
        $bytes = $this->runOnSession($workspaceKey, $sandboxSession, fn(SandboxSession $session): ?string => $this->readFileBytesOrNull($session, $relativePath));
        $this->touchSession($this->lastSessionOfCall ?? $sandboxSession);
        return $bytes;
    }

    /**
     * @param SandboxSession $sandboxSession
     * @param string $relativePath
     * @return string|null null when the runner answers FILE_NOT_FOUND
     * @throws SandboxRunnerException any other refusal
     */
    protected function readFileBytesOrNull(SandboxSession $sandboxSession, string $relativePath): ?string
    {
        $startedAt = hrtime(true);
        try {
            return $this->getSandboxRunnerClientService()->readFileBytes($sandboxSession->runnerAddress, $sandboxSession->sessionId, $relativePath);
        } catch (SandboxRunnerException $sandboxRunnerException) {
            if ($sandboxRunnerException->runnerErrorCode === SandboxRunnerException::FILE_NOT_FOUND) {
                return null;
            }
            throw $sandboxRunnerException;
        } finally {
            $this->addCallTiming('runnerMs', $startedAt);
        }
    }

    /**
     * Writes a file (parents created), then saves what changed.
     *
     * @param string $workspaceKey
     * @param string $relativePath
     * @param string $bytes
     * @param string|null $conversationKey the conversation of the call (null: a console or job write)
     * @return SandboxFileWrite
     * @throws ConflictException
     * @throws InternalErrorException
     */
    public function writeWorkspaceFileBytes(string $workspaceKey, string $relativePath, string $bytes, ?string $conversationKey = null): SandboxFileWrite
    {
        $sandboxSession = $this->ensureSession($workspaceKey);
        $this->flushBeforeCall($workspaceKey, $conversationKey);
        $sandboxRunnerClientService = $this->getSandboxRunnerClientService();
        $writeAnswer = $this->runOnSession($workspaceKey, $sandboxSession, function (SandboxSession $session) use ($sandboxRunnerClientService, $relativePath, $bytes): array {
            $runnerStartedAt = hrtime(true);
            try {
                return $sandboxRunnerClientService->writeFileBytes($session->runnerAddress, $session->sessionId, $relativePath, $bytes);
            } finally {
                $this->addCallTiming('runnerMs', $runnerStartedAt);
            }
        });
        $sandboxSession = $this->lastSessionOfCall ?? $sandboxSession;
        $this->touchSession($sandboxSession);
        $sandboxFileWrite = new SandboxFileWrite();
        $sandboxFileWrite->path = (string)($writeAnswer['path'] ?? $relativePath);
        $sandboxFileWrite->size = (int)($writeAnswer['size'] ?? strlen($bytes));
        $sandboxFileWrite->sha256 = (string)($writeAnswer['sha256'] ?? hash('sha256', $bytes));
        $sandboxFileWrite->syncResult = $this->syncWorkspace($sandboxSession, $conversationKey);
        return $sandboxFileWrite;
    }

    /**
     * The saved manifest of a workspace (cache first, then the store).
     *
     * @param string $workspaceKey
     * @return SandboxWorkspaceManifest
     * @throws InternalErrorException
     */
    public function findSavedManifest(string $workspaceKey): SandboxWorkspaceManifest
    {
        $cachedManifest = $this->readCachedManifest($workspaceKey);
        if ($cachedManifest !== null) {
            return $cachedManifest;
        }
        $tokenSeenBeforeRead = $this->readManifestToken($workspaceKey);
        $sandboxWorkspaceManifest = $this->getWorkspaceStore()->readManifest($workspaceKey);
        $this->cacheManifestAfterRead($workspaceKey, $sandboxWorkspaceManifest, $tokenSeenBeforeRead);
        return $sandboxWorkspaceManifest;
    }

    /**
     * The cached saved manifest, valid only while it carries the workspace's current manifest token. Every manifest
     * write of ours sets a new token (under the save lock, after the store write), so an entry a reader filled from an
     * older store read is never served after a write: entry and token come in one multi-get.
     *
     * @param string $workspaceKey
     * @return SandboxWorkspaceManifest|null
     */
    protected function readCachedManifest(string $workspaceKey): ?SandboxWorkspaceManifest
    {
        $entryKey = $this->buildCacheKey('manifest', $workspaceKey);
        $tokenKey = $this->buildCacheKey('manifestToken', $workspaceKey);
        $cachedValues = (array)$this->getCache()->get($entryKey, $tokenKey);
        $token = is_string($cachedValues[$tokenKey] ?? null) ? $cachedValues[$tokenKey] : '';
        $cachedEntry = $cachedValues[$entryKey] ?? null;
        if (!is_array($cachedEntry) || !is_array($cachedEntry['manifest'] ?? null) || ($cachedEntry['token'] ?? null) !== $token) {
            return null;
        }
        return SandboxWorkspaceManifest::fromArray($cachedEntry['manifest']);
    }

    /**
     * @param string $workspaceKey
     * @return string the current manifest token ('' before any write)
     */
    protected function readManifestToken(string $workspaceKey): string
    {
        $token = $this->getCache()->get($this->buildCacheKey('manifestToken', $workspaceKey));
        return is_string($token) ? $token : '';
    }

    /**
     * Fills the cache from a store read with the token seen BEFORE that read: when a write came in between, the
     * entry carries the old token and is not served.
     *
     * @param string $workspaceKey
     * @param SandboxWorkspaceManifest $sandboxWorkspaceManifest
     * @param string $tokenSeenBeforeRead
     * @return void
     */
    protected function cacheManifestAfterRead(string $workspaceKey, SandboxWorkspaceManifest $sandboxWorkspaceManifest, string $tokenSeenBeforeRead): void
    {
        $this->getCache()->set($this->buildCacheKey('manifest', $workspaceKey), ['token' => $tokenSeenBeforeRead, 'manifest' => $this->buildCacheableManifest($sandboxWorkspaceManifest)], static::MANIFEST_CACHE_SECONDS);
    }

    /**
     * After a manifest write of ours (the caller holds the save lock and has written the store): a new token first,
     * then the entry under it; null = the workspace is gone (entry removed).
     *
     * @param string $workspaceKey
     * @param SandboxWorkspaceManifest|null $sandboxWorkspaceManifest
     * @return void
     */
    protected function cacheManifestAfterWrite(string $workspaceKey, ?SandboxWorkspaceManifest $sandboxWorkspaceManifest): void
    {
        $token = bin2hex(random_bytes(8));
        $this->getCache()->set($this->buildCacheKey('manifestToken', $workspaceKey), $token, static::MANIFEST_TOKEN_CACHE_SECONDS);
        if ($sandboxWorkspaceManifest === null) {
            $this->getCache()->delete($this->buildCacheKey('manifest', $workspaceKey));
            return;
        }
        $this->getCache()->set($this->buildCacheKey('manifest', $workspaceKey), ['token' => $token, 'manifest' => $this->buildCacheableManifest($sandboxWorkspaceManifest)], static::MANIFEST_CACHE_SECONDS);
    }

    /**
     * The manifest as plain arrays for the cache (the cache marshaller unserialises no stdClass of the stored form).
     *
     * @param SandboxWorkspaceManifest $sandboxWorkspaceManifest
     * @return array<string, mixed>
     */
    protected function buildCacheableManifest(SandboxWorkspaceManifest $sandboxWorkspaceManifest): array
    {
        return json_decode(json_encode($sandboxWorkspaceManifest->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The state note for the first workspace call of a conversation: the workspace, NOTES.md (capped) and the most
     * recently changed files. Null when the conversation saw it already. Read from the saved manifest and the store, no
     * runner call.
     *
     * @param string $workspaceKey
     * @param string $conversationKey
     * @param string|null $ownerDescription whose workspace it is, as one phrase (the agent part names the space); null = the key
     * @return string|null
     * @throws InternalErrorException
     */
    public function buildStateNoteOnce(string $workspaceKey, string $conversationKey, ?string $ownerDescription = null): ?string
    {
        if ($conversationKey === static::CONVERSATION_KEY_WITHOUT_CONVERSATION) {
            return null;
        }
        $markKey = $this->buildCacheKey('stateNoteShown', $workspaceKey . '.' . $conversationKey);
        if ($this->getCache()->get($markKey)) {
            return null;
        }
        $this->getCache()->set($markKey, 1, static::STATE_NOTE_MARK_SECONDS);
        if ($this->findDirtyMark($workspaceKey) !== null) {
            // the first call of a conversation: what another conversation left is saved first, NOTES.md is read from the store
            $this->flushPendingChanges($workspaceKey);
        }
        $workspaceDescription = $workspaceKey . ' (' . ($ownerDescription ?? 'persistent') . ')';
        $sandboxWorkspaceManifest = $this->findSavedManifest($workspaceKey);
        $workDirectoryLine = 'Your shell starts in ' . SandboxWorkspaceZones::buildWorkDirectoryOfConversation($conversationKey)
            . '/ (scratch of this conversation, kept ' . SandboxWorkspaceZones::SCRATCH_DAYS . ' days after it); paths below are relative to /workspace.';
        $toolkitLine = $this->buildToolkitLine();
        if ($sandboxWorkspaceManifest->countFiles() === 0) {
            return implode("\n", array_filter([
                sprintf('Workspace %s: empty, no files and no NOTES.md yet.', $workspaceDescription),
                $workDirectoryLine,
                $toolkitLine,
            ]));
        }
        $lines = [sprintf(
            'Workspace %s: %d files, %s.',
            $workspaceDescription,
            $sandboxWorkspaceManifest->countFiles(),
            SandboxWorkspaceSyncResult::formatBytes($sandboxWorkspaceManifest->sumBytes())
        ), $workDirectoryLine];
        if ($toolkitLine !== null) {
            $lines[] = $toolkitLine;
        }
        $lines[] = 'Recently changed:';
        foreach ($sandboxWorkspaceManifest->findRecentlyChangedFiles((int)(Config::get('AI.Sandbox.workspaces.recentFilesInStateNote') ?? 10)) as $manifestFile) {
            $lines[] = '- ' . $manifestFile->path . ' · ' . SandboxWorkspaceSyncResult::formatBytes($manifestFile->size) . ' · ' . substr(str_replace('T', ' ', $manifestFile->mtime), 0, 16) . ' UTC';
        }
        $notesFile = $sandboxWorkspaceManifest->files->findByPath(static::NOTES_PATH);
        if ($notesFile === null) {
            $lines[] = 'No NOTES.md yet.';
        } else {
            $notesPreviewCharacters = (int)(Config::get('AI.Sandbox.workspaces.notesPreviewCharacters') ?? 4000);
            $notes = $this->getWorkspaceStore()->readBlob($workspaceKey, $notesFile->sha256);
            $lines[] = 'NOTES.md' . (mb_strlen($notes) > $notesPreviewCharacters ? ' (first ' . $notesPreviewCharacters . ' characters)' : '') . ':';
            $lines[] = rtrim(mb_substr($notes, 0, $notesPreviewCharacters));
        }
        return implode("\n", $lines);
    }

    /**
     * The toolkit line of the state note (`AI.Sandbox.runner.toolkitNote`, e.g. what the project's image adds); null
     * without one.
     *
     * @return string|null
     */
    protected function buildToolkitLine(): ?string
    {
        $toolkitNote = trim((string)(Config::get('AI.Sandbox.runner.toolkitNote') ?? ''));
        return $toolkitNote !== '' ? 'Toolkit: ' . $toolkitNote : null;
    }

    /**
     * The bound session of a workspace, claimed (and restored) when there is none.
     *
     * @param string $workspaceKey
     * @return SandboxSession
     * @throws BadRequestException the key is no plain key
     * @throws ConflictException no runner pod is free
     * @throws InternalErrorException
     */
    public function ensureSession(string $workspaceKey): SandboxSession
    {
        static::requireValidWorkspaceKey($workspaceKey);
        $this->lastCallClaimedSession = false;
        $this->lastClaimMilliseconds = 0;
        $sandboxSession = $this->findSession($workspaceKey);
        if ($sandboxSession !== null) {
            return $sandboxSession;
        }
        $lockToken = $this->waitForLock('claim.' . $workspaceKey);
        try {
            $sandboxSession = $this->findSession($workspaceKey);
            if ($sandboxSession !== null) {
                return $sandboxSession;
            }
            $phaseStartedAt = hrtime(true);
            $this->releaseIdleSessionsWhenDue();
            $this->addCallTiming('idleSweepMs', $phaseStartedAt);
            return $this->claimAndRestore($workspaceKey);
        } finally {
            $this->getCache()->releaseLock($this->buildCacheKey('lock', 'claim.' . $workspaceKey), $lockToken);
        }
    }

    /**
     * @param string $workspaceKey
     * @return SandboxSession|null the session the cache holds for the workspace
     */
    public function findSession(string $workspaceKey): ?SandboxSession
    {
        $startedAt = hrtime(true);
        $cachedSession = $this->getCache()->get($this->buildCacheKey('session', $workspaceKey));
        $this->addCallTiming('sessionCacheMs', $startedAt);
        if (!is_array($cachedSession) || ($cachedSession['sessionId'] ?? '') === '') {
            return null;
        }
        return SandboxSession::fromArray($cachedSession);
    }

    /**
     * Every session the cache knows.
     *
     * @return SandboxSession[]
     */
    public function findSessions(): array
    {
        $sandboxSessions = [];
        foreach ($this->readSessionIndex() as $workspaceKey) {
            $sandboxSession = $this->findSession($workspaceKey);
            if ($sandboxSession !== null) {
                $sandboxSessions[] = $sandboxSession;
            }
        }
        return $sandboxSessions;
    }

    /**
     * Saves the workspace and releases its session (kills its processes, wipes the slot). A session that already
     * ended on its runner is only forgotten.
     *
     * @param string $workspaceKey
     * @return bool whether a session was released
     * @throws InternalErrorException
     */
    public function releaseSession(string $workspaceKey): bool
    {
        $sandboxSession = $this->findSession($workspaceKey);
        if ($sandboxSession === null) {
            return false;
        }
        try {
            // the store first, then the runner: the session is the only copy of what is not saved yet
            $this->flushWorkspace($sandboxSession);
            $this->forgetUnsavedMarks($workspaceKey);
            $this->getSandboxRunnerClientService()->releaseSession($sandboxSession->runnerAddress, $sandboxSession->sessionId);
        } catch (SandboxRunnerException $sandboxRunnerException) {
            if (!$sandboxRunnerException->isSessionGone()) {
                throw $sandboxRunnerException;
            }
        } finally {
            $this->forgetSession($workspaceKey);
        }
        return true;
    }

    /**
     * Under the claim lock: saves the workspace and releases its session ({@see releaseSession()}). The admin's
     * offload and the `--offload` option of the sessions command (before a runner rollout).
     *
     * @param string $workspaceKey
     * @return bool whether a session was released
     * @throws ConflictException the workspace is busy with a claim
     * @throws InternalErrorException
     */
    public function offloadSession(string $workspaceKey): bool
    {
        $lockToken = $this->waitForLock('claim.' . $workspaceKey);
        try {
            return $this->releaseSession($workspaceKey);
        } finally {
            $this->getCache()->releaseLock($this->buildCacheKey('lock', 'claim.' . $workspaceKey), $lockToken);
        }
    }

    /**
     * Offloads every session the cache knows (saved, then released): before a runner rollout, so no change lives only
     * in a pod that is replaced.
     *
     * @return string[] the offloaded workspace keys
     * @throws InternalErrorException
     */
    public function offloadAllSessions(): array
    {
        $offloadedWorkspaceKeys = [];
        foreach ($this->findSessions() as $sandboxSession) {
            if ($this->offloadSession($sandboxSession->workspaceKey)) {
                $offloadedWorkspaceKeys[] = $sandboxSession->workspaceKey;
            }
        }
        return $offloadedWorkspaceKeys;
    }

    /**
     * The admin's end: saves what the session changed into the store, then ends the session on its runner. A session
     * already gone is only forgotten.
     *
     * @param string $workspaceKey
     * @return bool whether a session was ended
     * @throws ConflictException the workspace is busy with a claim
     * @throws InternalErrorException
     */
    public function endSession(string $workspaceKey): bool
    {
        $lockToken = $this->waitForLock('claim.' . $workspaceKey);
        try {
            $sandboxSession = $this->findSession($workspaceKey);
            if ($sandboxSession === null) {
                return false;
            }
            try {
                $this->flushWorkspace($sandboxSession);
            } catch (SandboxRunnerException $sandboxRunnerException) {
                if (!$sandboxRunnerException->isSessionGone()) {
                    throw $sandboxRunnerException;
                }
                $this->forgetSession($workspaceKey);
                return false;
            }
            $this->forgetUnsavedMarks($workspaceKey);
            $this->discardSession($workspaceKey);
            return true;
        } finally {
            $this->getCache()->releaseLock($this->buildCacheKey('lock', 'claim.' . $workspaceKey), $lockToken);
        }
    }

    /**
     * The admin's reset: ends the session without a save (the runner wipes the slot) and drops the unsaved marks, so the
     * next claim starts from the stored state. The store keeps the workspace.
     *
     * @param string $workspaceKey
     * @return void
     * @throws ConflictException the workspace is busy with a claim or save
     * @throws InternalErrorException
     */
    public function resetSession(string $workspaceKey): void
    {
        $lockToken = $this->waitForLock('claim.' . $workspaceKey);
        try {
            $this->runUnderSaveLock($workspaceKey, function () use ($workspaceKey): void {
                $this->discardSession($workspaceKey);
                $this->forgetUnsavedMarks($workspaceKey);
            });
        } finally {
            $this->getCache()->releaseLock($this->buildCacheKey('lock', 'claim.' . $workspaceKey), $lockToken);
        }
    }

    /**
     * Gives the key a fresh workspace made of the given files (an eval case run, a job's input): a session still held
     * for the key ends without a save, the store holds exactly these files, the next call claims and restores them.
     *
     * @param string $workspaceKey
     * @param string[] $contentsByPath content by path relative to /workspace
     * @return SandboxWorkspaceManifest
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function createWorkspaceFromFiles(string $workspaceKey, array $contentsByPath): SandboxWorkspaceManifest
    {
        static::requireValidWorkspaceKey($workspaceKey);
        $lockToken = $this->waitForLock('sync.' . $workspaceKey);
        try {
            $this->discardSession($workspaceKey);
            $this->forgetUnsavedMarks($workspaceKey);
            $sandboxWorkspaceManifest = $this->getWorkspaceStore()->createWorkspaceFromFiles($workspaceKey, $contentsByPath);
            $this->cacheManifestAfterWrite($workspaceKey, $sandboxWorkspaceManifest);
        } finally {
            $this->getCache()->releaseLock($this->buildCacheKey('lock', 'sync.' . $workspaceKey), $lockToken);
        }
        return $sandboxWorkspaceManifest;
    }

    /**
     * Removes the workspace of the key entirely: the session ends without a save, the store forgets manifest and
     * contents.
     *
     * @param string $workspaceKey
     * @return int the store objects removed
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function deleteWorkspace(string $workspaceKey): int
    {
        static::requireValidWorkspaceKey($workspaceKey);
        // under the save lock: a flush process still running for the key finishes first and finds no session after
        $lockToken = $this->waitForLock('sync.' . $workspaceKey);
        try {
            $this->discardSession($workspaceKey);
            $this->forgetUnsavedMarks($workspaceKey);
            $removedObjects = $this->getWorkspaceStore()->deleteWorkspace($workspaceKey);
            $this->cacheManifestAfterWrite($workspaceKey, null);
            return $removedObjects;
        } finally {
            $this->getCache()->releaseLock($this->buildCacheKey('lock', 'sync.' . $workspaceKey), $lockToken);
        }
    }

    /**
     * Forgets the unsaved mark and the seen state of a key: after a full save before a release, or when the key is
     * made new or removed.
     *
     * @param string $workspaceKey
     * @return void
     */
    protected function forgetUnsavedMarks(string $workspaceKey): void
    {
        $this->getCache()->delete($this->buildCacheKey('dirty', $workspaceKey));
        $this->getCache()->delete($this->buildCacheKey('seenManifest', $workspaceKey));
    }

    /**
     * Whether this key was ever used here: a session is held, or a manifest was read, saved or made for it (every
     * claim, save and creation caches the manifest). False for a key no call touched, so its cleanup can skip the store.
     *
     * @param string $workspaceKey
     * @return bool
     */
    public function isWorkspaceKnown(string $workspaceKey): bool
    {
        return $this->findSession($workspaceKey) !== null || $this->readCachedManifest($workspaceKey) !== null;
    }

    /**
     * Ends the session of the key on its runner without saving it. A session already gone is only forgotten.
     *
     * @param string $workspaceKey
     * @return void
     * @throws InternalErrorException
     */
    protected function discardSession(string $workspaceKey): void
    {
        $sandboxSession = $this->findSession($workspaceKey);
        if ($sandboxSession === null) {
            return;
        }
        try {
            $this->getSandboxRunnerClientService()->releaseSession($sandboxSession->runnerAddress, $sandboxSession->sessionId);
        } catch (SandboxRunnerException $sandboxRunnerException) {
            if (!$sandboxRunnerException->isSessionGone()) {
                throw $sandboxRunnerException;
            }
        } finally {
            $this->forgetSession($workspaceKey);
        }
    }

    /**
     * The sweep of the worker loop (`app:agent-sandbox:sessions --sweep`, every minute): saves every session whose
     * unsaved mark is overdue (its flush process died), then saves and releases the idle ones. It runs far inside the
     * runner's own idle release, so a change whose PHP process died is saved even when no call follows.
     *
     * @return SandboxSweepResult
     * @throws InternalErrorException
     */
    public function sweepWorkspaces(): SandboxSweepResult
    {
        $sandboxSweepResult = new SandboxSweepResult();
        foreach ($this->findSessions() as $sandboxSession) {
            $dirtyMark = $this->findDirtyMark($sandboxSession->workspaceKey);
            if ($dirtyMark !== null && $dirtyMark->isOlderThan(static::FLUSH_OVERDUE_SECONDS)) {
                $this->flushPendingChanges($sandboxSession->workspaceKey);
                $sandboxSweepResult->flushedWorkspaceKeys[] = $sandboxSession->workspaceKey;
            }
        }
        $sandboxSweepResult->releasedWorkspaceKeys = $this->releaseIdleSessions();
        return $sandboxSweepResult;
    }

    /**
     * Saves and releases every session idle for longer than `AI.Sandbox.workspaces.idleReleaseSeconds`.
     *
     * @return string[] the released workspace keys
     * @throws InternalErrorException
     */
    public function releaseIdleSessions(): array
    {
        $idleReleaseSeconds = (int)(Config::get('AI.Sandbox.workspaces.idleReleaseSeconds') ?? 900);
        $releasedWorkspaceKeys = [];
        foreach ($this->findSessions() as $sandboxSession) {
            if ($sandboxSession->lastUsedAt > time() - $idleReleaseSeconds) {
                continue;
            }
            $workspaceKey = $sandboxSession->workspaceKey;
            $lockToken = $this->getCache()->acquireLock($this->buildCacheKey('lock', 'claim.' . $workspaceKey), static::LOCK_SECONDS);
            if ($lockToken === null) {
                continue;
            }
            try {
                if ($this->releaseSession($workspaceKey)) {
                    $releasedWorkspaceKeys[] = $workspaceKey;
                }
            } finally {
                $this->getCache()->releaseLock($this->buildCacheKey('lock', 'claim.' . $workspaceKey), $lockToken);
            }
        }
        return $releasedWorkspaceKeys;
    }

    /**
     * The save step after a call that may have changed files. With `AI.Sandbox.workspaces.uploadInBackground` (the
     * default) only the fast part runs here ({@see recordWorkspaceChanges()}); the store upload follows in the flush
     * process ({@see flushPendingChanges()}). Without it the call saves to the store itself ({@see flushWorkspace()}).
     *
     * @param SandboxSession $sandboxSession
     * @param string|null $conversationKey the conversation whose call changed the files
     * @return SandboxWorkspaceSyncResult
     * @throws InternalErrorException
     */
    public function syncWorkspace(SandboxSession $sandboxSession, ?string $conversationKey = null): SandboxWorkspaceSyncResult
    {
        if (!$this->isUploadInBackground()) {
            return $this->flushWorkspace($sandboxSession, $conversationKey);
        }
        return $this->recordWorkspaceChanges($sandboxSession, $conversationKey);
    }

    /**
     * The fast save step: what changed since the workspace was last seen (by sha256, from the runner's manifest), no
     * store call. With a change the seen state is the runner's, the workspace is marked dirty with a fresh token and
     * a flush process starts. Until the flush ends the runner's session holds the only copy of the change.
     *
     * @param SandboxSession $sandboxSession
     * @param string|null $conversationKey
     * @return SandboxWorkspaceSyncResult
     * @throws InternalErrorException
     */
    public function recordWorkspaceChanges(SandboxSession $sandboxSession, ?string $conversationKey): SandboxWorkspaceSyncResult
    {
        $startedAt = hrtime(true);
        $workspaceKey = $sandboxSession->workspaceKey;
        $phaseStartedAt = hrtime(true);
        $runnerManifest = $this->getSandboxRunnerClientService()->readManifest($sandboxSession->runnerAddress, $sandboxSession->sessionId);
        $this->addCallTiming('manifestMs', $phaseStartedAt);
        $phaseStartedAt = hrtime(true);
        $seenManifest = $this->findCurrentManifest($workspaceKey);
        $this->addCallTiming('savedManifestMs', $phaseStartedAt);
        $nextManifest = new SandboxWorkspaceManifest();
        $sandboxWorkspaceSyncResult = $this->compareRunnerManifest($runnerManifest, $seenManifest, $nextManifest, null, $conversationKey);
        if ($sandboxWorkspaceSyncResult->hasChanges()) {
            $phaseStartedAt = hrtime(true);
            $this->getCache()->set($this->buildCacheKey('seenManifest', $workspaceKey), $this->buildCacheableManifest($nextManifest), static::MANIFEST_CACHE_SECONDS);
            $this->getCache()->set($this->buildCacheKey('dirty', $workspaceKey), SandboxDirtyMark::createNow($conversationKey)->toCachedValue(), static::SESSION_CACHE_SECONDS);
            $this->addCallTiming('dirtyMarkMs', $phaseStartedAt);
            $phaseStartedAt = hrtime(true);
            $this->startFlushProcess($workspaceKey);
            $this->addCallTiming('flushStartMs', $phaseStartedAt);
        }
        $sandboxWorkspaceSyncResult->durationMilliseconds = (int)((hrtime(true) - $startedAt) / 1_000_000);
        return $sandboxWorkspaceSyncResult;
    }

    /**
     * Saves what the session holds and the store does not, until the workspace is clean: one flushWorkspace() always
     * (so a flush started after a call sees that call's files even when an earlier flush cleared the mark), then
     * again while a newer mark stands. The mark is cleared only when it is still the one the round began with. A
     * workspace whose session is gone while marked lost the unsaved change: that is logged.
     *
     * @param string $workspaceKey
     * @return int the save rounds run
     * @throws InternalErrorException
     */
    public function flushPendingChanges(string $workspaceKey): int
    {
        $rounds = 0;
        do {
            $dirtyMark = $this->findDirtyMark($workspaceKey);
            $sandboxSession = $this->findSession($workspaceKey);
            if ($sandboxSession === null) {
                if ($dirtyMark !== null) {
                    // data loss: an error, so it reaches the main log and the sandbox log
                    $lostChangeMessage = sprintf('sandboxWorkspace.flush workspace=%s: the runner session ended before its changes since %s UTC were saved; those changes are lost', $workspaceKey, gmdate('Y-m-d H:i:s', $dirtyMark->since));
                    DDDService::instance()->getLogger()->error($lostChangeMessage);
                    if ($this->getTimingLogger() !== DDDService::instance()->getLogger()) {
                        $this->getTimingLogger()->error($lostChangeMessage);
                    }
                    $this->getCache()->delete($this->buildCacheKey('dirty', $workspaceKey));
                }
                return $rounds;
            }
            $this->flushWorkspace($sandboxSession);
            $rounds++;
            $markAfterRound = $this->findDirtyMark($workspaceKey);
            if ($markAfterRound === null) {
                return $rounds;
            }
            if ($dirtyMark !== null && $markAfterRound->token === $dirtyMark->token) {
                $this->getCache()->delete($this->buildCacheKey('dirty', $workspaceKey));
                return $rounds;
            }
        } while ($rounds < static::MAX_FLUSH_ROUNDS);
        return $rounds;
    }

    /**
     * Flushes before a call when the store lags behind in a way the call must not see: changes of ANOTHER
     * conversation, or a mark older than FLUSH_OVERDUE_SECONDS (its flush process died with its PHP process).
     *
     * @param string $workspaceKey
     * @param string|null $conversationKey the conversation of the coming call
     * @return void
     * @throws InternalErrorException
     */
    public function flushBeforeCall(string $workspaceKey, ?string $conversationKey): void
    {
        $dirtyMark = $this->findDirtyMark($workspaceKey);
        if ($dirtyMark === null) {
            return;
        }
        if ($dirtyMark->conversationKey !== $conversationKey || $dirtyMark->isOlderThan(static::FLUSH_OVERDUE_SECONDS)) {
            $startedAt = hrtime(true);
            $this->flushPendingChanges($workspaceKey);
            $this->addCallTiming('flushBeforeCallMs', $startedAt);
        }
    }

    /**
     * @param string $workspaceKey
     * @return SandboxDirtyMark|null the mark of unsaved changes
     */
    public function findDirtyMark(string $workspaceKey): ?SandboxDirtyMark
    {
        return SandboxDirtyMark::fromCachedValue($this->getCache()->get($this->buildCacheKey('dirty', $workspaceKey)));
    }

    /**
     * The manifest as the store holds it now, read past the cache (a check that the flush reached the store).
     *
     * @param string $workspaceKey
     * @return SandboxWorkspaceManifest
     * @throws InternalErrorException
     */
    public function getSavedManifestFromStore(string $workspaceKey): SandboxWorkspaceManifest
    {
        return $this->getWorkspaceStore()->readManifest($workspaceKey);
    }

    /**
     * The workspace as last seen: the runner's state after the last recorded call, else the saved manifest.
     *
     * @param string $workspaceKey
     * @return SandboxWorkspaceManifest
     * @throws InternalErrorException
     */
    public function findCurrentManifest(string $workspaceKey): SandboxWorkspaceManifest
    {
        $seenManifest = $this->getCache()->get($this->buildCacheKey('seenManifest', $workspaceKey));
        return is_array($seenManifest) ? SandboxWorkspaceManifest::fromArray($seenManifest) : $this->findSavedManifest($workspaceKey);
    }

    /**
     * Compares the runner's manifest with the saved one by sha256, stores new contents and writes the next manifest.
     * One save of a workspace at a time (lock); a file above `maxSyncedFileBytes` stays in the session only. A content
     * is stored under the sha256 of the bytes read, so a file changed during the save never leaves a manifest entry
     * whose content is missing.
     *
     * @param SandboxSession $sandboxSession
     * @param string|null $conversationKey
     * @return SandboxWorkspaceSyncResult what changed against the saved manifest
     * @throws InternalErrorException
     */
    public function flushWorkspace(SandboxSession $sandboxSession, ?string $conversationKey = null): SandboxWorkspaceSyncResult
    {
        $startedAt = hrtime(true);
        $workspaceKey = $sandboxSession->workspaceKey;
        // who made a new file: the call's conversation, else the conversation of the open mark (a flush process)
        $conversationKey ??= $this->findDirtyMark($workspaceKey)?->conversationKey;
        // the seen manifest names the conversation of files the write-behind recorded before this flush
        $attributionManifest = $this->findCurrentManifest($workspaceKey);
        $lockToken = $this->waitForLock('sync.' . $workspaceKey);
        $this->addCallTiming('syncLockMs', $startedAt);
        try {
            $sandboxWorkspaceStore = $this->getWorkspaceStore();
            $phaseStartedAt = hrtime(true);
            $runnerManifest = $this->getSandboxRunnerClientService()->readManifest($sandboxSession->runnerAddress, $sandboxSession->sessionId);
            $this->addCallTiming('manifestMs', $phaseStartedAt);
            $phaseStartedAt = hrtime(true);
            $savedManifest = $this->findSavedManifest($workspaceKey);
            $this->addCallTiming('savedManifestMs', $phaseStartedAt);
            $nextManifest = new SandboxWorkspaceManifest();
            $sandboxWorkspaceSyncResult = $this->compareRunnerManifest($runnerManifest, $savedManifest, $nextManifest, $sandboxSession, $conversationKey, $attributionManifest);
            $activityMoved = $nextManifest->mergeActivity($this->readActivity($workspaceKey));
            if ($sandboxWorkspaceSyncResult->hasChanges() || $activityMoved) {
                $nextManifest->version = $savedManifest->version + 1;
                $nextManifest->savedAt = gmdate('Y-m-d\TH:i:s\Z');
                $phaseStartedAt = hrtime(true);
                $sandboxWorkspaceStore->writeManifest($workspaceKey, $nextManifest);
                $this->addCallTiming('manifestWriteMs', $phaseStartedAt);
                $phaseStartedAt = hrtime(true);
                $this->cacheManifestAfterWrite($workspaceKey, $nextManifest);
                $this->addCallTiming('manifestCacheMs', $phaseStartedAt);
            }
        } finally {
            $this->getCache()->releaseLock($this->buildCacheKey('lock', 'sync.' . $workspaceKey), $lockToken);
        }
        $sandboxWorkspaceSyncResult->durationMilliseconds = (int)((hrtime(true) - $startedAt) / 1_000_000);
        return $sandboxWorkspaceSyncResult;
    }

    /**
     * Fills $nextManifest from the runner's manifest against a base manifest and answers the changes. With a session to
     * store from, each new or changed content is read from the runner and written to the store (under the sha256 of
     * the bytes read); without, the runner's entries are taken as they are (the fast step).
     *
     * @param array<string, mixed> $runnerManifest the runner's manifest answer
     * @param SandboxWorkspaceManifest $baseManifest
     * @param SandboxWorkspaceManifest $nextManifest filled here
     * @param SandboxSession|null $storeFromSession null = no store writes
     * @param string|null $conversationKey
     * @param SandboxWorkspaceManifest|null $attributionManifest who created a file the base does not know yet
     * @return SandboxWorkspaceSyncResult
     * @throws InternalErrorException
     */
    protected function compareRunnerManifest(
        array $runnerManifest,
        SandboxWorkspaceManifest $baseManifest,
        SandboxWorkspaceManifest $nextManifest,
        ?SandboxSession $storeFromSession,
        ?string $conversationKey = null,
        ?SandboxWorkspaceManifest $attributionManifest = null
    ): SandboxWorkspaceSyncResult {
        $nextManifest->version = $baseManifest->version;
        $nextManifest->savedAt = $baseManifest->savedAt;
        $nextManifest->conversationsLastActiveAt = $baseManifest->conversationsLastActiveAt;
        $nextManifest->files = new SandboxWorkspaceManifestFiles();
        $now = time();
        $realConversationKey = $conversationKey !== null && $conversationKey !== '' && $conversationKey !== static::CONVERSATION_KEY_WITHOUT_CONVERSATION ? $conversationKey : null;
        $sandboxWorkspaceSyncResult = new SandboxWorkspaceSyncResult();
        $maxSyncedFileBytes = (int)(Config::get('AI.Sandbox.workspaces.maxSyncedFileBytes') ?? 104857600);
        $storedHashes = [];
        foreach ((array)($runnerManifest['files'] ?? []) as $runnerEntry) {
            if (!is_array($runnerEntry)) {
                continue;
            }
            $path = (string)($runnerEntry['path'] ?? '');
            $sha256 = (string)($runnerEntry['sha256'] ?? '');
            $size = (int)($runnerEntry['size'] ?? 0);
            if ($path === '' || $sha256 === '' || static::isExcludedPath($path)) {
                continue;
            }
            $baseFile = $baseManifest->files->findByPath($path);
            if ($baseFile !== null && $baseFile->sha256 === $sha256) {
                $nextManifest->files->add($baseFile);
                continue;
            }
            if ($size > $maxSyncedFileBytes) {
                $sandboxWorkspaceSyncResult->addChange($path, SandboxWorkspaceSyncResult::CHANGE_NOT_SAVED, $size);
                if ($baseFile !== null) {
                    $nextManifest->files->add($baseFile);
                }
                continue;
            }
            if ($storeFromSession !== null && !$baseManifest->holdsContent($sha256) && !isset($storedHashes[$sha256])) {
                $phaseStartedAt = hrtime(true);
                $bytes = $this->getSandboxRunnerClientService()->readFileBytes($storeFromSession->runnerAddress, $storeFromSession->sessionId, $path);
                $this->addCallTiming('uploadReadMs', $phaseStartedAt);
                $sha256 = hash('sha256', $bytes);
                $size = strlen($bytes);
                $phaseStartedAt = hrtime(true);
                $this->getWorkspaceStore()->writeBlob($storeFromSession->workspaceKey, $sha256, $bytes);
                $this->addCallTiming('uploadMs', $phaseStartedAt);
                $storedHashes[$sha256] = true;
                $sandboxWorkspaceSyncResult->uploadedContents++;
                $sandboxWorkspaceSyncResult->uploadedBytes += $size;
            }
            $nextFile = new SandboxWorkspaceManifestFile();
            $nextFile->path = $path;
            $nextFile->sha256 = $sha256;
            $nextFile->size = $size;
            $nextFile->mtime = (string)($runnerEntry['mtime'] ?? '');
            $nextFile->mode = (string)($runnerEntry['mode'] ?? '0644');
            $nextFile->lastReadAt = $baseFile?->lastReadAt;
            $nextFile->handedOutAt = $baseFile?->handedOutAt;
            $nextFile->createdByConversation = $baseFile?->createdByConversation
                ?? $attributionManifest?->files->findByPath($path)?->createdByConversation
                ?? $realConversationKey;
            $nextManifest->files->add($nextFile);
            $sandboxWorkspaceSyncResult->addChange(
                $path,
                $baseFile === null ? SandboxWorkspaceSyncResult::CHANGE_NEW : SandboxWorkspaceSyncResult::CHANGE_CHANGED,
                $size,
                SandboxWorkspaceZones::buildExpiryLabelOfChange($path, $now)
            );
        }
        if ($realConversationKey !== null && $sandboxWorkspaceSyncResult->hasChanges()) {
            $nextManifest->conversationsLastActiveAt[$realConversationKey] = gmdate('Y-m-d\TH:i:s\Z', $now);
        }
        foreach ($baseManifest->files as $path => $baseFile) {
            $path = (string)$path;
            if (!$nextManifest->files->hasPath($path) && !static::isExcludedPath($path) && !$sandboxWorkspaceSyncResult->namesPath($path)) {
                $sandboxWorkspaceSyncResult->addChange($path, SandboxWorkspaceSyncResult::CHANGE_DELETED, $baseFile->size);
            }
        }
        return $sandboxWorkspaceSyncResult;
    }

    /**
     * Starts `app:agent-sandbox:sessions --flush=<key>` detached from this process: it saves the change of the call to
     * the store while the call answers. The key is a plain key (checked at the claim), every argument is escaped.
     *
     * @param string $workspaceKey
     * @return void
     */
    protected function startFlushProcess(string $workspaceKey): void
    {
        if (preg_match(static::WORKSPACE_KEY_PATTERN, $workspaceKey) !== 1) {
            return;
        }
        $consolePath = DDDService::instance()->getConsoleDir(false);
        exec(sprintf(
            'nohup %s %s %s --flush=%s > /dev/null 2>&1 &',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($consolePath),
            escapeshellarg(static::FLUSH_COMMAND_NAME),
            escapeshellarg($workspaceKey)
        ));
    }

    /**
     * @return bool Whether calls leave the store upload to a flush process (AI.Sandbox.workspaces.uploadInBackground)
     */
    protected function isUploadInBackground(): bool
    {
        return $this->uploadInBackgroundOverride ?? (bool)(Config::get('AI.Sandbox.workspaces.uploadInBackground') ?? true);
    }

    /**
     * @return LoggerInterface the log of the sandbox's timing and info lines (a host may override it with a channel of its own)
     */
    public function getTimingLogger(): LoggerInterface
    {
        return DDDService::instance()->getLogger();
    }

    /**
     * @param bool|null $uploadInBackground a test's choice; null = back to AI.Sandbox.workspaces.uploadInBackground
     * @return void
     */
    public function setUploadInBackground(?bool $uploadInBackground): void
    {
        $this->uploadInBackgroundOverride = $uploadInBackground;
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function isExcludedPath(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, static::EXCLUDED_PATH_SEGMENTS, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param SandboxSession $sandboxSession
     * @param string $conversationKey
     * @param string $command
     * @param int $timeoutSeconds
     * @param bool $background
     * @return SandboxCommandRun without its sync result
     * @throws SandboxRunnerException
     * @throws ConflictException every shell of the workspace is busy
     */
    protected function runCommandInConversationShell(SandboxSession $sandboxSession, string $conversationKey, string $command, int $timeoutSeconds, bool $background): SandboxCommandRun
    {
        $sandboxRunnerClientService = $this->getSandboxRunnerClientService();
        $cwd = $sandboxSession->cwdsByConversation[$conversationKey] ?? $this->prepareWorkDirectory($sandboxSession, $conversationKey);
        foreach ($sandboxSession->getShellNamesToTryFor($conversationKey) as $shellName) {
            $startedAt = hrtime(true);
            try {
                $execAnswer = $sandboxRunnerClientService->execute($sandboxSession->runnerAddress, $sandboxSession->sessionId, $command, $shellName, $cwd, $timeoutSeconds, $background);
            } catch (SandboxRunnerException $sandboxRunnerException) {
                if ($sandboxRunnerException->runnerErrorCode === SandboxRunnerException::SHELL_BUSY) {
                    continue;
                }
                throw $sandboxRunnerException;
            }
            $sandboxCommandRun = new SandboxCommandRun();
            $sandboxCommandRun->execAnswer = $execAnswer;
            $sandboxCommandRun->runnerMilliseconds = (int)((hrtime(true) - $startedAt) / 1_000_000);
            $this->addCallTiming('runnerMs', $startedAt);
            $sandboxSession->shellNamesByConversation[$conversationKey] = $shellName;
            if (!$background && is_string($execAnswer['cwd'] ?? null) && $execAnswer['cwd'] !== '') {
                $sandboxSession->cwdsByConversation[$conversationKey] = $execAnswer['cwd'];
            }
            $this->touchSession($sandboxSession);
            return $sandboxCommandRun;
        }
        throw new ConflictException('Every shell of this workspace is busy with another command. Wait for it, or start this one with background: true.');
    }

    /**
     * Frees room in a full workspace: removes the oldest scratch of OTHER conversations (work/ of another
     * conversation, root files another conversation made) in the session until `$bytesNeeded` are free, and saves.
     * Never lib/, NOTES.md, out/ or inbox/, never the scratch of the calling conversation.
     *
     * @param string $workspaceKey
     * @param string $conversationKey the calling conversation
     * @param int $bytesNeeded
     * @return SandboxFreedScratch what was removed
     * @throws ConflictException
     * @throws InternalErrorException
     */
    public function freeOldestScratchOfOtherConversations(string $workspaceKey, string $conversationKey, int $bytesNeeded): SandboxFreedScratch
    {
        $sandboxWorkspaceManifest = $this->findCurrentManifest($workspaceKey);
        $sandboxWorkspaceManifest->mergeActivity($this->readActivity($workspaceKey));
        $expiryByPath = [];
        foreach ($sandboxWorkspaceManifest->files as $path => $manifestFile) {
            $path = (string)$path;
            if (!SandboxWorkspaceZones::isScratch($path)) {
                continue;
            }
            $owningConversationKey = SandboxWorkspaceZones::findConversationOfWorkPath($path) ?? $manifestFile->createdByConversation;
            if ($owningConversationKey === null || $owningConversationKey === $conversationKey) {
                continue;
            }
            $expiryByPath[$path] = (int)SandboxWorkspaceZones::findExpiryOfFile($path, $manifestFile, $sandboxWorkspaceManifest->conversationsLastActiveAt);
        }
        asort($expiryByPath);
        $sandboxFreedScratch = new SandboxFreedScratch();
        foreach (array_keys($expiryByPath) as $path) {
            if ($sandboxFreedScratch->removedBytes >= $bytesNeeded) {
                break;
            }
            $sandboxFreedScratch->removedPaths[] = (string)$path;
            $sandboxFreedScratch->removedBytes += (int)$sandboxWorkspaceManifest->files->findByPath((string)$path)?->size;
        }
        if ($sandboxFreedScratch->removedPaths === []) {
            return $sandboxFreedScratch;
        }
        $removedPaths = $sandboxFreedScratch->removedPaths;
        $sandboxSession = $this->ensureSession($workspaceKey);
        $this->runOnSession($workspaceKey, $sandboxSession, fn(SandboxSession $session): array => $this->getSandboxRunnerClientService()->execute(
            $session->runnerAddress,
            $session->sessionId,
            'rm -f -- ' . implode(' ', array_map('escapeshellarg', $removedPaths)),
            SandboxSession::FILES_SHELL_NAME,
            '/workspace',
            30,
            false
        ));
        $this->syncWorkspace($this->lastSessionOfCall ?? $sandboxSession, $conversationKey);
        $this->getTimingLogger()->info(sprintf(
            'sandboxWorkspace.quotaPressure workspace=%s conversation=%s removed %d scratch files of other conversations (%s)',
            $workspaceKey,
            $conversationKey,
            count($removedPaths),
            SandboxWorkspaceSyncResult::formatBytes($sandboxFreedScratch->removedBytes)
        ));
        return $sandboxFreedScratch;
    }

    /**
     * Deletes the saved files whose lifetime is over ({@see SandboxWorkspaceZones}), per workspace of the store, and
     * under quota pressure the oldest scratch beyond that (work/ and the root first, then nothing else: out/, inbox/
     * keep their 30 days, lib/ and NOTES.md are never touched). A workspace with a bound session or an unsaved change
     * is left for the next run: its runner copy would bring the files back. One info line per workspace.
     *
     * @param bool $dryRun only report what would go
     * @param int|null $now Unix time (a test sets it)
     * @return SandboxWorkspaceExpiryReports
     * @throws InternalErrorException
     */
    public function expireWorkspaces(bool $dryRun, ?int $now = null): SandboxWorkspaceExpiryReports
    {
        $now ??= time();
        $sandboxWorkspaceStore = $this->getWorkspaceStore();
        $quotaBytes = $this->getStoredWorkspaceQuotaBytes();
        $expiryReports = new SandboxWorkspaceExpiryReports();
        foreach ($sandboxWorkspaceStore->listWorkspaceKeys() as $workspaceKey) {
            if (str_starts_with($workspaceKey, static::RESERVED_WORKSPACE_KEY_PREFIX)) {
                // the preload and other reserved keys never expire: the seed owns them
                continue;
            }
            $expiryReport = new SandboxWorkspaceExpiryReport();
            $expiryReport->workspaceKey = $workspaceKey;
            $expiryReports->add($expiryReport);
            if ($this->findSession($workspaceKey) !== null || $this->findDirtyMark($workspaceKey) !== null) {
                $expiryReport->skippedReason = 'session open';
                $this->getTimingLogger()->info(sprintf('sandboxWorkspace.expire workspace=%s skipped: a session is bound (next run)', $workspaceKey));
                continue;
            }
            $lockToken = $this->waitForLock('sync.' . $workspaceKey);
            try {
                $sandboxWorkspaceManifest = $sandboxWorkspaceStore->readManifest($workspaceKey);
                $activityMoved = $sandboxWorkspaceManifest->mergeActivity($this->readActivity($workspaceKey));
                $remainingFiles = new SandboxWorkspaceManifestFiles();
                foreach ($sandboxWorkspaceManifest->files as $path => $manifestFile) {
                    $expiresAt = SandboxWorkspaceZones::findExpiryOfFile((string)$path, $manifestFile, $sandboxWorkspaceManifest->conversationsLastActiveAt);
                    if ($expiresAt !== null && $expiresAt <= $now) {
                        $expiryReport->expiredPaths[] = (string)$path;
                        $expiryReport->freedBytes += $manifestFile->size;
                        continue;
                    }
                    $remainingFiles->add($manifestFile);
                }
                $remainingBytes = $remainingFiles->sumBytes();
                if ($remainingBytes > $quotaBytes) {
                    // under quota pressure the oldest scratch goes first; never lib/ (nor out/, inbox/ before their time)
                    $scratchExpiryByPath = [];
                    foreach ($remainingFiles as $path => $manifestFile) {
                        if (SandboxWorkspaceZones::isScratch((string)$path)) {
                            $scratchExpiryByPath[(string)$path] = (int)SandboxWorkspaceZones::findExpiryOfFile((string)$path, $manifestFile, $sandboxWorkspaceManifest->conversationsLastActiveAt);
                        }
                    }
                    asort($scratchExpiryByPath);
                    foreach (array_keys($scratchExpiryByPath) as $path) {
                        if ($remainingBytes <= $quotaBytes) {
                            break;
                        }
                        $pressureFile = $remainingFiles->findByPath((string)$path);
                        $expiryReport->pressurePaths[] = (string)$path;
                        $expiryReport->freedBytes += (int)$pressureFile?->size;
                        $remainingBytes -= (int)$pressureFile?->size;
                        $remainingFiles->removeByPath((string)$path);
                    }
                }
                $expiryReport->keptFiles = $remainingFiles->count();
                $removedPaths = array_merge($expiryReport->expiredPaths, $expiryReport->pressurePaths);
                if (!$dryRun && ($removedPaths !== [] || $activityMoved)) {
                    $removedHashes = [];
                    foreach ($removedPaths as $path) {
                        $removedHashes[(string)$sandboxWorkspaceManifest->files->findByPath($path)?->sha256] = true;
                    }
                    $sandboxWorkspaceManifest->files = $remainingFiles;
                    $sandboxWorkspaceManifest->version++;
                    $sandboxWorkspaceManifest->savedAt = gmdate('Y-m-d\TH:i:s\Z', $now);
                    $sandboxWorkspaceStore->writeManifest($workspaceKey, $sandboxWorkspaceManifest);
                    $this->cacheManifestAfterWrite($workspaceKey, $sandboxWorkspaceManifest);
                    $this->getCache()->delete($this->buildCacheKey('seenManifest', $workspaceKey));
                    // the manifest first: a content is deleted only once no saved entry names it
                    foreach (array_keys($removedHashes) as $sha256) {
                        if ($sha256 !== '' && !$sandboxWorkspaceManifest->holdsContent((string)$sha256)) {
                            $sandboxWorkspaceStore->deleteBlob($workspaceKey, (string)$sha256);
                        }
                    }
                }
                $remainingManifest = new SandboxWorkspaceManifest();
                $remainingManifest->files = $remainingFiles;
                $expiryReport->libBytes = $remainingManifest->sumBytesOfZone(SandboxWorkspaceZones::ZONE_LIB);
            } finally {
                $this->getCache()->releaseLock($this->buildCacheKey('lock', 'sync.' . $workspaceKey), $lockToken);
            }
            $this->getTimingLogger()->info(sprintf(
                'sandboxWorkspace.expire workspace=%s %s: %d expired, %d under quota pressure, %s freed, %d files kept, lib/ %s of %s%s',
                $workspaceKey,
                $dryRun ? 'dry run' : 'done',
                count($expiryReport->expiredPaths),
                count($expiryReport->pressurePaths),
                SandboxWorkspaceSyncResult::formatBytes($expiryReport->freedBytes),
                $expiryReport->keptFiles,
                SandboxWorkspaceSyncResult::formatBytes($expiryReport->libBytes),
                SandboxWorkspaceSyncResult::formatBytes(SandboxWorkspaceZones::LIB_QUOTA_BYTES),
                $expiryReport->libBytes > SandboxWorkspaceZones::LIB_QUOTA_BYTES ? ' (OVER the lib/ quota: a human decides what goes)' : ''
            ));
        }
        return $expiryReports;
    }

    /**
     * @return int Bytes of saved files a workspace keeps before the expiry removes the oldest scratch
     */
    protected function getStoredWorkspaceQuotaBytes(): int
    {
        return (int)(Config::get('AI.Sandbox.workspaces.storedWorkspaceQuotaBytes') ?? static::DEFAULT_STORED_WORKSPACE_QUOTA_BYTES);
    }

    /**
     * The cwd a conversation's shell starts in: its scratch folder `work/<conversationKey>/` (created when missing);
     * `/workspace` for a call without a conversation.
     *
     * @param SandboxSession $sandboxSession
     * @param string $conversationKey
     * @return string
     * @throws SandboxRunnerException
     */
    protected function prepareWorkDirectory(SandboxSession $sandboxSession, string $conversationKey): string
    {
        if ($conversationKey === static::CONVERSATION_KEY_WITHOUT_CONVERSATION || preg_match('/^[A-Za-z0-9._-]+$/', $conversationKey) !== 1) {
            return '/workspace';
        }
        $workDirectory = SandboxWorkspaceZones::buildWorkDirectoryOfConversation($conversationKey);
        $startedAt = hrtime(true);
        $this->getSandboxRunnerClientService()->execute(
            $sandboxSession->runnerAddress,
            $sandboxSession->sessionId,
            'mkdir -p ' . escapeshellarg($workDirectory),
            SandboxSession::FILES_SHELL_NAME,
            '/workspace',
            10,
            false
        );
        $this->addCallTiming('workDirectoryMs', $startedAt);
        return '/workspace/' . $workDirectory;
    }

    /**
     * Notes that a conversation worked in the workspace and which files it read, in the cache (cheap per call); the
     * next save and the expiry merge it into the manifest ({@see SandboxWorkspaceManifest::mergeActivity()}).
     *
     * @param string $workspaceKey
     * @param string $conversationKey
     * @param string[] $readPaths
     * @return void
     */
    public function noteActivity(string $workspaceKey, string $conversationKey, array $readPaths = []): void
    {
        $activityRecord = $this->readActivity($workspaceKey);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($conversationKey !== static::CONVERSATION_KEY_WITHOUT_CONVERSATION) {
            $activityRecord->conversationsLastActiveAt[$conversationKey] = $now;
        }
        foreach (array_slice(array_unique($readPaths), 0, static::MAX_READ_PATHS_NOTED) as $readPath) {
            $activityRecord->filesLastReadAt[(string)$readPath] = $now;
        }
        $this->getCache()->set($this->buildCacheKey('activity', $workspaceKey), $activityRecord->toCachedValue(), static::ACTIVITY_CACHE_SECONDS);
    }

    /**
     * @param string $workspaceKey
     * @return SandboxWorkspaceActivityRecord
     */
    public function readActivity(string $workspaceKey): SandboxWorkspaceActivityRecord
    {
        return SandboxWorkspaceActivityRecord::fromCachedValue($this->getCache()->get($this->buildCacheKey('activity', $workspaceKey)));
    }

    /**
     * Runs one runner call of the workspace's session and handles a session that is gone or a pod that takes no
     * connection (the call never reached it, so it can be repeated):
     *
     * - the runner no longer knows the session (SESSION_NOT_FOUND), or the pod is gone from the Service (no address):
     *   claim a new session, restore from the store, run again (a change the old session did not save is logged as
     *   lost by the claim);
     * - the pod is still listed but takes no connection: one retry after a pause; when it still takes none and a
     *   change is not saved yet, a tool error (claiming anew would drop it); without one, claim anew and run again.
     *
     * A call on a new session sets {@see $lastCallRestartedSession} (the tool answer says so: shells and cwd are new).
     * Any other refusal, and NO_ANSWER (the call reached the runner and may have run), is thrown as it is.
     *
     * @template T
     * @param string $workspaceKey
     * @param SandboxSession $sandboxSession
     * @param callable(SandboxSession): T $operation
     * @return T
     * @throws SandboxRunnerException
     * @throws ConflictException
     * @throws InternalErrorException
     */
    protected function runOnSession(string $workspaceKey, SandboxSession $sandboxSession, callable $operation): mixed
    {
        $this->lastSessionOfCall = $sandboxSession;
        try {
            return $operation($sandboxSession);
        } catch (SandboxRunnerException $sandboxRunnerException) {
            if (!$sandboxRunnerException->isSessionGone()) {
                throw $sandboxRunnerException;
            }
            $lastException = $sandboxRunnerException;
        }
        if ($lastException->runnerErrorCode === SandboxRunnerException::UNREACHABLE) {
            $unreachableStartedAt = hrtime(true);
            $this->callTimings['unreachableAttempts'] = ($this->callTimings['unreachableAttempts'] ?? 0) + 1;
            if ($this->isRunnerPodGone($sandboxSession)) {
                $this->getTimingLogger()->error(sprintf('sandboxWorkspace.podGone workspace=%s runner=%s: the session\'s pod is no longer in the Service; claiming anew', $workspaceKey, $sandboxSession->runnerAddress));
            } else {
                $this->pauseBeforeRetry();
                try {
                    $answer = $operation($sandboxSession);
                    $this->addCallTiming('unreachableMs', $unreachableStartedAt);
                    return $answer;
                } catch (SandboxRunnerException $retryException) {
                    $this->addCallTiming('unreachableMs', $unreachableStartedAt);
                    if (!$retryException->isSessionGone()) {
                        throw $retryException;
                    }
                    if ($retryException->runnerErrorCode === SandboxRunnerException::UNREACHABLE) {
                        $this->callTimings['unreachableAttempts']++;
                        if ($this->findDirtyMark($workspaceKey) !== null) {
                            throw new ConflictException(static::UNREACHABLE_WITH_UNSAVED_CHANGE_MESSAGE);
                        }
                    }
                }
            }
        }
        $sandboxSession = $this->claimAgainAfterSessionEnded($workspaceKey);
        $this->lastSessionOfCall = $sandboxSession;
        $this->lastCallRestartedSession = true;
        return $operation($sandboxSession);
    }

    /**
     * Whether the session's runner address is no longer among the pods of its pool (the pod was deleted or replaced).
     *
     * @param SandboxSession $sandboxSession
     * @return bool
     * @throws SandboxRunnerException when the Service resolves to no pod at all
     */
    protected function isRunnerPodGone(SandboxSession $sandboxSession): bool
    {
        return !in_array($sandboxSession->runnerAddress, $this->getSandboxRunnerClientService()->resolveRunnerAddresses($sandboxSession->poolName), true);
    }

    /**
     * @return void
     */
    protected function pauseBeforeRetry(): void
    {
        usleep(static::UNREACHABLE_RETRY_PAUSE_MICROSECONDS);
    }

    /**
     * @param string $workspaceKey
     * @return SandboxSession
     * @throws ConflictException
     * @throws InternalErrorException
     */
    protected function claimAgainAfterSessionEnded(string $workspaceKey): SandboxSession
    {
        $this->forgetSession($workspaceKey);
        return $this->ensureSession($workspaceKey);
    }

    /**
     * Claims a runner pod of the workspace's pool and restores its saved files. Tries every pod of the pool; when all
     * are busy, releases the idle sessions once and tries again; then refuses ({@see ALL_SLOTS_BUSY_MESSAGE}).
     *
     * @param string $workspaceKey
     * @return SandboxSession
     * @throws ConflictException
     * @throws InternalErrorException
     */
    protected function claimAndRestore(string $workspaceKey): SandboxSession
    {
        $startedAt = hrtime(true);
        // no session holds the key any more: a mark still standing means its runner session ended before the flush
        // (pod lost); flushPendingChanges() logs that and clears it, and the seen state starts again from the store
        $this->flushPendingChanges($workspaceKey);
        $this->forgetUnsavedMarks($workspaceKey);
        $sandboxSessionRules = $this->getSandboxSessionRulesService()->findRulesOfWorkspace($workspaceKey);
        $phaseStartedAt = hrtime(true);
        $sandboxWorkspaceManifest = $this->findSavedManifest($workspaceKey);
        $this->addCallTiming('claimStoreManifestMs', $phaseStartedAt);
        $phaseStartedAt = hrtime(true);
        $inlineFiles = [];
        $filesToPut = [];
        $inlineBytes = 0;
        $inlineManifestFiles = [];
        foreach ($sandboxWorkspaceManifest->files->getElements() as $manifestFile) {
            if ($inlineBytes + $manifestFile->size <= static::INLINE_RESTORE_BYTES) {
                $inlineManifestFiles[] = $manifestFile;
                $inlineBytes += $manifestFile->size;
                continue;
            }
            $filesToPut[] = $manifestFile;
        }
        // the contents of the inline files at once: one store round trip instead of one per file
        $inlineSha256s = array_map(static fn(SandboxWorkspaceManifestFile $manifestFile): string => $manifestFile->sha256, $inlineManifestFiles);
        $contentsBySha256 = $inlineSha256s !== [] ? $this->getWorkspaceStore()->readBlobs($workspaceKey, $inlineSha256s) : [];
        foreach ($inlineManifestFiles as $manifestFile) {
            $inlineFiles[] = ['path' => $manifestFile->path, 'contentBase64' => base64_encode($contentsBySha256[$manifestFile->sha256]), 'mode' => $manifestFile->mode, 'mtime' => $manifestFile->mtime];
        }
        unset($contentsBySha256);
        // the preloaded content below .preload/, read-only by mode (restored on every claim, never saved)
        $preloadManifest = $this->findSavedManifest(static::PRELOAD_WORKSPACE_KEY);
        $preloadFilesToPut = [];
        if ($preloadManifest->countFiles() > 0) {
            $inlinePreloadFiles = [];
            foreach ($preloadManifest->files->getElements() as $manifestFile) {
                if ($inlineBytes + $manifestFile->size <= static::INLINE_RESTORE_BYTES) {
                    $inlinePreloadFiles[] = $manifestFile;
                    $inlineBytes += $manifestFile->size;
                    continue;
                }
                $preloadFilesToPut[] = $manifestFile;
            }
            $preloadSha256s = array_map(static fn(SandboxWorkspaceManifestFile $manifestFile): string => $manifestFile->sha256, $inlinePreloadFiles);
            $preloadContentsBySha256 = $preloadSha256s !== [] ? $this->getWorkspaceStore()->readBlobs(static::PRELOAD_WORKSPACE_KEY, $preloadSha256s) : [];
            foreach ($inlinePreloadFiles as $manifestFile) {
                $inlineFiles[] = [
                    'path' => static::PRELOAD_DIRECTORY . '/' . $manifestFile->path,
                    'contentBase64' => base64_encode($preloadContentsBySha256[$manifestFile->sha256]),
                    'mode' => static::buildPreloadFileMode($manifestFile),
                    'mtime' => $manifestFile->mtime,
                ];
            }
            unset($preloadContentsBySha256);
        }
        $this->addCallTiming('claimStoreContentsMs', $phaseStartedAt);
        $this->callTimings['claimRestoredFiles'] = $sandboxWorkspaceManifest->countFiles();
        $phaseStartedAt = hrtime(true);
        $sessionEnvironment = $sandboxSessionRules->environment + ['SANDBOX_PRELOAD' => '/workspace/' . static::PRELOAD_DIRECTORY];
        $sandboxRunnerClaim = $this->claimOnAnyRunner($workspaceKey, $sandboxSessionRules->poolName, $sessionEnvironment, $inlineFiles);
        if ($sandboxRunnerClaim === null) {
            $this->releaseIdleSessions();
            $sandboxRunnerClaim = $this->claimOnAnyRunner($workspaceKey, $sandboxSessionRules->poolName, $sessionEnvironment, $inlineFiles);
        }
        if ($sandboxRunnerClaim === null) {
            $this->getTimingLogger()->warning(sprintf('sandboxWorkspace.claim refused workspace=%s pool=%s: every slot is busy', $workspaceKey, $sandboxSessionRules->poolName));
            throw new ConflictException(static::ALL_SLOTS_BUSY_MESSAGE);
        }
        $sandboxSession = new SandboxSession();
        $sandboxSession->workspaceKey = $workspaceKey;
        $sandboxSession->sessionId = $sandboxRunnerClaim->sessionId;
        $sandboxSession->runnerAddress = $sandboxRunnerClaim->runnerAddress;
        $sandboxSession->poolName = $sandboxSessionRules->poolName;
        $sandboxSession->claimedAt = time();
        $sandboxSession->lastUsedAt = time();
        $this->addCallTiming('claimRunnerMs', $phaseStartedAt);
        $phaseStartedAt = hrtime(true);
        foreach ($filesToPut as $manifestFile) {
            $this->getSandboxRunnerClientService()->writeFileBytes(
                $sandboxSession->runnerAddress,
                $sandboxSession->sessionId,
                $manifestFile->path,
                $this->getWorkspaceStore()->readBlob($workspaceKey, $manifestFile->sha256),
                $manifestFile->mode,
                $manifestFile->mtime
            );
        }
        foreach ($preloadFilesToPut as $manifestFile) {
            $this->getSandboxRunnerClientService()->writeFileBytes(
                $sandboxSession->runnerAddress,
                $sandboxSession->sessionId,
                static::PRELOAD_DIRECTORY . '/' . $manifestFile->path,
                $this->getWorkspaceStore()->readBlob(static::PRELOAD_WORKSPACE_KEY, $manifestFile->sha256),
                static::buildPreloadFileMode($manifestFile),
                $manifestFile->mtime
            );
        }
        $this->addCallTiming('claimRestorePutMs', $phaseStartedAt);
        $this->storeSession($sandboxSession);
        if ($preloadManifest->files->hasPath(static::PRELOAD_START_SCRIPT)) {
            $this->runPreloadStartScript($sandboxSession);
        }
        $this->lastCallClaimedSession = true;
        $this->lastClaimMilliseconds = (int)((hrtime(true) - $startedAt) / 1_000_000);
        $this->addCallTiming('claimMs', $startedAt);
        return $sandboxSession;
    }

    /**
     * The mode of a preloaded file: read-only, executable when the seeded file was.
     *
     * @param SandboxWorkspaceManifestFile $manifestFile
     * @return string
     */
    protected static function buildPreloadFileMode(SandboxWorkspaceManifestFile $manifestFile): string
    {
        return (octdec($manifestFile->mode) & 0111) !== 0 ? '0555' : '0444';
    }

    /**
     * Runs the preloaded start script once at the start of a session (in the files shell, cwd /workspace). A failure is
     * logged and does not fail the claim.
     *
     * @param SandboxSession $sandboxSession
     * @return void
     */
    protected function runPreloadStartScript(SandboxSession $sandboxSession): void
    {
        $startedAt = hrtime(true);
        try {
            $execAnswer = $this->getSandboxRunnerClientService()->execute(
                $sandboxSession->runnerAddress,
                $sandboxSession->sessionId,
                'sh ' . escapeshellarg('/workspace/' . static::PRELOAD_DIRECTORY . '/' . static::PRELOAD_START_SCRIPT),
                SandboxSession::FILES_SHELL_NAME,
                '/workspace',
                static::PRELOAD_START_SCRIPT_TIMEOUT_SECONDS,
                false
            );
            if ((int)($execAnswer['exitCode'] ?? 0) !== 0) {
                $this->getTimingLogger()->warning(sprintf('sandboxWorkspace.preloadStart workspace=%s: start.sh exited with %d', $sandboxSession->workspaceKey, (int)($execAnswer['exitCode'] ?? 0)));
            }
        } catch (SandboxRunnerException $sandboxRunnerException) {
            $this->getTimingLogger()->warning(sprintf('sandboxWorkspace.preloadStart workspace=%s failed: %s', $sandboxSession->workspaceKey, $sandboxRunnerException->getMessage()));
        } finally {
            $this->addCallTiming('preloadStartMs', $startedAt);
        }
    }

    /**
     * @param string $workspaceKey
     * @param string $poolName
     * @param string[] $environment by variable name
     * @param array<int, array<string, string>> $inlineFiles the claim's `files` (path, contentBase64, mode, mtime)
     * @return SandboxRunnerClaim|null null when every runner of the pool is busy
     * @throws SandboxRunnerException a runner refused the claim for another reason than being busy
     */
    protected function claimOnAnyRunner(string $workspaceKey, string $poolName, array $environment, array $inlineFiles): ?SandboxRunnerClaim
    {
        $sandboxRunnerClientService = $this->getSandboxRunnerClientService();
        $lastUnreachable = null;
        foreach ($sandboxRunnerClientService->resolveRunnerAddresses($poolName) as $runnerAddress) {
            $attemptStartedAt = hrtime(true);
            try {
                $claimAnswer = $sandboxRunnerClientService->claimSession($runnerAddress, $workspaceKey, $environment, $inlineFiles);
                $sandboxRunnerClaim = new SandboxRunnerClaim();
                $sandboxRunnerClaim->runnerAddress = $runnerAddress;
                $sandboxRunnerClaim->sessionId = (string)($claimAnswer['sessionId'] ?? '');
                $sandboxRunnerClaim->restoredFiles = (int)($claimAnswer['restoredFiles'] ?? 0);
                return $sandboxRunnerClaim;
            } catch (SandboxRunnerException $sandboxRunnerException) {
                if ($sandboxRunnerException->runnerStatusCode === 503) {
                    $this->callTimings['busyAttempts'] = ($this->callTimings['busyAttempts'] ?? 0) + 1;
                    continue;
                }
                if ($sandboxRunnerException->runnerErrorCode === SandboxRunnerException::UNREACHABLE) {
                    $this->callTimings['unreachableAttempts'] = ($this->callTimings['unreachableAttempts'] ?? 0) + 1;
                    $this->addCallTiming('unreachableMs', $attemptStartedAt);
                    $lastUnreachable = $sandboxRunnerException;
                    continue;
                }
                throw $sandboxRunnerException;
            }
        }
        if ($lastUnreachable !== null) {
            throw $lastUnreachable;
        }
        return null;
    }

    /**
     * @return void
     * @throws InternalErrorException
     */
    protected function releaseIdleSessionsWhenDue(): void
    {
        $sweepKey = $this->buildCacheKey('idleSweepAt', 'all');
        if ((int)$this->getCache()->get($sweepKey) > time() - static::IDLE_SWEEP_INTERVAL_SECONDS) {
            return;
        }
        $this->getCache()->set($sweepKey, time(), static::IDLE_SWEEP_INTERVAL_SECONDS * 2);
        $this->releaseIdleSessions();
    }

    /**
     * @param SandboxSession $sandboxSession
     * @return void
     */
    protected function touchSession(SandboxSession $sandboxSession): void
    {
        $startedAt = hrtime(true);
        $sandboxSession->lastUsedAt = time();
        $this->getCache()->set($this->buildCacheKey('session', $sandboxSession->workspaceKey), $sandboxSession->toArray(), static::SESSION_CACHE_SECONDS);
        $this->addCallTiming('sessionCacheMs', $startedAt);
    }

    /**
     * @param SandboxSession $sandboxSession
     * @return void
     */
    protected function storeSession(SandboxSession $sandboxSession): void
    {
        $this->touchSession($sandboxSession);
        $sessionIndex = $this->readSessionIndex();
        if (!in_array($sandboxSession->workspaceKey, $sessionIndex, true)) {
            $sessionIndex[] = $sandboxSession->workspaceKey;
            $this->getCache()->set($this->buildCacheKey('sessionIndex', 'all'), $sessionIndex, static::SESSION_CACHE_SECONDS);
        }
    }

    /**
     * @param string $workspaceKey
     * @return void
     */
    protected function forgetSession(string $workspaceKey): void
    {
        $this->getCache()->delete($this->buildCacheKey('session', $workspaceKey));
        $sessionIndex = array_values(array_diff($this->readSessionIndex(), [$workspaceKey]));
        $this->getCache()->set($this->buildCacheKey('sessionIndex', 'all'), $sessionIndex, static::SESSION_CACHE_SECONDS);
    }

    /**
     * @return string[]
     */
    protected function readSessionIndex(): array
    {
        $sessionIndex = $this->getCache()->get($this->buildCacheKey('sessionIndex', 'all'));
        return is_array($sessionIndex) ? array_values(array_map('strval', $sessionIndex)) : [];
    }

    /**
     * Runs an operation under the save lock of a workspace.
     *
     * @template T
     * @param string $workspaceKey
     * @param callable(): T $operation
     * @return T
     * @throws ConflictException the lock was not free in time
     */
    public function runUnderSaveLock(string $workspaceKey, callable $operation): mixed
    {
        $lockToken = $this->waitForLock('sync.' . $workspaceKey);
        try {
            return $operation();
        } finally {
            $this->getCache()->releaseLock($this->buildCacheKey('lock', 'sync.' . $workspaceKey), $lockToken);
        }
    }

    /**
     * Waits for a lock of the shared cache, at most LOCK_WAIT_SECONDS.
     *
     * @param string $lockName
     * @return string the lock token
     * @throws ConflictException when the wait gave up
     */
    protected function waitForLock(string $lockName): string
    {
        $deadline = microtime(true) + static::LOCK_WAIT_SECONDS;
        do {
            $lockToken = $this->getCache()->acquireLock($this->buildCacheKey('lock', $lockName), static::LOCK_SECONDS);
            if ($lockToken !== null) {
                return $lockToken;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);
        throw new ConflictException('The workspace is busy with another call (claim or save). Try again; nothing was run.');
    }

    /**
     * @param string $kind
     * @param string $name
     * @return string
     */
    protected function buildCacheKey(string $kind, string $name): string
    {
        return 'sandboxWorkspace.' . $kind . '.' . $name;
    }

    /**
     * @return Cache the cache every process of the workspace shares (Redis by default)
     */
    protected function getCache(): Cache
    {
        return Cache::instance($this->cacheGroupOverride ?? (string)(Config::get('AI.Sandbox.runner.cacheGroup') ?? Cache::CACHE_GROUP_REDIS));
    }

    /**
     * @return Cache the cache of the workspace sessions
     */
    public function getWorkspaceCache(): Cache
    {
        return $this->getCache();
    }

    /**
     * @param string|null $cacheGroup a cache group for a test; null = back to AI.Sandbox.runner.cacheGroup
     * @return void
     */
    public function setCacheGroup(?string $cacheGroup): void
    {
        $this->cacheGroupOverride = $cacheGroup;
    }

    /**
     * @param SandboxWorkspaceStore|null $sandboxWorkspaceStore a test's store; null = back to the configured one
     * @return void
     */
    public function setWorkspaceStore(?SandboxWorkspaceStore $sandboxWorkspaceStore): void
    {
        $this->sandboxWorkspaceStoreOverride = $sandboxWorkspaceStore;
    }

    /**
     * The store `AI.Sandbox.workspaces.store` names (default `manifest`).
     *
     * @return SandboxWorkspaceStore
     * @throws InternalErrorException when no registered store has that name
     */
    public function getWorkspaceStore(): SandboxWorkspaceStore
    {
        if ($this->sandboxWorkspaceStoreOverride !== null) {
            return $this->sandboxWorkspaceStoreOverride;
        }
        $storeName = (string)(Config::get('AI.Sandbox.workspaces.store') ?? SandboxManifestWorkspaceStoreService::STORE_NAME);
        foreach ($this->sandboxWorkspaceStores as $sandboxWorkspaceStore) {
            if ($sandboxWorkspaceStore->getStoreName() === $storeName) {
                return $sandboxWorkspaceStore;
            }
        }
        if ($storeName === SandboxManifestWorkspaceStoreService::STORE_NAME) {
            /** @var SandboxManifestWorkspaceStoreService $sandboxManifestWorkspaceStoreService */
            $sandboxManifestWorkspaceStoreService = DDDService::instance()->getService(SandboxManifestWorkspaceStoreService::class);
            return $sandboxManifestWorkspaceStoreService;
        }
        throw new InternalErrorException('No sandbox workspace store is named "' . $storeName . '" (AI.Sandbox.workspaces.store); registered: '
            . implode(', ', $this->listWorkspaceStoreNames()));
    }

    /**
     * @return string[]
     */
    protected function listWorkspaceStoreNames(): array
    {
        $storeNames = [];
        foreach ($this->sandboxWorkspaceStores as $sandboxWorkspaceStore) {
            $storeNames[] = $sandboxWorkspaceStore->getStoreName();
        }
        return $storeNames === [] ? [SandboxManifestWorkspaceStoreService::STORE_NAME] : $storeNames;
    }

    /**
     * @return SandboxRunnerClientService
     */
    protected function getSandboxRunnerClientService(): SandboxRunnerClientService
    {
        /** @var SandboxRunnerClientService $sandboxRunnerClientService */
        $sandboxRunnerClientService = DDDService::instance()->getService(SandboxRunnerClientService::class);
        return $sandboxRunnerClientService;
    }

    /**
     * @return SandboxSessionRulesService
     */
    protected function getSandboxSessionRulesService(): SandboxSessionRulesService
    {
        /** @var SandboxSessionRulesService $sandboxSessionRulesService */
        $sandboxSessionRulesService = DDDService::instance()->getService(SandboxSessionRulesService::class);
        return $sandboxSessionRulesService;
    }
}
