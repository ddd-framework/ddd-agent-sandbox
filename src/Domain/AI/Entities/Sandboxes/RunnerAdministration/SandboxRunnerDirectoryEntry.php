<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\RunnerAdministration;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerAnswerReader;
use DDD\Domain\AI\Exceptions\Sandboxes\SandboxRunnerException;
use DateTimeImmutable;

/**
 * One entry of a directory listing of a session's workspace (`GET /v1/sessions/{id}/files?path=`).
 */
class SandboxRunnerDirectoryEntry
{
    public const string TYPE_FILE = 'file';
    public const string TYPE_DIRECTORY = 'dir';
    public const string TYPE_SYMLINK = 'symlink';
    public const string TYPE_OTHER = 'other';

    public string $name = '';

    /** @var string file, dir, symlink or other */
    public string $type = self::TYPE_FILE;

    public int $size = 0;

    /** @var DateTimeImmutable|null null for drive mount entries (the drive state carries no time) */
    public ?DateTimeImmutable $modifiedAt = null;

    /** @var bool A drive file only listed (placeholder), not in the working copy */
    public bool $isNotLocal = false;

    /**
     * @param SandboxRunnerAnswerReader $reader
     * @return static
     * @throws SandboxRunnerException
     */
    public static function fromReader(SandboxRunnerAnswerReader $reader): static
    {
        $directoryEntry = new static();
        $directoryEntry->name = $reader->requireString('name');
        $directoryEntry->type = $reader->requireString('type');
        if (!in_array($directoryEntry->type, [static::TYPE_FILE, static::TYPE_DIRECTORY, static::TYPE_SYMLINK, static::TYPE_OTHER], true)) {
            throw SandboxRunnerAnswerReader::invalid($reader->context . '.type', 'is no known entry type');
        }
        $directoryEntry->size = $reader->requireInt('size');
        $directoryEntry->modifiedAt = $reader->findMoment('mtime');
        $directoryEntry->isNotLocal = $reader->findBool('notLocal');
        return $directoryEntry;
    }
}
