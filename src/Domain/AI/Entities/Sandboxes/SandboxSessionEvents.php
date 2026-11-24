<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The newest entries of a session's log, newest first.
 *
 * @property SandboxSessionEvent[] $elements
 * @method SandboxSessionEvent|null first()
 * @method SandboxSessionEvent|null getByUniqueKey(string $uniqueKey)
 * @method SandboxSessionEvent[] getElements()
 */
class SandboxSessionEvents extends ObjectSet
{
}
