<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Domain\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceSyncResult;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Tests\AgentSandbox\Fakes\TestableSandboxWorkspacesService;
use PHPUnit\Framework\TestCase;

/** Workspaces on a fake runner: claim and restore, write-behind and flush, restart after a lost session, capacity, expiry, preload */
class SandboxWorkspacesServiceTest extends TestCase
{
    public function testAClaimRestoresTheStoredFilesAndAWriteIsSavedByTheFlush(): void
    {
        $sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $sandboxWorkspacesService->createWorkspaceFromFiles('space-7', ['NOTES.md' => "# notes\n", 'lib/tool.py' => "print(1)\n"]);
        $sandboxFileWrite = $sandboxWorkspacesService->writeWorkspaceFileBytes('space-7', 'out/report.csv', "a;b\n1;2\n", 'conversation-1');
        $sessionId = $sandboxWorkspacesService->fakeSandboxRunnerClientService->sessionIdsByAddress['10.0.0.1:8080'];
        self::assertSame("# notes\n", $sandboxWorkspacesService->fakeSandboxRunnerClientService->filesBySessionId[$sessionId]['NOTES.md'], 'the claim restored the stored files');
        self::assertSame(SandboxWorkspaceSyncResult::CHANGE_NEW, $sandboxFileWrite->syncResult->changes[0]->change);
        self::assertSame(['space-7'], $sandboxWorkspacesService->startedFlushProcesses, 'the upload is left to a flush process');
        self::assertNotNull($sandboxWorkspacesService->findDirtyMark('space-7'));
        self::assertFalse($sandboxWorkspacesService->getSavedManifestFromStore('space-7')->files->hasPath('out/report.csv'), 'not in the store before the flush');

        $sandboxWorkspacesService->flushPendingChanges('space-7');

        $savedManifest = $sandboxWorkspacesService->getSavedManifestFromStore('space-7');
        self::assertTrue($savedManifest->files->hasPath('out/report.csv'));
        self::assertSame('conversation-1', $savedManifest->files->findByPath('out/report.csv')->createdByConversation);
        self::assertNull($sandboxWorkspacesService->findDirtyMark('space-7'));
        self::assertSame("a;b\n1;2\n", $sandboxWorkspacesService->inMemorySandboxWorkspaceStore->readBlob('space-7', hash('sha256', "a;b\n1;2\n")));
    }

    public function testAReleaseSavesFirstAndTheNextCallRestoresTheSavedState(): void
    {
        $sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $sandboxWorkspacesService->writeWorkspaceFileBytes('space-8', 'out/a.txt', 'one', 'conversation-1');
        self::assertTrue($sandboxWorkspacesService->releaseSession('space-8'));
        self::assertNull($sandboxWorkspacesService->findSession('space-8'));
        self::assertTrue($sandboxWorkspacesService->getSavedManifestFromStore('space-8')->files->hasPath('out/a.txt'));
        self::assertSame('one', $sandboxWorkspacesService->readWorkspaceFileBytes('space-8', 'out/a.txt'));
        self::assertSame(2, $sandboxWorkspacesService->fakeSandboxRunnerClientService->claimCount);
    }

    public function testACallWhoseSessionIsGoneClaimsAgainAndSaysSo(): void
    {
        $sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $sandboxWorkspacesService->createWorkspaceFromFiles('space-9', ['NOTES.md' => 'kept']);
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-9', 'NOTES.md');
        $sandboxWorkspacesService->fakeSandboxRunnerClientService->dropSessionOf('10.0.0.1:8080');
        $sandboxWorkspacesService->startCallTimings();
        self::assertSame('kept', $sandboxWorkspacesService->readWorkspaceFileBytes('space-9', 'NOTES.md'));
        self::assertTrue($sandboxWorkspacesService->lastCallRestartedSession);
    }

    public function testEveryBusyPodRefusesClearlyAfterReleasingIdleSessionsOnce(): void
    {
        $sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-1', 'missing.txt');
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Every sandbox slot is in use');
        $sandboxWorkspacesService->readWorkspaceFileBytes('space-2', 'missing.txt');
    }

    public function testAnIdleSessionIsReleasedForAClaimOfAnotherWorkspace(): void
    {
        $sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $sandboxWorkspacesService->writeWorkspaceFileBytes('space-1', 'out/x.txt', 'x', 'conversation-1');
        $cachedSession = $sandboxWorkspacesService->findSession('space-1');
        $cachedSession->lastUsedAt = time() - 3600;
        $sandboxWorkspacesService->inMemorySandboxCache->set('sandboxWorkspace.session.space-1', $cachedSession->toArray());
        self::assertNull($sandboxWorkspacesService->readWorkspaceFileBytes('space-2', 'missing.txt'));
        self::assertNull($sandboxWorkspacesService->findSession('space-1'));
        self::assertTrue($sandboxWorkspacesService->getSavedManifestFromStore('space-1')->files->hasPath('out/x.txt'), 'saved before it was released');
    }

