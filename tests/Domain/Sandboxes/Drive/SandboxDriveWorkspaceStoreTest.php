<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Domain\Sandboxes\Drive;

use DDD\Domain\AI\Entities\Sandboxes\SandboxCallContext;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceSyncResult;
use DDD\Domain\AI\Services\Sandboxes\Agents\Drive\SandboxDriveWorkspaceStoreService;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResult;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyChanges;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyEntry;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyFile;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyFiles;
use DDD\Tests\AgentSandbox\Fakes\Drive\TestableSandboxDriveWorkspaceStoreService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The drive store on fakes: which keys it serves, the claim, the pull at the first call of a turn and on the pull mark,
 * the replay of changes, the save after a call, the commit at the turn end with its conflicts, the CLI pause, release.
 */
class SandboxDriveWorkspaceStoreTest extends TestCase
{
    public function testItServesAgentSpaceKeysAndLeavesEveryOtherKeyToTheManifestStore(): void
    {
        $driveStore = new TestableSandboxDriveWorkspaceStoreService();
        self::assertSame(SandboxDriveWorkspaceStoreService::STORE_NAME, $driveStore->getStoreName());
        self::assertTrue($driveStore->isLiveWorkspace('space-3'), 'a space key names its agent space');
        self::assertFalse($driveStore->isLiveWorkspace('account-9'));
        self::assertFalse($driveStore->isLiveWorkspace('system.preload'));
        self::assertFalse($driveStore->isLiveWorkspace('eval-case-1-1-x'), 'an eval key without a binding');
        $driveStore->noteCallContext($this->buildCallContext('eval-case-1-1-x', 'conversation-5', 't1', 3));
        self::assertTrue($driveStore->isLiveWorkspace('eval-case-1-1-x'), 'a call of the space bound the eval key');
        $driveStore->createWorkspaceFromFiles('eval-case-1-1-x', ['a.txt' => 'A']);
        self::assertFalse($driveStore->isLiveWorkspace('eval-case-1-1-x'), 'files made for the key keep it in the manifest store');
        self::assertSame('A', $driveStore->readBlob('eval-case-1-1-x', hash('sha256', 'A')));
        $driveStore->scratchDriveSpaceIds['eval-case-1-1-x'] = TestableSandboxDriveWorkspaceStoreService::OWN_DRIVE_SPACE_ID;
        self::assertTrue($driveStore->isLiveWorkspace('eval-case-1-1-x'), 'a scratch drive wins');
        $driveStore->createWorkspaceFromFiles('account-9', ['b.txt' => 'B']);
        self::assertSame(1, $driveStore->readManifest('account-9')->countFiles());
    }

