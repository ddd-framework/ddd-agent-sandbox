<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration\SandboxRunnerDirectoryEntry;
use DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration\SandboxRunnerExecRecord;
use DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration\SandboxRunnerSessionSummary;
use DDD\Domain\AI\Entities\Sandboxes\SandboxCommand;
use DDD\Domain\AI\Entities\Sandboxes\SandboxCommands;
use DDD\Domain\AI\Entities\Sandboxes\SandboxDriveStatus;
use DDD\Domain\AI\Entities\Sandboxes\SandboxFileEntries;
use DDD\Domain\AI\Entities\Sandboxes\SandboxFileEntry;
use DDD\Domain\AI\Entities\Sandboxes\SandboxOffloadResult;
use DDD\Domain\AI\Entities\Sandboxes\SandboxProcess;
use DDD\Domain\AI\Entities\Sandboxes\SandboxProcesses;
use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerClaim;
use DDD\Domain\AI\Entities\Sandboxes\SandboxSession;
use DDD\Domain\AI\Entities\Sandboxes\SandboxSessionEvent;
use DDD\Domain\AI\Entities\Sandboxes\SandboxSessionEvents;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspace;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaces;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The runner and store side of the sandbox administration: the workspaces, their runner sessions, files, processes,
 * commands and session log, and the actions start, offload, kill a job, end and reset. {@see SandboxesAdminService}
 * adds the guard against a running turn, the audit and the file delivery; the endpoints are
 * {@see \DDD\Presentation\Api\Admin\Sandbox\SandboxesControllerTrait}.
 *
 * - **Workspaces** = every key any source knows: the runner pods' sessions (`GET /v1/sessions` per pod of the default
 *   pool, the truth for what runs), the sessions of the shared cache and the keys of the workspace store. A key with a
 *   session is RUNNING (a command or job runs) or IDLE; the rest is OFFLOADED.
 * - **Names and spaces** come from {@see SandboxWorkspaceActivity} (the agent part); without it a workspace shows its key.
 * - **Reads** of a live session go to its runner (administration reads: no session activity, env values masked by the
 *   runner); an offloaded workspace answers its files from the stored manifest.
 * - **Actions** run through {@see SandboxWorkspacesService}: start = claim (restore), offload = save + release, end =
 *   save + end, reset = end without a save (the store keeps the last saved state).
 */
class SandboxAdministrationService extends Service
{
    /** @var string[] Exec and job states of a command the runner's timeout ended */
    public const array RUNNER_TIMED_OUT_STATUSES = ['TIMED_OUT', 'timedOut'];

    /** @var string[] Exec and job states of a command that was killed: by a kill, or by the runner for memory or disk */
    public const array RUNNER_KILLED_STATUSES = ['killed', 'memoryExceeded', 'quotaExceeded', 'MEMORY_EXCEEDED', 'QUOTA_EXCEEDED', 'SHELL_EXITED'];

    /** @var int Seconds the keys of the workspace store stay cached (the listing walks every object of the store) */
    public const int STORED_KEYS_CACHE_SECONDS = 300;

    /** @var string[] The screen's file entry types by the runner's directory entry type */
    public const array FILE_ENTRY_TYPES = [
        SandboxRunnerDirectoryEntry::TYPE_FILE => SandboxFileEntry::TYPE_FILE,
        SandboxRunnerDirectoryEntry::TYPE_DIRECTORY => SandboxFileEntry::TYPE_DIRECTORY,
        SandboxRunnerDirectoryEntry::TYPE_SYMLINK => SandboxFileEntry::TYPE_SYMLINK,
    ];

    /** @var string[] runner address by session id, of the last runner listing (orphan sessions) */
    protected array $runnerAddressesBySessionId = [];

    /** @var iterable<SandboxWorkspaceActivity> */
    protected iterable $sandboxWorkspaceActivities = [];

    /**
     * @param iterable<SandboxWorkspaceActivity> $sandboxWorkspaceActivities
     */
    #[Required]
    public function setSandboxWorkspaceActivities(
        #[AutowireIterator(SandboxWorkspaceActivity::TAG)]
        iterable $sandboxWorkspaceActivities
    ): void {
        $this->sandboxWorkspaceActivities = $sandboxWorkspaceActivities;
    }

