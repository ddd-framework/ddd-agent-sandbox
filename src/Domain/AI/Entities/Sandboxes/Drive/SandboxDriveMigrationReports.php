<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\Drive;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The reports of a move of manifest-store workspaces into the drive, by workspace key.
 *
 * @property SandboxDriveMigrationReport[] $elements
 * @method SandboxDriveMigrationReport|null first()
 * @method SandboxDriveMigrationReport|null getByUniqueKey(string $uniqueKey)
 * @method SandboxDriveMigrationReport[] getElements()
 */
class SandboxDriveMigrationReports extends ObjectSet
{
}
