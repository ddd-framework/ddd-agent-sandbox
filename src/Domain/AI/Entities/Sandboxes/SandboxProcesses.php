<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The processes of a workspace session.
 *
 * @property SandboxProcess[] $elements
 * @method SandboxProcess|null first()
 * @method SandboxProcess|null getByUniqueKey(string $uniqueKey)
 * @method SandboxProcess[] getElements()
 */
class SandboxProcesses extends ObjectSet
{
}