    /** The registered workspace activity (the agent part, or a host's); null without one */
    public function findWorkspaceActivity(): ?SandboxWorkspaceActivity
    {
        foreach ($this->sandboxWorkspaceActivities as $sandboxWorkspaceActivity) {
            return $sandboxWorkspaceActivity;
        }
        return null;
    }

    /**
     * Every workspace any source knows.
     *
     * @return SandboxWorkspaces
     * @throws InternalErrorException
     */
    public function findWorkspaces(): SandboxWorkspaces
    {
        $runnerSessionsByKey = $this->findRunnerSessionsByWorkspaceKey();
        $cachedSessionsByKey = [];
        foreach ($this->getSandboxWorkspacesService()->findSessions() as $sandboxSession) {
            $cachedSessionsByKey[$sandboxSession->workspaceKey] = $sandboxSession;
        }
        $workspaceKeys = array_merge(array_keys($runnerSessionsByKey), array_keys($cachedSessionsByKey), $this->findStoredWorkspaceKeys());
        $workspaceKeys = array_values(array_unique(array_map('strval', $workspaceKeys)));
        sort($workspaceKeys);
        $sandboxWorkspaces = new SandboxWorkspaces();
        foreach ($workspaceKeys as $workspaceKey) {
            $sandboxWorkspace = $this->buildWorkspace($workspaceKey, $runnerSessionsByKey[$workspaceKey] ?? null, $cachedSessionsByKey[$workspaceKey] ?? null);
            $sandboxWorkspaces->add($sandboxWorkspace);
        }
        return $sandboxWorkspaces;
    }

    /**
     * One workspace; null when no source knows the key.
     *
     * @param string $workspaceKey
     * @return SandboxWorkspace|null
     * @throws InternalErrorException
     */
    public function findWorkspace(string $workspaceKey): ?SandboxWorkspace
    {
        $runnerSessionsByKey = $this->findRunnerSessionsByWorkspaceKey();
        $cachedSession = $this->getSandboxWorkspacesService()->findSession($workspaceKey);
        if (!isset($runnerSessionsByKey[$workspaceKey]) && $cachedSession === null && !$this->isStoredWorkspaceKey($workspaceKey)) {
            return null;
        }
        return $this->buildWorkspace($workspaceKey, $runnerSessionsByKey[$workspaceKey] ?? null, $cachedSession);
    }

