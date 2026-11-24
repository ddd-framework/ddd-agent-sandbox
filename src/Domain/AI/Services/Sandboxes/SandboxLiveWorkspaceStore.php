<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxCallContext;
use DDD\Domain\AI\Entities\Sandboxes\SandboxDriveStatus;
use DDD\Domain\AI\Entities\Sandboxes\SandboxLiveClaim;
use DDD\Domain\AI\Entities\Sandboxes\SandboxSession;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceManifest;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceSyncResult;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * A workspace store that works WITH the live session instead of behind it: the runner holds the workspace in a
 * protocol of the store (e.g. the working copy of a drive: claim with a base map, save after a call, commit at the end
 * of a turn, pull at the first call of a turn, the in-session CLI answered by PHP). {@see SandboxWorkspacesService}
 * hands every step of a key the store serves live ({@see isLiveWorkspace()}) to it; every other key goes through the
 * manifest methods of {@see SandboxWorkspaceStore} as before.
 *
 * The runner protocol of a step is the store's: wire bodies and answers are the runner's JSON (runner/README.md).
 */
interface SandboxLiveWorkspaceStore extends SandboxWorkspaceStore
{
    /** Whether the key runs in the store's live protocol (else: the manifest methods) */
    public function isLiveWorkspace(string $workspaceKey): bool;

    /** The context of the coming call (agent space, operator, turn): the store notes what a later claim or flush needs */
    public function noteCallContext(SandboxCallContext $sandboxCallContext): void;

    /**
     * @throws InternalErrorException
     */
    public function buildLiveClaim(string $workspaceKey): SandboxLiveClaim;

    /**
     * After the runner took the claim: the store keeps its session state and finishes what the claim left open.
     *
     * @param array<string, mixed> $claimAnswer the runner's claim answer
     * @throws SandboxRunnerException
     * @throws InternalErrorException
     */
    public function finishLiveClaim(SandboxSession $sandboxSession, array $claimAnswer, SandboxLiveClaim $sandboxLiveClaim): void;

    /**
     * Before a call on a session: brings in what others changed when the call is the first of a turn or a pull was
     * requested. Never throws: a failure becomes a pending note.
     */
    public function prepareCall(SandboxSession $sandboxSession, ?SandboxCallContext $sandboxCallContext, bool $isFreshClaim): void;

    /**
     * The fast step after a call: what the call changed (no store write).
     *
     * @throws SandboxRunnerException
     */
    public function recordCallChanges(SandboxSession $sandboxSession): SandboxWorkspaceSyncResult;

    /**
     * The save step (the caller holds the save lock): what the session changed goes into the store, not yet visible.
     *
     * @throws SandboxRunnerException
     * @throws InternalErrorException
     */
    public function saveSessionChanges(SandboxSession $sandboxSession, ?string $conversationKey): SandboxWorkspaceSyncResult;

    /**
     * An exec that paused for the host (the in-session CLI): answered and continued until it ends.
     *
     * @param array<string, mixed> $execAnswer
     * @return array<string, mixed> the final exec answer
     * @throws SandboxRunnerException
     */
    public function answerPausedCommand(SandboxSession $sandboxSession, array $execAnswer, ?string $conversationKey, int $timeoutSeconds): array;

    /**
     * Lines the answer of a command adds (e.g. files the command named that are not local).
     *
     * @param array<string, mixed> $execAnswer
     * @return string[]
     */
    public function buildCommandHintLines(array $execAnswer): array;

    /**
     * Before a release (the caller saved first): makes what is saved visible. Answers the files made visible.
     *
     * @throws SandboxRunnerException
     * @throws InternalErrorException
     */
    public function commitBeforeRelease(SandboxSession $sandboxSession): int;

    /**
     * At the end of a conversation's turn (the caller holds the save lock): makes the conversation's saved changes
     * visible. Never throws: a conflict or failure becomes a pending note.
     *
     * @return string|null a summary line; null when nothing was to do
     */
    public function commitAtTurnEnd(?SandboxSession $sandboxSession, string $workspaceKey, string $conversationKey, int $operatorAccountId): ?string;

    /**
     * The state note's lines about the workspace (without the work directory and NOTES.md).
     *
     * @return string[]
     * @throws InternalErrorException
     */
    public function buildStateNoteLines(?SandboxSession $sandboxSession, string $workspaceKey, string $ownerDescription, ?SandboxCallContext $sandboxCallContext): array;

    /**
     * What the store left for a conversation (conflicts, a failed save, a pull's lines), once.
     *
     * @return string[]
     */
    public function takePendingNotes(string $workspaceKey, string $conversationKey): array;

    /**
     * The files of the workspace as the store sees them now (saved changes included).
     *
     * @throws InternalErrorException
     */
    public function buildCurrentManifest(string $workspaceKey): SandboxWorkspaceManifest;

    /**
     * Drops the saved, not yet visible changes (the admin's reset). Answers how many.
     *
     * @throws InternalErrorException
     */
    public function discardSavedChanges(string $workspaceKey): int;

    /** The session ended or was discarded: the store forgets its session state of the key */
    public function forgetSessionState(string $workspaceKey): void;

    /**
     * What the workspace holds that is not visible yet.
     *
     * @throws InternalErrorException
     * @throws ConflictException
     */
    public function findDriveStatus(string $workspaceKey): SandboxDriveStatus;

    /**
     * The preloaded content as the store offers it to its live workspaces (e.g. a library space every workspace
     * mounts). Answers lines for the seed's output.
     *
     * @param string[] $contentsByPath content by path
     * @return string[]
     * @throws InternalErrorException
     */
    public function seedPreloadForLiveWorkspaces(array $contentsByPath, bool $dryRun): array;
}