    public function testAClaimSendsTheBaseMapWithRestoreUrlsAndTheLibraryMount(): void
    {
        $driveStore = new TestableSandboxDriveWorkspaceStoreService();
        $releasedBlob = $driveStore->fakeDriveBlobsService->addBlob(7, hash('sha256', 'notes'), 5);
        $driveStore->fakeDriveChangesService->newestChangeIdsBySpaceId = [7 => 40, 50 => 12];
        $ownWorkingCopy = $driveStore->fakeDriveWorkingCopiesService->findOrCreateWorkingCopy($driveStore->fakeDriveSpacesService->spacesById[7], 'space-3');
        $driveStore->fakeDriveWorkingCopiesService->baseMapsByWorkingCopyId[(int)$ownWorkingCopy->id] = $this->buildFiles($this->buildFile('NOTES.md', 21, 301, (int)$releasedBlob->id, hash('sha256', 'notes'), 5));
        $libraryWorkingCopy = $driveStore->fakeDriveWorkingCopiesService->findOrCreateWorkingCopy($driveStore->fakeDriveSpacesService->spacesById[50], 'space-3~library');
        $startScriptBlob = $driveStore->fakeDriveBlobsService->addBlob(50, hash('sha256', 'echo hi'), 7);
        $driveStore->fakeDriveWorkingCopiesService->baseMapsByWorkingCopyId[(int)$libraryWorkingCopy->id] = $this->buildFiles($this->buildFile('start.sh', 31, 401, (int)$startScriptBlob->id, hash('sha256', 'echo hi'), 7));
        $sandboxWorkspacesService = $driveStore->testableSandboxWorkspacesService;
        $sandboxWorkspacesService->setCallContext($this->buildCallContext('space-3', 'conversation-5', 'turn-1', 3));

        $sandboxWorkspacesService->readWorkspaceFileBytes('space-3', 'NOTES.md');

        $driveClaimBlock = $driveStore->fakeDriveRunnerClientService->driveClaimBlocks[0];
        self::assertSame(7, $driveClaimBlock['spaceId']);
        self::assertSame(40, $driveClaimBlock['cursor']);
        self::assertSame('NOTES.md', $driveClaimBlock['entries'][0]['path']);
        self::assertStringStartsWith('https://proxy.test/restore/' . $releasedBlob->id, $driveClaimBlock['entries'][0]['url']);
        self::assertSame('library', $driveClaimBlock['mounts'][0]['name']);
        self::assertSame('READ_ONLY', $driveClaimBlock['mounts'][0]['mode']);
        self::assertSame('/workspace/drivespaces/library', $driveStore->fakeDriveRunnerClientService->driveClaimEnvironments[0]['SANDBOX_PRELOAD']);
        self::assertSame(40, (int)$ownWorkingCopy->changeCursor, 'the claim acknowledged the cursor of its base map');
        self::assertSame(12, (int)$libraryWorkingCopy->changeCursor);
        self::assertContains("sh '/workspace/drivespaces/library/start.sh'", $driveStore->fakeDriveRunnerClientService->executedCommands, 'the library start script ran once');
        $sandboxSession = $sandboxWorkspacesService->findSession('space-3');
        self::assertNotNull($driveStore->findSessionState($sandboxSession));
        self::assertSame([], $driveStore->fakeDriveRunnerClientService->findDriveCallBodies('pull'), 'a fresh claim pulls nothing');
    }

    public function testThePullRunsAtTheFirstCallOfATurnAndAtTheNextCallWhenThePullMarkIsSet(): void
    {
        $driveStore = new TestableSandboxDriveWorkspaceStoreService();
        $sandboxWorkspacesService = $driveStore->testableSandboxWorkspacesService;
        $sandboxWorkspacesService->setCallContext($this->buildCallContext('space-3', 'conversation-5', 'turn-1', 3));
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-3', 'a.txt');
        $ownWorkingCopy = $driveStore->getWorkingCopyOfKey('space-3');
        $queueChange = function (int $toCursor) use ($driveStore, $ownWorkingCopy): void {
            $changedBlob = $driveStore->fakeDriveBlobsService->addBlob(7, hash('sha256', 'v' . $toCursor), 2);
            $driveStore->fakeDriveWorkingCopiesService->changePagesByWorkingCopyId[(int)$ownWorkingCopy->id][] = $this->buildChanges($toCursor, $this->buildFile('report.csv', 22, 500 + $toCursor, (int)$changedBlob->id, hash('sha256', 'v' . $toCursor), 2));
        };

        $queueChange(41);
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-3', 'a.txt');
        self::assertSame([], $driveStore->fakeDriveRunnerClientService->findDriveCallBodies('pull'), 'the same turn without a mark pulls nothing');

        $driveStore->fakeDriveWorkingCopiesService->requestPull($ownWorkingCopy);
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-3', 'a.txt');
        $pullBodies = $driveStore->fakeDriveRunnerClientService->findDriveCallBodies('pull');
        self::assertCount(1, $pullBodies, 'the pull mark pulls at the next call');
        self::assertSame('update', $pullBodies[0]['changes'][0]['op']);
        self::assertSame('report.csv', $pullBodies[0]['changes'][0]['path']);
        self::assertArrayHasKey('url', $pullBodies[0]['changes'][0]);
        self::assertSame(41, (int)$ownWorkingCopy->changeCursor);
        self::assertFalse($driveStore->fakeDriveWorkingCopiesService->isPullRequested($ownWorkingCopy), 'the mark is consumed');

        $queueChange(42);
        $sandboxWorkspacesService->setCallContext($this->buildCallContext('space-3', 'conversation-5', 'turn-2', 3));
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-3', 'a.txt');
        self::assertCount(2, $driveStore->fakeDriveRunnerClientService->findDriveCallBodies('pull'), 'a new turn pulls');
        self::assertSame(42, (int)$ownWorkingCopy->changeCursor);
    }