    /**
     * The entries of one directory of the workspace ('' = the root), read-only.
     *
     * @param string $workspaceKey
     * @param string $directoryPath
     * @return SandboxFileEntries
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    public function findFileEntries(string $workspaceKey, string $directoryPath): SandboxFileEntries
    {
        $this->requireKnownWorkspace($workspaceKey);
        $sandboxFileEntries = new SandboxFileEntries();
        $liveSession = $this->findLiveSession($workspaceKey);
        $directoryListing = null;
        if ($liveSession !== null) {
            try {
                $directoryListing = $this->getSandboxRunnerClientService()->listDirectory($liveSession->runnerAddress, $liveSession->sessionId, $directoryPath);
            } catch (SandboxRunnerException $sandboxRunnerException) {
                if (in_array($sandboxRunnerException->runnerErrorCode, [SandboxRunnerException::FILE_NOT_FOUND, 'NOT_A_DIRECTORY', 'PATH_REFUSED'], true)) {
                    throw new NotFoundException('No such folder in the workspace');
                }
                if (!$this->hasNoSessionToShow($sandboxRunnerException)) {
                    throw $sandboxRunnerException;
                }
                // the session ended meanwhile: the saved state below
            }
        }
        if ($directoryListing !== null) {
            foreach ($directoryListing->entries as $directoryEntry) {
                $sandboxFileEntry = new SandboxFileEntry();
                $sandboxFileEntry->name = $directoryEntry->name;
                $sandboxFileEntry->path = ltrim(($directoryPath !== '' ? $directoryPath . '/' : '') . $directoryEntry->name, '/');
                $sandboxFileEntry->type = static::FILE_ENTRY_TYPES[$directoryEntry->type] ?? SandboxFileEntry::TYPE_FILE;
                $sandboxFileEntry->sizeBytes = $directoryEntry->type === SandboxRunnerDirectoryEntry::TYPE_DIRECTORY ? null : $directoryEntry->size;
                $sandboxFileEntry->modifiedAt = $this->toDateTime($directoryEntry->modifiedAt);
                $sandboxFileEntries->add($sandboxFileEntry);
            }
            return $sandboxFileEntries;
        }
        return $this->buildFileEntriesOfSavedState($workspaceKey, $directoryPath);
    }

    /**
     * The bytes of one file, at most `$maxBytes + 1` of them (one more tells the caller the file is larger); null when
     * the path names no file. The path was checked by {@see SandboxesAdminService}.
     *
     * @param string $workspaceKey
     * @param string $relativePath
     * @param int $maxBytes
     * @return string|null
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    public function readFileBytes(string $workspaceKey, string $relativePath, int $maxBytes): ?string
    {
        $this->requireKnownWorkspace($workspaceKey);
        $liveSession = $this->findLiveSession($workspaceKey);
        if ($liveSession !== null) {
            try {
                $fileBytes = $this->getSandboxRunnerClientService()->readFileBytes($liveSession->runnerAddress, $liveSession->sessionId, $relativePath);
                return substr($fileBytes, 0, $maxBytes + 1);
            } catch (SandboxRunnerException $sandboxRunnerException) {
                if (in_array($sandboxRunnerException->runnerErrorCode, [SandboxRunnerException::FILE_NOT_FOUND, 'NOT_A_REGULAR_FILE', 'PATH_REFUSED'], true)) {
                    return null;
                }
                if (!$this->hasNoSessionToShow($sandboxRunnerException)) {
                    throw $sandboxRunnerException;
                }
                // the session ended meanwhile: the saved state below
            }
        }
        $savedFile = $this->getSandboxWorkspacesService()->findCurrentManifest($workspaceKey)->files->findByPath($relativePath);
        if ($savedFile === null) {
            return null;
        }
        return substr($this->getSandboxWorkspacesService()->getWorkspaceStore()->readBlob($workspaceKey, $savedFile->sha256), 0, $maxBytes + 1);
    }

    /**
     * What the workspace holds that a drive does not have yet. The manifest store has no drive: always "no drive"
     * (a drive store answers its own changes).
     *
     * @param string $workspaceKey
     * @return SandboxDriveStatus
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    public function findDriveStatus(string $workspaceKey): SandboxDriveStatus
    {
        $this->requireKnownWorkspace($workspaceKey);
        return new SandboxDriveStatus();
    }

    /**
     * The processes and background jobs of the session; empty without a session.
     *
     * @param string $workspaceKey
     * @return SandboxProcesses
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    public function findProcesses(string $workspaceKey): SandboxProcesses
    {
        $this->requireKnownWorkspace($workspaceKey);
        $sandboxProcesses = new SandboxProcesses();
        $liveSession = $this->findLiveSession($workspaceKey);
        if ($liveSession === null) {
            return $sandboxProcesses;
        }
        $runnerProcesses = $this->readFromLiveSession(fn(): array => $this->getSandboxRunnerClientService()->findProcesses($liveSession->runnerAddress, $liveSession->sessionId));
        foreach ($runnerProcesses as $runnerProcess) {
            $sandboxProcess = new SandboxProcess();
            $sandboxProcess->pid = $runnerProcess->pid;
            $sandboxProcess->command = $runnerProcess->argv;
            $sandboxProcess->startedAt = $this->toDateTime($runnerProcess->startedAt);
            $sandboxProcess->cpuSeconds = $runnerProcess->cpuSeconds;
            $sandboxProcess->rssBytes = $runnerProcess->rssBytes;
            $sandboxProcess->jobId = $runnerProcess->jobId;
            $sandboxProcesses->add($sandboxProcess);
        }
        return $sandboxProcesses;
    }

    /**
     * The last commands of the session, newest first.
     *
     * @param string $workspaceKey
     * @param int $limit
     * @return SandboxCommands
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    public function findRecentCommands(string $workspaceKey, int $limit): SandboxCommands
    {
        $this->requireKnownWorkspace($workspaceKey);
        $sandboxCommands = new SandboxCommands();
        $liveSession = $this->findLiveSession($workspaceKey);
        if ($liveSession === null) {
            return $sandboxCommands;
        }
        $execRecords = $this->readFromLiveSession(fn(): array => $this->getSandboxRunnerClientService()->findRecentExecs($liveSession->runnerAddress, $liveSession->sessionId, $limit));
        foreach ($execRecords as $execRecord) {
            $sandboxCommand = new SandboxCommand();
            $sandboxCommand->seq = $execRecord->seq;
            $sandboxCommand->command = $execRecord->command;
            $sandboxCommand->startedAt = $this->toDateTime($execRecord->startedAt);
            $sandboxCommand->durationMs = $execRecord->durationMs;
            $sandboxCommand->exitCode = $execRecord->exitCode;
            $sandboxCommand->stdoutTail = $execRecord->stdoutTail;
            $sandboxCommand->stderrTail = $execRecord->stderrTail;
            $sandboxCommand->isTruncated = $execRecord->isTruncated;
            $sandboxCommand->jobId = $execRecord->jobId;
            $sandboxCommand->status = $this->commandStatusOf($execRecord);
            $sandboxCommands->add($sandboxCommand);
        }
        return $sandboxCommands;
    }

    /**
     * The command status of a runner's exec record: still running, ended by the runner's timeout, exited with a code,
     * or killed (by an admin or the agent, or by the runner for memory or disk); null for a status the runner may add.
     *
     * @param SandboxRunnerExecRecord $execRecord
     * @return string|null a SandboxCommand::STATUS_*
     */
    protected function commandStatusOf(SandboxRunnerExecRecord $execRecord): ?string
    {
        if ($execRecord->isRunning()) {
            return SandboxCommand::STATUS_RUNNING;
        }
        if (in_array($execRecord->status, static::RUNNER_TIMED_OUT_STATUSES, true)) {
            return SandboxCommand::STATUS_TIMED_OUT;
        }
        if ($execRecord->exitCode !== null) {
            return SandboxCommand::STATUS_EXITED;
        }
        if (in_array($execRecord->status, static::RUNNER_KILLED_STATUSES, true)) {
            return SandboxCommand::STATUS_KILLED;
        }
        return null;
    }

