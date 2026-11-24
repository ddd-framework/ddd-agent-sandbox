<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The last commands of a workspace session, newest first.
 *
 * @property SandboxCommand[] $elements
 * @method SandboxCommand|null first()
 * @method SandboxCommand|null getByUniqueKey(string $uniqueKey)
 * @method SandboxCommand[] getElements()
 */
class SandboxCommands extends ObjectSet
{
}
