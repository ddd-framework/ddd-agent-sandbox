<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * One regular file of a saved workspace: its path relative to /workspace, the sha256 of its content (the content is
 * stored under it), size, mtime (RFC 3339, UTC) and mode, as the runner's manifest reports them; plus what feeds the
 * lifetime of the file ({@see SandboxWorkspaceZones}): the conversation that created it, the last read and hand-out.
 *
 * A plain typed class (no framework object): a manifest holds thousands of them and is compared on every call.
 */
class SandboxWorkspaceManifestFile
{
    public string $path = '';

    public string $sha256 = '';

    public int $size = 0;

    /** @var string RFC 3339 */
    public string $mtime = '';

    /** @var string octal, e.g. 0644 */
    public string $mode = '0644';

    /** @var string|null The conversation key of the call that created the file */
    public ?string $createdByConversation = null;

    /** @var string|null RFC 3339 of the last read noted for the file */
    public ?string $lastReadAt = null;

    /** @var string|null RFC 3339 of the hand-out of the file to the operator */
    public ?string $handedOutAt = null;

    /**
     * @param string $path
     * @param array<string, mixed> $storedEntry the entry as the stored manifest JSON holds it
     * @return static|null null when the entry has no content hash
     */
    public static function fromStoredEntry(string $path, array $storedEntry): ?static
    {
        if (!is_string($storedEntry['sha256'] ?? null) || $storedEntry['sha256'] === '') {
            return null;
        }
        $manifestFile = new static();
        $manifestFile->path = $path;
        $manifestFile->sha256 = $storedEntry['sha256'];
        $manifestFile->size = (int)($storedEntry['size'] ?? 0);
        $manifestFile->mtime = (string)($storedEntry['mtime'] ?? '');
        $manifestFile->mode = (string)($storedEntry['mode'] ?? '0644');
        $manifestFile->createdByConversation = static::readOptionalText($storedEntry, 'createdByConversation');
        $manifestFile->lastReadAt = static::readOptionalText($storedEntry, 'lastReadAt');
        $manifestFile->handedOutAt = static::readOptionalText($storedEntry, 'handedOutAt');
        return $manifestFile;
    }

    /**
     * @return array<string, mixed> the entry as the stored manifest JSON holds it (optional keys only when set)
     */
    public function toStoredEntry(): array
    {
        $storedEntry = ['sha256' => $this->sha256, 'size' => $this->size, 'mtime' => $this->mtime, 'mode' => $this->mode];
        foreach (['createdByConversation', 'lastReadAt', 'handedOutAt'] as $optionalName) {
            if ($this->$optionalName !== null && $this->$optionalName !== '') {
                $storedEntry[$optionalName] = $this->$optionalName;
            }
        }
        return $storedEntry;
    }

    /**
     * @param array<string, mixed> $storedEntry
     * @param string $name
     * @return string|null
     */
    protected static function readOptionalText(array $storedEntry, string $name): ?string
    {
        return is_string($storedEntry[$name] ?? null) && $storedEntry[$name] !== '' ? $storedEntry[$name] : null;
    }
}