    /**
     * The newest entries of the session's log, newest first.
     *
     * @param string $workspaceKey
     * @param int $limit
     * @return SandboxSessionEvents
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    public function findSessionEvents(string $workspaceKey, int $limit): SandboxSessionEvents
    {
        $this->requireKnownWorkspace($workspaceKey);
        $sandboxSessionEvents = new SandboxSessionEvents();
        $liveSession = $this->findLiveSession($workspaceKey);
        if ($liveSession === null) {
            return $sandboxSessionEvents;
        }
        $runnerEvents = $this->readFromLiveSession(fn(): array => $this->getSandboxRunnerClientService()->findSessionEvents($liveSession->runnerAddress, $liveSession->sessionId, $limit));
        foreach ($runnerEvents as $runnerEvent) {
            $sandboxSessionEvent = new SandboxSessionEvent();
            $sandboxSessionEvent->occurredAt = $this->toDateTime($runnerEvent->occurredAt);
            $sandboxSessionEvent->type = $runnerEvent->type;
            $sandboxSessionEvent->detail = $runnerEvent->detail;
            $sandboxSessionEvents->add($sandboxSessionEvent);
        }
        return $sandboxSessionEvents;
    }

    /**
     * Starts (warms) a session for the workspace; nothing when one runs.
     *
     * @throws NotFoundException
     * @throws ConflictException no slot is free
     * @throws InternalErrorException
     */
    public function startSession(string $workspaceKey): void
    {
        $this->requireKnownWorkspace($workspaceKey);
        $this->getSandboxWorkspacesService()->ensureSession($workspaceKey);
    }

    /**
     * Saves the workspace, then releases the session; the workspace persists in the store.
     *
     * @throws NotFoundException
     * @throws ConflictException a session only its runner knows (nothing to save it from)
     * @throws InternalErrorException
     */
    public function offloadSession(string $workspaceKey): SandboxOffloadResult
    {
        $this->requireKnownWorkspace($workspaceKey);
        $sandboxWorkspacesService = $this->getSandboxWorkspacesService();
        if ($sandboxWorkspacesService->findSession($workspaceKey) === null && $this->findUncachedRunnerSession($workspaceKey) !== null) {
            throw new ConflictException('The platform holds no state of this session (only its runner knows it), so its changes cannot be saved: end it instead');
        }
        $sandboxOffloadResult = new SandboxOffloadResult();
        $sandboxOffloadResult->wasSessionReleased = $sandboxWorkspacesService->offloadSession($workspaceKey);
        $sandboxOffloadResult->detail = $sandboxOffloadResult->wasSessionReleased ? 'saved, session released' : 'no session held';
        return $sandboxOffloadResult;
    }

