<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\Drive;

/**
 * What a seed of the library space did (or, as a dry run, would do).
 */
class SandboxDriveLibrarySeedResult
{
    /** @var int|null The library space; null when a dry run found none */
    public ?int $driveSpaceId = null;

    public bool $isDryRun = false;

    public bool $wasSpaceCreated = false;

    public int $fileCount = 0;

    public int $unchangedCount = 0;

    /** @var string[] The paths written (or to write) */
    public array $writtenPaths = [];

    /** @var string[] Paths that were refused, each with its reason */
    public array $refusedLines = [];

    /**
     * @return string[] lines for the seed command's output
     */
    public function buildLines(): array
    {
        $lines = [sprintf(
            'library space %s%s: %d files, %d unchanged, %d %s%s',
            $this->driveSpaceId !== null ? (string)$this->driveSpaceId : '(new)',
            $this->wasSpaceCreated ? ' created' : '',
            $this->fileCount,
            $this->unchangedCount,
            count($this->writtenPaths),
            $this->isDryRun ? 'to write (dry run)' : 'written',
            $this->refusedLines !== [] ? ', ' . count($this->refusedLines) . ' refused' : ''
        )];
        foreach ($this->refusedLines as $refusedLine) {
            $lines[] = '  refused: ' . $refusedLine;
        }
        return $lines;
    }
}
