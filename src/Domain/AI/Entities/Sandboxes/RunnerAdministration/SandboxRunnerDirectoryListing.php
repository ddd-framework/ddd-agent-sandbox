<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerAnswerReader;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;

/**
 * One directory of a session's workspace (`GET /v1/sessions/{id}/files?path=`): folders first, at most 2,000 entries.
 */
class SandboxRunnerDirectoryListing
{
    /** @var string Workspace-relative, empty for the root */
    public string $path = '';

    /** @var SandboxRunnerDirectoryEntry[] */
    public array $entries = [];

    /** @var bool More than 2,000 entries: the rest is left out */
    public bool $isTruncated = false;

    /**
     * @param array<string, mixed> $data
     * @return static
     * @throws SandboxRunnerException
     */
    public static function fromArray(array $data): static
    {
        $reader = SandboxRunnerAnswerReader::of($data, 'files answer');
        $directoryListing = new static();
        $directoryListing->path = $reader->requireString('path');
        foreach ($reader->requireObjectList('entries') as $entryReader) {
            $directoryListing->entries[] = SandboxRunnerDirectoryEntry::fromReader($entryReader);
        }
        $directoryListing->isTruncated = $reader->requireBool('truncated');
        return $directoryListing;
    }
}