    /**
     * Kills one background job of the session.
     *
     * @throws NotFoundException when the job does not exist
     * @throws ConflictException when the workspace has no session
     * @throws InternalErrorException
     */
    public function killJob(string $workspaceKey, string $jobId): void
    {
        $this->requireKnownWorkspace($workspaceKey);
        $sandboxWorkspacesService = $this->getSandboxWorkspacesService();
        $hasCachedSession = $sandboxWorkspacesService->findSession($workspaceKey) !== null;
        $uncachedRunnerSession = $hasCachedSession ? null : $this->findUncachedRunnerSession($workspaceKey);
        if (!$hasCachedSession && $uncachedRunnerSession === null) {
            throw new ConflictException('The workspace has no running session, so it has no job to kill');
        }
        try {
            if ($uncachedRunnerSession !== null) {
                $this->getSandboxRunnerClientService()->readJob($uncachedRunnerSession->runnerAddress, $uncachedRunnerSession->sessionId, $jobId, true);
                return;
            }
            $sandboxWorkspacesService->readBackgroundJob($workspaceKey, $jobId, true);
        } catch (SandboxRunnerException $sandboxRunnerException) {
            if ($sandboxRunnerException->runnerErrorCode === 'JOB_NOT_FOUND') {
                throw new NotFoundException('No such job in the session');
            }
            if ($sandboxRunnerException->isSessionGone()) {
                throw new ConflictException('The workspace\'s session ended meanwhile, so it has no job to kill');
            }
            throw $sandboxRunnerException;
        }
    }

    /**
     * Saves what the session changed, then ends it.
     *
     * @throws NotFoundException
     * @throws ConflictException without a session
     * @throws InternalErrorException
     */
    public function endSession(string $workspaceKey): void
    {
        $this->requireKnownWorkspace($workspaceKey);
        if (!$this->getSandboxWorkspacesService()->endSession($workspaceKey) && !$this->releaseUncachedRunnerSession($workspaceKey)) {
            throw new ConflictException('The workspace has no running session to end');
        }
    }

    /**
     * Ends the session without a save: the next claim starts from the last saved state.
     *
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    public function resetWorkspace(string $workspaceKey): void
    {
        $this->requireKnownWorkspace($workspaceKey);
        $this->getSandboxWorkspacesService()->resetSession($workspaceKey);
        // a session only its runner knows (its cache entry expired or was lost) is wiped as well
        $this->releaseUncachedRunnerSession($workspaceKey);
    }

    /**
     * A session a runner pod holds for the key while the shared cache holds none (the platform lost its state: an
     * expired entry, a process that ended without releasing). Read fresh from the pods.
     *
     * @param string $workspaceKey
     * @return SandboxRunnerClaim|null
     */
    protected function findUncachedRunnerSession(string $workspaceKey): ?SandboxRunnerClaim
    {
        if ($this->getSandboxWorkspacesService()->findSession($workspaceKey) !== null) {
            return null;
        }
        $runnerSession = $this->findRunnerSessionsByWorkspaceKey()[$workspaceKey] ?? null;
        return $runnerSession !== null ? $this->buildRunnerClaimOf($runnerSession) : null;
    }

    /**
     * Releases a session only its runner knows ({@see findUncachedRunnerSession}); the runner wipes the slot.
     *
     * @param string $workspaceKey
     * @return bool whether such a session was released
     * @throws SandboxRunnerException a refusal other than "the session is gone"
     */
    protected function releaseUncachedRunnerSession(string $workspaceKey): bool
    {
        $uncachedRunnerSession = $this->findUncachedRunnerSession($workspaceKey);
        if ($uncachedRunnerSession === null) {
            return false;
        }
        try {
            $this->getSandboxRunnerClientService()->releaseSession($uncachedRunnerSession->runnerAddress, $uncachedRunnerSession->sessionId);
        } catch (SandboxRunnerException $sandboxRunnerException) {
            if (!$sandboxRunnerException->isSessionGone()) {
                throw $sandboxRunnerException;
            }
            return false;
        }
        return true;
    }

