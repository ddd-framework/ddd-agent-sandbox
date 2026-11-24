<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One entry of a workspace directory. Names and paths are foreign text (the agent and its tools wrote them): a screen
 * shows them as text only.
 */
class SandboxFileEntry extends ValueObject
{
    public const string TYPE_FILE = 'FILE';
    public const string TYPE_DIRECTORY = 'DIRECTORY';
    public const string TYPE_SYMLINK = 'SYMLINK';
    public const array TYPES = [self::TYPE_FILE, self::TYPE_DIRECTORY, self::TYPE_SYMLINK];

    /** @var string The entry's name */
    public string $name = '';

    /** @var string The path relative to the workspace root, `/`-separated */
    public string $path = '';

    /** @var string FILE, DIRECTORY or SYMLINK */
    #[Choice(choices: self::TYPES)]
    public string $type = self::TYPE_FILE;

    /** @var int|null The file's size in bytes; null for a directory */
    public ?int $sizeBytes = null;

    /** @var DateTime|null The last change */
    public ?DateTime $modifiedAt = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->path);
    }
}