    public function testAChangeStillBeingScannedIsSentWithoutContentAndHoldsTheCursor(): void
    {
        $driveStore = new TestableSandboxDriveWorkspaceStoreService();
        $sandboxWorkspacesService = $driveStore->testableSandboxWorkspacesService;
        $sandboxWorkspacesService->setCallContext($this->buildCallContext('space-3', 'conversation-5', 'turn-1', 3));
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-3', 'a.txt');
        $ownWorkingCopy = $driveStore->getWorkingCopyOfKey('space-3');
        $scannedBlob = $driveStore->fakeDriveBlobsService->addBlob(7, hash('sha256', 'new'), 3, DriveBlob::STATE_PENDING);
        $removedFile = $this->buildFile('old.txt', 23, 600, null, hash('sha256', 'old'), 3);
        $removedFile->isRemoved = true;
        $driveStore->fakeDriveWorkingCopiesService->changePagesByWorkingCopyId[(int)$ownWorkingCopy->id][] = $this->buildChanges(
            45,
            $this->buildFile('report.csv', 22, 601, (int)$scannedBlob->id, hash('sha256', 'new'), 3),
            $removedFile
        );
        $driveStore->fakeDriveRunnerClientService->driveAnswersByCall['pull'] = [['target' => '', 'applied' => ['old.txt'], 'needBlobs' => [['sha256' => hash('sha256', 'new'), 'paths' => ['report.csv']]]]];
        $sandboxWorkspacesService->setCallContext($this->buildCallContext('space-3', 'conversation-5', 'turn-2', 3));

        $sandboxWorkspacesService->readWorkspaceFileBytes('space-3', 'a.txt');

        $pullBody = $driveStore->fakeDriveRunnerClientService->findDriveCallBodies('pull')[0];
        self::assertArrayNotHasKey('url', $pullBody['changes'][0], 'content still in the scan does not go into the sandbox');
        self::assertSame('delete', $pullBody['changes'][1]['op']);
        self::assertSame(0, (int)$ownWorkingCopy->changeCursor, 'the cursor stays: the next pull sends the change again');
        $pendingNotes = $sandboxWorkspacesService->takePendingNotes('space-3', 'conversation-5');
        self::assertStringContainsString('1 file(s) updated from the drive', implode("\n", $pendingNotes));
        self::assertStringContainsString('report.csv has a newer version in the drive that could not come in yet', implode("\n", $pendingNotes));
        self::assertSame(45, SandboxDriveWorkspaceStoreService::buildAcknowledgedCursor(40, 45, false));
        self::assertSame(40, SandboxDriveWorkspaceStoreService::buildAcknowledgedCursor(40, 45, true));
    }