    /**
     * Runs one administration read of a live session. A session that ended meanwhile (released, idle, pod gone) and
     * any 404 of the runner answer an empty list: the workspace has no session to show, the list shows its state.
     *
     * @param callable(): array<int, mixed> $read
     * @return array<int, mixed>
     * @throws SandboxRunnerException any other refusal
     */
    protected function readFromLiveSession(callable $read): array
    {
        try {
            return $read();
        } catch (SandboxRunnerException $sandboxRunnerException) {
            if (!$this->hasNoSessionToShow($sandboxRunnerException)) {
                throw $sandboxRunnerException;
            }
            return [];
        }
    }

    /**
     * @param SandboxRunnerException $sandboxRunnerException
     * @return bool Whether the runner has no session to show: the session ended (released, idle, pod gone) or the
     *     runner answered a bare 404
     */
    protected function hasNoSessionToShow(SandboxRunnerException $sandboxRunnerException): bool
    {
        return $sandboxRunnerException->isSessionGone() || $sandboxRunnerException->runnerErrorCode === 'HTTP_404';
    }

    /**
     * @param string $workspaceKey
     * @param SandboxRunnerSessionSummary|null $runnerSession the session a runner pod holds for the key
     * @param SandboxSession|null $cachedSession the session the shared cache holds for the key
     * @return SandboxWorkspace
     */
    protected function buildWorkspace(string $workspaceKey, ?SandboxRunnerSessionSummary $runnerSession, ?SandboxSession $cachedSession): SandboxWorkspace
    {
        $sandboxWorkspace = new SandboxWorkspace();
        $sandboxWorkspace->workspaceKey = $workspaceKey;
        $sandboxWorkspaceActivity = $this->findWorkspaceActivity();
        if ($sandboxWorkspaceActivity !== null) {
            $sandboxWorkspace->agentSpaceId = $sandboxWorkspaceActivity->findAgentSpaceIdOfWorkspaceKey($workspaceKey);
            $sandboxWorkspace->displayName = $sandboxWorkspaceActivity->findDisplayNameOfWorkspaceKey($workspaceKey);
            $sandboxWorkspace->ownerDisplayName = $sandboxWorkspaceActivity->findOwnerDisplayNameOfWorkspaceKey($workspaceKey);
        }
        $sandboxWorkspace->state = SandboxWorkspace::STATE_OFFLOADED;
        if ($cachedSession !== null && $cachedSession->lastUsedAt > 0) {
            $sandboxWorkspace->lastActivityAt = DateTime::fromTimestamp($cachedSession->lastUsedAt);
        }
        if ($runnerSession === null) {
            if ($cachedSession !== null) {
                // a session the cache holds but no pod listed (its pod did not answer the list): in use, its figures unknown
                $sandboxWorkspace->state = SandboxWorkspace::STATE_IDLE;
                $sandboxWorkspace->mountMode = null;
            }
            return $sandboxWorkspace;
        }
        $isBusy = $runnerSession->runningJobCount + $runnerSession->runningExecCount > 0;
        $sandboxWorkspace->state = $isBusy ? SandboxWorkspace::STATE_RUNNING : SandboxWorkspace::STATE_IDLE;
        $sandboxWorkspace->lastActivityAt = $this->toDateTime($runnerSession->lastActivityAt);
        $sandboxWorkspace->diskUsedBytes = $runnerSession->diskUsedBytes;
        $sandboxWorkspace->diskQuotaBytes = $runnerSession->diskQuotaBytes;
        $sandboxWorkspace->memoryBytes = $runnerSession->memoryBytes;
        $sandboxWorkspace->memoryPeakBytes = $runnerSession->memoryPeakBytes;
        $sandboxWorkspace->memoryLimitBytes = $runnerSession->memoryLimitBytes;
        $sandboxWorkspace->mountMode = $runnerSession->mountMode;
        $sandboxWorkspace->mountModeReason = $runnerSession->mountModeReason !== '' ? $runnerSession->mountModeReason : null;
        $sandboxWorkspace->runnerPod = $runnerSession->pod;
        $sandboxWorkspace->runningJobCount = $runnerSession->runningJobCount;
        return $sandboxWorkspace;
    }

