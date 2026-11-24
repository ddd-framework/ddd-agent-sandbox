<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The changes of a workspace the drive has not received yet.
 *
 * @property SandboxDriveChange[] $elements
 * @method SandboxDriveChange|null first()
 * @method SandboxDriveChange|null getByUniqueKey(string $uniqueKey)
 * @method SandboxDriveChange[] getElements()
 */
class SandboxDriveChanges extends ObjectSet
{
}