    public function testACallRecordsItsChangesAndTheFlushSavesThemIntoTheWorkingCopy(): void
    {
        $driveStore = new TestableSandboxDriveWorkspaceStoreService();
        $sandboxWorkspacesService = $driveStore->testableSandboxWorkspacesService;
        $sha256 = hash('sha256', 'a;b');
        $statusChange = ['path' => 'out/report.csv', 'kind' => 'added', 'sha256' => $sha256, 'size' => 3, 'type' => 'file'];
        $driveStore->fakeDriveRunnerClientService->driveAnswersByCall['status'] = [['changes' => [$statusChange]]];
        $driveStore->fakeDriveRunnerClientService->driveAnswersByCall['stage'] = [['changes' => [$statusChange]]];
        $driveStore->fakeDriveRunnerClientService->driveAnswersByCall['upload'] = [['uploads' => [['sha256' => $sha256, 'ok' => true]]]];
        $sandboxWorkspacesService->setCallContext($this->buildCallContext('space-3', 'conversation-5', 'turn-1', 3));

        $sandboxFileWrite = $sandboxWorkspacesService->writeWorkspaceFileBytes('space-3', 'out/report.csv', 'a;b', 'conversation-5');

        self::assertSame(SandboxWorkspaceSyncResult::CHANGE_NEW, $sandboxFileWrite->syncResult->changes[0]->change);
        self::assertSame('uncommitted, saved to the drive at turn end', $sandboxFileWrite->syncResult->changes[0]->expiryLabel);
        self::assertSame(['space-3'], $sandboxWorkspacesService->startedFlushProcesses);
        self::assertSame([], $driveStore->fakeDriveWorkingCopiesService->stagedChangeCalls, 'nothing staged before the flush');

        $sandboxWorkspacesService->flushPendingChanges('space-3');

        $stagedChange = $driveStore->fakeDriveWorkingCopiesService->stagedChangeCalls[0]->first();
        self::assertSame('out/report.csv', $stagedChange->path);
        self::assertSame('5', $stagedChange->conversationKey, 'the conversation the change is committed with');
        $uploadBody = $driveStore->fakeDriveRunnerClientService->findDriveCallBodies('upload')[0];
        self::assertSame($sha256, $uploadBody['uploads'][0]['sha256']);
        $stagedBlobId = $driveStore->fakeDriveBlobsService->completedBlobIds[0];
        self::assertSame(DriveBlob::STATE_STAGED, $driveStore->fakeDriveBlobsService->blobsById[$stagedBlobId]->state);
        self::assertNull($sandboxWorkspacesService->findDirtyMark('space-3'));

        $sandboxWorkspacesService->flushPendingChanges('space-3');
        self::assertCount(1, $driveStore->fakeDriveWorkingCopiesService->stagedChangeCalls, 'a change saved before is not staged again');
    }

    public function testTheTurnEndCommitsTheConversationAndAConflictComesBackAsANoteOnce(): void
    {
        $driveStore = new TestableSandboxDriveWorkspaceStoreService();
        $sandboxWorkspacesService = $driveStore->testableSandboxWorkspacesService;
        $sandboxWorkspacesService->setCallContext($this->buildCallContext('space-3', 'conversation-5', 'turn-1', 3));
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-3', 'a.txt');
        $driveCommitResult = new DriveCommitResult();
        $driveCommitResult->committedFiles = $this->buildFiles($this->buildFile('out/a.txt', 24, 700, null, hash('sha256', 'a'), 1));
        $conflictedFile = $this->buildFile('NOTES.md', 21, 701, null, hash('sha256', 'mine'), 4);
        $conflictedFile->conflictReason = 'changed in the drive meanwhile';
        $driveCommitResult->conflictedFiles = $this->buildFiles($conflictedFile);
        $driveStore->fakeDriveWorkingCopiesService->nextCommitResult = $driveCommitResult;

        $summaryLine = $sandboxWorkspacesService->commitAtTurnEnd('space-3', 'conversation-5', 0);

        self::assertNotNull($summaryLine);
        self::assertSame('5', $driveStore->fakeDriveWorkingCopiesService->commitCalls[0]['conversationKey'], 'only the conversation\'s changes');
        // the drive's after-commit hook (SandboxDriveWorkingCopyListener) moves the runner's base
        $sandboxSession = $sandboxWorkspacesService->findSession('space-3');
        $driveStore->applyCommitToRunner($sandboxSession, $driveStore->findSessionState($sandboxSession), $driveStore->getWorkingCopyOfKey('space-3'), $driveCommitResult, null);
        $runnerCommit = $driveStore->fakeDriveRunnerClientService->findDriveCallBodies('commit')[0];
        self::assertSame('out/a.txt', $runnerCommit['committed'][0]['path']);
        self::assertSame('NOTES.md', $runnerCommit['conflicts'][0]['path']);
        $pendingNotes = $sandboxWorkspacesService->takePendingNotes('space-3', 'conversation-5');
        self::assertCount(1, $pendingNotes);
        self::assertStringContainsString('conflict: NOTES.md', $pendingNotes[0]);
        self::assertSame([], $sandboxWorkspacesService->takePendingNotes('space-3', 'conversation-5'), 'a note is given once');
    }