    /**
     * The session of every runner pod of the default pool, by workspace key. A pod that does not answer is left out
     * (one pod never blocks the list) and logged; no runner Service (a local run without runners) gives none.
     *
     * @return SandboxRunnerSessionSummary[] by workspace key
     */
    protected function findRunnerSessionsByWorkspaceKey(): array
    {
        $sandboxRunnerClientService = $this->getSandboxRunnerClientService();
        try {
            $runnerAddresses = $sandboxRunnerClientService->resolveRunnerAddresses();
        } catch (SandboxRunnerException $sandboxRunnerException) {
            DDDService::instance()->getLogger()->warning('sandboxAdministration: no runner pod resolves: ' . $sandboxRunnerException->getMessage());
            return [];
        }
        $runnerSessionsByKey = [];
        foreach ($runnerAddresses as $runnerAddress) {
            try {
                $sessionList = $sandboxRunnerClientService->listRunnerSessions($runnerAddress);
            } catch (SandboxRunnerException $sandboxRunnerException) {
                DDDService::instance()->getLogger()->warning('sandboxAdministration: runner ' . $runnerAddress . ' left out of the list: ' . $sandboxRunnerException->getMessage());
                continue;
            }
            foreach ($sessionList->sessions as $runnerSession) {
                $runnerSessionsByKey[$runnerSession->workspaceKey] = $runnerSession;
                $this->runnerAddressesBySessionId[$runnerSession->sessionId] = $runnerAddress;
            }
        }
        return $runnerSessionsByKey;
    }

    /**
     * @param SandboxRunnerSessionSummary $runnerSession
     * @return SandboxRunnerClaim
     */
    protected function buildRunnerClaimOf(SandboxRunnerSessionSummary $runnerSession): SandboxRunnerClaim
    {
        $sandboxRunnerClaim = new SandboxRunnerClaim();
        $sandboxRunnerClaim->runnerAddress = (string)($this->runnerAddressesBySessionId[$runnerSession->sessionId] ?? '');
        $sandboxRunnerClaim->sessionId = $runnerSession->sessionId;
        return $sandboxRunnerClaim;
    }

    /**
     * The live session of a key: the one the shared cache holds, else one a runner pod holds without a cache entry
     * (an orphan, e.g. after the cache was cleared).
     *
     * @param string $workspaceKey
     * @return SandboxRunnerClaim|null
     */
    protected function findLiveSession(string $workspaceKey): ?SandboxRunnerClaim
    {
        $cachedSession = $this->getSandboxWorkspacesService()->findSession($workspaceKey);
        if ($cachedSession !== null) {
            $sandboxRunnerClaim = new SandboxRunnerClaim();
            $sandboxRunnerClaim->runnerAddress = $cachedSession->runnerAddress;
            $sandboxRunnerClaim->sessionId = $cachedSession->sessionId;
            return $sandboxRunnerClaim;
        }
        $runnerSession = $this->findRunnerSessionsByWorkspaceKey()[$workspaceKey] ?? null;
        return $runnerSession !== null ? $this->buildRunnerClaimOf($runnerSession) : null;
    }

    /**
     * @param string $workspaceKey
     * @return void
     * @throws NotFoundException
     */
    protected function requireKnownWorkspace(string $workspaceKey): void
    {
        if ($this->getSandboxWorkspacesService()->findSession($workspaceKey) !== null || $this->isStoredWorkspaceKey($workspaceKey)) {
            return;
        }
        if (!isset($this->findRunnerSessionsByWorkspaceKey()[$workspaceKey])) {
            throw new NotFoundException('No such sandbox workspace');
        }
    }

    /**
     * @param string $workspaceKey
     * @return bool whether the workspace store holds the key
     */
    protected function isStoredWorkspaceKey(string $workspaceKey): bool
    {
        return in_array($workspaceKey, $this->findStoredWorkspaceKeys(), true);
    }

