<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Domain\Sandboxes;

use DDD\Domain\AI\Services\Sandboxes\SandboxManifestWorkspaceStoreService;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Tests\AgentSandbox\Fakes\InMemorySandboxCache;
use PHPUnit\Framework\TestCase;

/** The built-in manifest store on a filesystem object store */
class SandboxManifestWorkspaceStoreServiceTest extends TestCase
{
    public function testAWorkspaceIsStoredByManifestAndContentHashes(): void
    {
        $storeDirectory = sys_get_temp_dir() . '/sandbox-store-test-' . getmypid() . '-' . random_int(1, 99999);
        mkdir($storeDirectory, 0777, true);
        $sandboxManifestWorkspaceStoreService = new class extends SandboxManifestWorkspaceStoreService {
            public ?InMemorySandboxCache $testCache = null;

            protected function getCache(): Cache
            {
                return $this->testCache ??= new InMemorySandboxCache();
            }
        };
        $sandboxManifestWorkspaceStoreService->setObjectStorage(new FilesystemObjectStorage($storeDirectory));
        try {
            $sandboxWorkspaceManifest = $sandboxManifestWorkspaceStoreService->createWorkspaceFromFiles('space-1', ['a.txt' => 'same', 'b/c.txt' => 'same', 'd.txt' => 'other']);
            self::assertSame(3, $sandboxWorkspaceManifest->countFiles());
            self::assertSame(['space-1'], $sandboxManifestWorkspaceStoreService->listWorkspaceKeys());
            self::assertSame(3, $sandboxManifestWorkspaceStoreService->readManifest('space-1')->countFiles());
            self::assertSame('same', $sandboxManifestWorkspaceStoreService->readBlob('space-1', hash('sha256', 'same')));
            self::assertSame(
                [hash('sha256', 'other') => 'other', hash('sha256', 'same') => 'same'],
                $sandboxManifestWorkspaceStoreService->readBlobs('space-1', [hash('sha256', 'other'), hash('sha256', 'same')])
            );
            self::assertSame(3, $sandboxManifestWorkspaceStoreService->deleteWorkspace('space-1'), 'one manifest and two contents (same content stored once)');
            self::assertSame(0, $sandboxManifestWorkspaceStoreService->readManifest('space-1')->countFiles());
            $this->expectException(InternalErrorException::class);
            $sandboxManifestWorkspaceStoreService->createWorkspaceFromFiles('space-2', ['../escape.txt' => 'x']);
        } finally {
            exec('rm -rf ' . escapeshellarg($storeDirectory));
        }
    }
}