    public function testAFailedTurnEndCommitNeverFailsTheTurnAndLeavesANote(): void
    {
        $driveStore = new TestableSandboxDriveWorkspaceStoreService();
        $sandboxWorkspacesService = $driveStore->testableSandboxWorkspacesService;
        $sandboxWorkspacesService->setCallContext($this->buildCallContext('space-3', 'conversation-5', 'turn-1', 3));
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-3', 'a.txt');
        $driveStore->fakeDriveWorkingCopiesService->nextCommitException = new RuntimeException('database gone');

        self::assertNull($sandboxWorkspacesService->commitAtTurnEnd('space-3', 'conversation-5', 0));

        self::assertStringContainsString('database gone', $driveStore->loggedErrors[0]);
        self::assertStringContainsString('the save at the end of the last turn failed', $sandboxWorkspacesService->takePendingNotes('space-3', 'conversation-5')[0]);
    }

    public function testTheDriveCliPauseIsAnsweredAndContinued(): void
    {
        $driveStore = new TestableSandboxDriveWorkspaceStoreService();
        $sandboxWorkspacesService = $driveStore->testableSandboxWorkspacesService;
        $sandboxWorkspacesService->setUploadInBackground(true);
        $sandboxWorkspacesService->setCallContext($this->buildCallContext('space-3', 'conversation-5', 'turn-1', 3));
        $driveStore->fakeDriveRunnerClientService->pausedExecAnswer = [
            'status' => 'DRIVE_REQUESTS',
            'seq' => 4,
            'driveRequests' => [
                ['requestId' => 'r1', 'verb' => 'pull', 'payload' => []],
                ['requestId' => 'r2', 'verb' => 'share', 'payload' => []],
            ],
        ];

        $sandboxCommandRun = $sandboxWorkspacesService->runShellCommand('space-3', 'conversation-5', 'drive pull', 30, false);

        self::assertSame('OK', $sandboxCommandRun->execAnswer['status']);
        $responses = $driveStore->fakeDriveRunnerClientService->continuedResponses[0];
        self::assertTrue($responses[0]['ok']);
        self::assertSame('already up to date', $responses[0]['message']);
        self::assertFalse($responses[1]['ok']);
        self::assertSame('drive share is not known to the agent host', $responses[1]['message']);
    }

    public function testFilesTheCommandNamedThatAreNotLocalGetAHint(): void
    {
        $driveStore = new TestableSandboxDriveWorkspaceStoreService();
        self::assertSame([], $driveStore->buildCommandHintLines(['exitCode' => 0]));
        $hintLines = $driveStore->buildCommandHintLines(['notLocal' => [['path' => 'data/big.csv', 'size' => 30000000]], 'notLocalBytes' => 30000000]);
        self::assertStringContainsString('1 file(s) the command named are not local', $hintLines[0]);
        self::assertStringContainsString('drive get data/big.csv', $hintLines[2]);
    }