    /**
     * The keys of the workspace store (reserved keys left out), cached for {@see STORED_KEYS_CACHE_SECONDS}: the
     * listing walks every object of the store.
     *
     * @return string[]
     */
    protected function findStoredWorkspaceKeys(): array
    {
        $sandboxWorkspacesService = $this->getSandboxWorkspacesService();
        $cache = $sandboxWorkspacesService->getWorkspaceCache();
        $cacheKey = 'sandboxWorkspace.administration.storedKeys';
        $cachedKeys = $cache->get($cacheKey);
        if (is_array($cachedKeys)) {
            return array_values(array_map('strval', $cachedKeys));
        }
        try {
            $storedKeys = $sandboxWorkspacesService->getWorkspaceStore()->listWorkspaceKeys();
        } catch (InternalErrorException $internalErrorException) {
            DDDService::instance()->getLogger()->warning('sandboxAdministration: the workspace store does not list: ' . $internalErrorException->getMessage());
            return [];
        }
        $workspaceKeys = [];
        foreach ($storedKeys as $storedKey) {
            if (!str_starts_with($storedKey, SandboxWorkspacesService::RESERVED_WORKSPACE_KEY_PREFIX)) {
                $workspaceKeys[] = $storedKey;
            }
        }
        $cache->set($cacheKey, $workspaceKeys, static::STORED_KEYS_CACHE_SECONDS);
        return $workspaceKeys;
    }

    /**
     * One folder of an offloaded workspace from its stored manifest. Folders are derived from the file paths.
     *
     * @param string $workspaceKey
     * @param string $directoryPath
     * @return SandboxFileEntries
     * @throws NotFoundException the folder holds no saved file
     * @throws InternalErrorException
     */
    protected function buildFileEntriesOfSavedState(string $workspaceKey, string $directoryPath): SandboxFileEntries
    {
        $prefix = $directoryPath !== '' ? rtrim($directoryPath, '/') . '/' : '';
        $folderNames = [];
        $savedFilesByName = [];
        foreach ($this->getSandboxWorkspacesService()->findCurrentManifest($workspaceKey)->files as $path => $savedFile) {
            $path = (string)$path;
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }
            $pathBelow = substr($path, strlen($prefix));
            if ($pathBelow === '') {
                continue;
            }
            $slashPosition = strpos($pathBelow, '/');
            if ($slashPosition !== false) {
                $folderNames[substr($pathBelow, 0, $slashPosition)] = true;
                continue;
            }
            $savedFilesByName[$pathBelow] = $savedFile;
        }
        if ($prefix !== '' && $folderNames === [] && $savedFilesByName === []) {
            throw new NotFoundException('No such folder in the workspace');
        }
        $sandboxFileEntries = new SandboxFileEntries();
        $folderNames = array_keys($folderNames);
        sort($folderNames);
        foreach ($folderNames as $folderName) {
            $sandboxFileEntry = new SandboxFileEntry();
            $sandboxFileEntry->name = (string)$folderName;
            $sandboxFileEntry->path = $prefix . $folderName;
            $sandboxFileEntry->type = SandboxFileEntry::TYPE_DIRECTORY;
            $sandboxFileEntries->add($sandboxFileEntry);
        }
        ksort($savedFilesByName);
        foreach ($savedFilesByName as $fileName => $savedFile) {
            $sandboxFileEntry = new SandboxFileEntry();
            $sandboxFileEntry->name = (string)$fileName;
            $sandboxFileEntry->path = $prefix . $fileName;
            $sandboxFileEntry->type = SandboxFileEntry::TYPE_FILE;
            $sandboxFileEntry->sizeBytes = $savedFile->size;
            $modifiedTimestamp = $savedFile->mtime !== '' ? strtotime($savedFile->mtime) : false;
            $sandboxFileEntry->modifiedAt = $modifiedTimestamp !== false ? DateTime::fromTimestamp((int)$modifiedTimestamp) : null;
            $sandboxFileEntries->add($sandboxFileEntry);
        }
        return $sandboxFileEntries;
    }

    /**
     * @param DateTimeImmutable|null $moment
     * @return DateTime|null
     */
    protected function toDateTime(?DateTimeImmutable $moment): ?DateTime
    {
        return $moment !== null ? DateTime::fromTimestamp($moment->getTimestamp()) : null;
    }

    protected function getSandboxWorkspacesService(): SandboxWorkspacesService
    {
        /** @var SandboxWorkspacesService $sandboxWorkspacesService */
        $sandboxWorkspacesService = DDDService::instance()->getService(SandboxWorkspacesService::class);
        return $sandboxWorkspacesService;
    }

    protected function getSandboxRunnerClientService(): SandboxRunnerClientService
    {
        /** @var SandboxRunnerClientService $sandboxRunnerClientService */
        $sandboxRunnerClientService = DDDService::instance()->getService(SandboxRunnerClientService::class);
        return $sandboxRunnerClientService;
    }
}
