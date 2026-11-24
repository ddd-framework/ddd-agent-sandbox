<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One change of the workspace the drive has not received yet.
 */
class SandboxDriveChange extends ValueObject
{
    public const string CHANGE_ADDED = 'ADDED';
    public const string CHANGE_MODIFIED = 'MODIFIED';
    public const string CHANGE_DELETED = 'DELETED';
    public const string CHANGE_RENAMED = 'RENAMED';
    public const array CHANGES = [self::CHANGE_ADDED, self::CHANGE_MODIFIED, self::CHANGE_DELETED, self::CHANGE_RENAMED];

    /** @var string The path relative to the workspace root */
    public string $path = '';

    /** @var string ADDED, MODIFIED, DELETED or RENAMED */
    #[Choice(choices: self::CHANGES)]
    public string $changeType = self::CHANGE_MODIFIED;

    /** @var string|null The former path of a renamed file */
    public ?string $previousPath = null;

    /** @var int|null The size of the changed file in bytes */
    public ?int $sizeBytes = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->changeType . ':' . $this->path);
    }
}
