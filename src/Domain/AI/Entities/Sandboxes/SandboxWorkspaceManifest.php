<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * The saved state of one workspace (the manifest store, {@see \DDD\Domain\AI\Services\Sandboxes\SandboxManifestWorkspaceStoreService}):
 * every regular file by its path with sha256, size, mtime (RFC 3339, UTC) and mode, as the runner's manifest reports
 * them, and the last activity of each conversation that worked here (the lifetime of scratch counts from it).
 */
class SandboxWorkspaceManifest
{
    /** @var int Bumped by every save that changed something */
    public int $version = 0;

    /** @var string|null RFC 3339 of the last save */
    public ?string $savedAt = null;

    public SandboxWorkspaceManifestFiles $files;

    /** @var string[] RFC 3339 of the last call of each conversation that worked here, by conversation key (work/<conversation>/ expires from it) */
    public array $conversationsLastActiveAt = [];

    public function __construct()
    {
        $this->files = new SandboxWorkspaceManifestFiles();
    }

    /**
     * @param array<string, mixed> $storedManifest the decoded manifest JSON
     * @return static
     */
    public static function fromArray(array $storedManifest): static
    {
        $sandboxWorkspaceManifest = new static();
        $sandboxWorkspaceManifest->version = (int)($storedManifest['version'] ?? 0);
        $sandboxWorkspaceManifest->savedAt = isset($storedManifest['savedAt']) ? (string)$storedManifest['savedAt'] : null;
        foreach ((array)($storedManifest['files'] ?? []) as $path => $storedEntry) {
            if (!is_array($storedEntry)) {
                continue;
            }
            $manifestFile = SandboxWorkspaceManifestFile::fromStoredEntry((string)$path, $storedEntry);
            if ($manifestFile !== null) {
                $sandboxWorkspaceManifest->files->add($manifestFile);
            }
        }
        foreach ((array)($storedManifest['conversationsLastActiveAt'] ?? []) as $conversationKey => $lastActiveAt) {
            if (is_string($lastActiveAt) && $lastActiveAt !== '') {
                $sandboxWorkspaceManifest->conversationsLastActiveAt[(string)$conversationKey] = $lastActiveAt;
            }
        }
        return $sandboxWorkspaceManifest;
    }

    /**
     * @return array<string, mixed> the manifest JSON as stored
     */
    public function toArray(): array
    {
        $storedFiles = [];
        foreach ($this->files as $path => $manifestFile) {
            $storedFiles[$path] = $manifestFile->toStoredEntry();
        }
        return [
            'version' => $this->version,
            'savedAt' => $this->savedAt,
            'files' => (object)$storedFiles,
            'conversationsLastActiveAt' => (object)$this->conversationsLastActiveAt,
        ];
    }

    /**
     * Notes that conversations worked here and which files they read (the lifetime of scratch counts from it).
     *
     * @param SandboxWorkspaceActivityRecord $activityRecord
     * @return bool Whether anything moved forward
     */
    public function mergeActivity(SandboxWorkspaceActivityRecord $activityRecord): bool
    {
        $changed = false;
        foreach ($activityRecord->conversationsLastActiveAt as $conversationKey => $lastActiveAt) {
            if (strcmp($lastActiveAt, $this->conversationsLastActiveAt[$conversationKey] ?? '') > 0) {
                $this->conversationsLastActiveAt[$conversationKey] = $lastActiveAt;
                $changed = true;
            }
        }
        foreach ($activityRecord->filesLastReadAt as $path => $lastReadAt) {
            $manifestFile = $this->files->findByPath((string)$path);
            if ($manifestFile !== null && strcmp($lastReadAt, $manifestFile->lastReadAt ?? '') > 0) {
                $manifestFile->lastReadAt = $lastReadAt;
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * @param string $zone one of SandboxWorkspaceZones::ZONE_
     * @return int
     */
    public function sumBytesOfZone(string $zone): int
    {
        $totalBytes = 0;
        foreach ($this->files as $path => $manifestFile) {
            if (SandboxWorkspaceZones::findZoneOfPath((string)$path) === $zone) {
                $totalBytes += $manifestFile->size;
            }
        }
        return $totalBytes;
    }

    public function countFiles(): int
    {
        return $this->files->count();
    }

    public function sumBytes(): int
    {
        return $this->files->sumBytes();
    }

    /**
     * @param string $sha256
     * @return bool Whether a file of this manifest has this content (it is stored already)
     */
    public function holdsContent(string $sha256): bool
    {
        foreach ($this->files as $manifestFile) {
            if ($manifestFile->sha256 === $sha256) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $limit
     * @return SandboxWorkspaceManifestFile[] the newest first
     */
    public function findRecentlyChangedFiles(int $limit): array
    {
        $manifestFiles = $this->files->getElements();
        usort($manifestFiles, [static::class, 'compareByMtimeDescending']);
        return array_slice($manifestFiles, 0, max(0, $limit));
    }

    public static function compareByMtimeDescending(SandboxWorkspaceManifestFile $manifestFileA, SandboxWorkspaceManifestFile $manifestFileB): int
    {
        return strcmp($manifestFileB->mtime, $manifestFileA->mtime);
    }
}
