<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\Drive;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the move of one manifest-store workspace into its agent space's own drive did (or, as a dry run, would do).
 */
class SandboxDriveMigrationReport extends ValueObject
{
    public string $workspaceKey = '';

    /** @var string migrated, up to date, would migrate, skipped: <reason>, migrated with conflicts (…) */
    public string $state = '';

    public ?int $driveSpaceId = null;

    public int $fileCount = 0;

    public int $byteCount = 0;

    public int $stagedCount = 0;

    public int $uploadedCount = 0;

    /** @var int Files whose content the drive already held (no upload) */
    public int $knownCount = 0;

    public int $unchangedCount = 0;

    public int $excludedCount = 0;

    public int $committedCount = 0;

    public int $conflictCount = 0;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->workspaceKey);
    }
}
