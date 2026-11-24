<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The files of a saved workspace by path. A plain typed collection (no framework object set): manifests hold thousands
 * of files and are compared on every call.
 *
 * @implements IteratorAggregate<string, SandboxWorkspaceManifestFile>
 */
class SandboxWorkspaceManifestFiles implements IteratorAggregate, Countable
{
    /** @var SandboxWorkspaceManifestFile[] by path */
    protected array $filesByPath = [];

    public function findByPath(string $path): ?SandboxWorkspaceManifestFile
    {
        return $this->filesByPath[$path] ?? null;
    }

    public function hasPath(string $path): bool
    {
        return isset($this->filesByPath[$path]);
    }

    public function add(SandboxWorkspaceManifestFile $manifestFile): void
    {
        $this->filesByPath[$manifestFile->path] = $manifestFile;
    }

    public function removeByPath(string $path): void
    {
        unset($this->filesByPath[$path]);
    }

    /**
     * @return string[]
     */
    public function getPaths(): array
    {
        return array_map('strval', array_keys($this->filesByPath));
    }

    /**
     * @return SandboxWorkspaceManifestFile[] in insertion order
     */
    public function getElements(): array
    {
        return array_values($this->filesByPath);
    }

    public function count(): int
    {
        return count($this->filesByPath);
    }

    public function sumBytes(): int
    {
        $totalBytes = 0;
        foreach ($this->filesByPath as $manifestFile) {
            $totalBytes += $manifestFile->size;
        }
        return $totalBytes;
    }

    /**
     * @return Traversable<string, SandboxWorkspaceManifestFile> by path
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->filesByPath);
    }
}