    public function testKeysAreChecked(): void
    {
        $sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        foreach (['../x', 'system.preload', '', 'a b'] as $invalidKey) {
            try {
                $sandboxWorkspacesService->ensureSession($invalidKey);
                self::fail('accepted ' . $invalidKey);
            } catch (BadRequestException) {
                self::assertTrue(true);
            }
        }
    }

    public function testThePreloadIsRestoredReadOnlyAndNeverSaved(): void
    {
        $sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $sandboxWorkspacesService->seedPreloadContent(['kit/README.md' => 'kit', 'start.sh' => 'echo ok > out/started.txt']);
        $sandboxWorkspacesService->writeWorkspaceFileBytes('space-3', 'out/b.txt', 'b', 'conversation-1');
        $fakeRunner = $sandboxWorkspacesService->fakeSandboxRunnerClientService;
        $sessionId = $fakeRunner->sessionIdsByAddress['10.0.0.1:8080'];
        self::assertSame('kit', $fakeRunner->filesBySessionId[$sessionId]['.preload/kit/README.md']);
        self::assertSame('/workspace/.preload', $fakeRunner->environmentsBySessionId[$sessionId]['SANDBOX_PRELOAD']);
        self::assertContains("sh '/workspace/.preload/start.sh'", $fakeRunner->executedCommands);
        $sandboxWorkspacesService->flushPendingChanges('space-3');
        self::assertSame(['out/b.txt'], $sandboxWorkspacesService->getSavedManifestFromStore('space-3')->files->getPaths());
    }

    public function testTheExpiryRemovesOldScratchAndKeepsLib(): void
    {
        $sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $sandboxWorkspacesService->createWorkspaceFromFiles('space-4', ['work/conversation-1/tmp.csv' => 'x', 'lib/keep.py' => 'y', 'out/r.xlsx' => 'z']);
        $expiryReports = $sandboxWorkspacesService->expireWorkspaces(false, strtotime('2027-01-10T00:00:00Z'));
        $expiryReport = $expiryReports->findByWorkspaceKey('space-4');
        self::assertSame(['out/r.xlsx', 'work/conversation-1/tmp.csv'], array_values(array_intersect(['out/r.xlsx', 'work/conversation-1/tmp.csv'], $expiryReport->expiredPaths)));
        self::assertSame(['lib/keep.py'], $sandboxWorkspacesService->getSavedManifestFromStore('space-4')->files->getPaths());
    }

    public function testThePreloadNeverExpires(): void
    {
        $sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $sandboxWorkspacesService->seedPreloadContent(['README.md' => 'kit']);
        $expiryReports = $sandboxWorkspacesService->expireWorkspaces(false, strtotime('2030-01-01T00:00:00Z'));
        self::assertNull($expiryReports->findByWorkspaceKey(SandboxWorkspacesService::PRELOAD_WORKSPACE_KEY));
        self::assertSame(1, $sandboxWorkspacesService->getSavedManifestFromStore(SandboxWorkspacesService::PRELOAD_WORKSPACE_KEY)->countFiles());
    }

    public function testTheStateNoteIsShownOncePerConversation(): void
    {
        $sandboxWorkspacesService = new TestableSandboxWorkspacesService();
        $sandboxWorkspacesService->createWorkspaceFromFiles('space-5', ['NOTES.md' => "remember this\n"]);
        $stateNote = $sandboxWorkspacesService->buildStateNoteOnce('space-5', 'conversation-3', 'the workspace of this space');
        self::assertStringContainsString('remember this', (string)$stateNote);
        self::assertStringContainsString('space-5 (the workspace of this space)', (string)$stateNote);
        self::assertNull($sandboxWorkspacesService->buildStateNoteOnce('space-5', 'conversation-3'));
        self::assertNull($sandboxWorkspacesService->buildStateNoteOnce('space-5', SandboxWorkspacesService::CONVERSATION_KEY_WITHOUT_CONVERSATION));
    }

    public function testExcludedPathsAreNeverSaved(): void
    {
        self::assertTrue(SandboxWorkspacesService::isExcludedPath('node_modules/x/index.js'));
        self::assertTrue(SandboxWorkspacesService::isExcludedPath('.preload/kit/a.py'));
        self::assertFalse(SandboxWorkspacesService::isExcludedPath('out/report.xlsx'));
    }
}
