<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * What one save of a workspace changed: one entry per path (new, changed, deleted, or not saved because it is larger
 * than `AI.Sandbox.workspaces.maxSyncedFileBytes`), and how long the save took.
 */
class SandboxWorkspaceSyncResult
{
    public const string CHANGE_NEW = 'new';

    public const string CHANGE_CHANGED = 'changed';

    public const string CHANGE_DELETED = 'deleted';

    public const string CHANGE_NOT_SAVED = 'NOT SAVED, too large';

    /** @var int The most paths one result line names */
    public const int MAX_PATHS_IN_LINE = 12;

    /** @var SandboxWorkspaceChange[] in the order found */
    public array $changes = [];

    public int $durationMilliseconds = 0;

    /** @var int Contents uploaded to the store */
    public int $uploadedContents = 0;

    /** @var int Bytes uploaded to the store */
    public int $uploadedBytes = 0;

    /**
     * @param string $path
     * @param string $change
     * @param int $size
     * @param string|null $expiryLabel what the line says about the file's lifetime (SandboxWorkspaceZones)
     * @return void
     */
    public function addChange(string $path, string $change, int $size, ?string $expiryLabel = null): void
    {
        $sandboxWorkspaceChange = new SandboxWorkspaceChange();
        $sandboxWorkspaceChange->path = $path;
        $sandboxWorkspaceChange->change = $change;
        $sandboxWorkspaceChange->size = $size;
        $sandboxWorkspaceChange->expiryLabel = $expiryLabel;
        $this->changes[] = $sandboxWorkspaceChange;
    }

    /**
     * @param string $path
     * @return bool Whether the result names the path already
     */
    public function namesPath(string $path): bool
    {
        foreach ($this->changes as $change) {
            if ($change->path === $path) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool
     */
    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }

    /**
     * `changed: out/a.xlsx (new, 5.2 KB, kept 30 days) · NOTES.md (changed, 0.4 KB, kept) · tmp.txt (deleted)`;
     * null without a change.
     *
     * @return string|null
     */
    public function toLlmLine(): ?string
    {
        if ($this->changes === []) {
            return null;
        }
        $parts = [];
        foreach (array_slice($this->changes, 0, static::MAX_PATHS_IN_LINE) as $change) {
            $parts[] = $change->change === static::CHANGE_DELETED
                ? $change->path . ' (deleted)'
                : $change->path . ' (' . $change->change . ', ' . static::formatBytes($change->size) . ($change->expiryLabel !== null ? ', ' . $change->expiryLabel : '') . ')';
        }
        $leftOut = count($this->changes) - static::MAX_PATHS_IN_LINE;
        return 'changed: ' . implode(' · ', $parts) . ($leftOut > 0 ? ' · and ' . $leftOut . ' more' : '');
    }

    /**
     * @param int $bytes
     * @return string
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, '.', '') . ' KB';
        }
        return number_format($bytes / 1024 / 1024, 1, '.', '') . ' MB';
    }
}