    public function testAReleaseCommitsWhatIsLeftAndTheResetDropsTheSavedChanges(): void
    {
        $driveStore = new TestableSandboxDriveWorkspaceStoreService();
        $sandboxWorkspacesService = $driveStore->testableSandboxWorkspacesService;
        $sandboxWorkspacesService->setCallContext($this->buildCallContext('space-3', 'conversation-5', 'turn-1', 3));
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-3', 'a.txt');
        $driveCommitResult = new DriveCommitResult();
        $driveCommitResult->committedFiles = $this->buildFiles($this->buildFile('out/a.txt', 24, 700, null, hash('sha256', 'a'), 1));
        $driveStore->fakeDriveWorkingCopiesService->nextCommitResult = $driveCommitResult;

        self::assertTrue($sandboxWorkspacesService->releaseSession('space-3'));

        self::assertSame(1, $sandboxWorkspacesService->lastReleaseCommittedChangeCount);
        self::assertNull($driveStore->fakeDriveWorkingCopiesService->commitCalls[0]['conversationKey'], 'every conversation\'s changes');
        self::assertNull($sandboxWorkspacesService->findSession('space-3'));

        $ownWorkingCopy = $driveStore->getWorkingCopyOfKey('space-3');
        $savedEntry = new DriveWorkingCopyEntry();
        $savedEntry->path = 'x.txt';
        $driveStore->fakeDriveWorkingCopiesService->entriesByWorkingCopyId[(int)$ownWorkingCopy->id]['x.txt'] = $savedEntry;
        $sandboxWorkspacesService->resetSession('space-3');
        self::assertSame(1, $sandboxWorkspacesService->lastResetDiscardedChangeCount);
        self::assertSame([], $driveStore->fakeDriveWorkingCopiesService->entriesByWorkingCopyId[(int)$ownWorkingCopy->id]);
    }

    protected function buildCallContext(string $workspaceKey, string $conversationKey, ?string $turnKey, ?int $agentSpaceId): SandboxCallContext
    {
        $sandboxCallContext = new SandboxCallContext();
        $sandboxCallContext->workspaceKey = $workspaceKey;
        $sandboxCallContext->conversationKey = $conversationKey;
        $sandboxCallContext->turnKey = $turnKey;
        $sandboxCallContext->agentSpaceId = $agentSpaceId;
        return $sandboxCallContext;
    }

    protected function buildFile(string $path, int $driveNodeId, int $versionId, ?int $driveBlobId, string $sha256, int $sizeBytes): DriveWorkingCopyFile
    {
        $driveWorkingCopyFile = new DriveWorkingCopyFile();
        $driveWorkingCopyFile->path = $path;
        $driveWorkingCopyFile->driveNodeId = $driveNodeId;
        $driveWorkingCopyFile->versionId = $versionId;
        $driveWorkingCopyFile->versionNumber = 1;
        $driveWorkingCopyFile->driveBlobId = $driveBlobId;
        $driveWorkingCopyFile->sha256 = $sha256;
        $driveWorkingCopyFile->sizeBytes = $sizeBytes;
        return $driveWorkingCopyFile;
    }

    protected function buildFiles(DriveWorkingCopyFile ...$driveWorkingCopyFiles): DriveWorkingCopyFiles
    {
        $files = new DriveWorkingCopyFiles();
        foreach ($driveWorkingCopyFiles as $driveWorkingCopyFile) {
            $files->add($driveWorkingCopyFile);
        }
        return $files;
    }

    protected function buildChanges(int $toCursor, DriveWorkingCopyFile ...$driveWorkingCopyFiles): DriveWorkingCopyChanges
    {
        $driveWorkingCopyChanges = new DriveWorkingCopyChanges();
        $driveWorkingCopyChanges->toCursor = $toCursor;
        $driveWorkingCopyChanges->changedFiles = $this->buildFiles(...$driveWorkingCopyFiles);
        return $driveWorkingCopyChanges;
    }
}
