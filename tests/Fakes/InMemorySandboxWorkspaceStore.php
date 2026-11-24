<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceManifest;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceManifestFile;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspaceStore;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/** The workspace store of the tests: manifests and contents in memory */
class InMemorySandboxWorkspaceStore implements SandboxWorkspaceStore
{
    /** @var array<string, array<string, mixed>> stored manifest JSON by workspace key */
    public array $manifestsByKey = [];

    /** @var array<string, string> content by "<key>/<sha256>" */
    public array $contentsByKey = [];

    public function getStoreName(): string
    {
        return 'memory';
    }

    public function readManifest(string $workspaceKey): SandboxWorkspaceManifest
    {
        return isset($this->manifestsByKey[$workspaceKey]) ? SandboxWorkspaceManifest::fromArray($this->manifestsByKey[$workspaceKey]) : new SandboxWorkspaceManifest();
    }

    public function writeManifest(string $workspaceKey, SandboxWorkspaceManifest $sandboxWorkspaceManifest): void
    {
        $this->manifestsByKey[$workspaceKey] = json_decode((string)json_encode($sandboxWorkspaceManifest->toArray()), true);
    }

    public function readBlob(string $workspaceKey, string $sha256): string
    {
        if (!isset($this->contentsByKey[$workspaceKey . '/' . $sha256])) {
            throw new InternalErrorException('missing content ' . $sha256);
        }
        return $this->contentsByKey[$workspaceKey . '/' . $sha256];
    }

    public function readBlobs(string $workspaceKey, array $sha256s): array
    {
        $contentsBySha256 = [];
        foreach ($sha256s as $sha256) {
            $contentsBySha256[$sha256] = $this->readBlob($workspaceKey, $sha256);
        }
        return $contentsBySha256;
    }

    public function writeBlob(string $workspaceKey, string $sha256, string $content): void
    {
        $this->contentsByKey[$workspaceKey . '/' . $sha256] = $content;
    }

    public function deleteBlob(string $workspaceKey, string $sha256): void
    {
        unset($this->contentsByKey[$workspaceKey . '/' . $sha256]);
    }

    public function listWorkspaceKeys(): array
    {
        $workspaceKeys = array_keys($this->manifestsByKey);
        sort($workspaceKeys);
        return $workspaceKeys;
    }

    public function deleteWorkspace(string $workspaceKey): int
    {
        $removedObjects = isset($this->manifestsByKey[$workspaceKey]) ? 1 : 0;
        unset($this->manifestsByKey[$workspaceKey]);
        foreach (array_keys($this->contentsByKey) as $contentKey) {
            if (str_starts_with($contentKey, $workspaceKey . '/')) {
                unset($this->contentsByKey[$contentKey]);
                $removedObjects++;
            }
        }
        return $removedObjects;
    }

    public function createWorkspaceFromFiles(string $workspaceKey, array $contentsByPath): SandboxWorkspaceManifest
    {
        $this->deleteWorkspace($workspaceKey);
        $sandboxWorkspaceManifest = new SandboxWorkspaceManifest();
        foreach ($contentsByPath as $path => $content) {
            $manifestFile = new SandboxWorkspaceManifestFile();
            $manifestFile->path = (string)$path;
            $manifestFile->sha256 = hash('sha256', $content);
            $manifestFile->size = strlen($content);
            $manifestFile->mtime = '2026-11-24T10:00:00Z';
            $this->writeBlob($workspaceKey, $manifestFile->sha256, $content);
            $sandboxWorkspaceManifest->files->add($manifestFile);
        }
        $sandboxWorkspaceManifest->version = 1;
        $this->writeManifest($workspaceKey, $sandboxWorkspaceManifest);
        return $sandboxWorkspaceManifest;
    }
}
