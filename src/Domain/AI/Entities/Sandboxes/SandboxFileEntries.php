<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The entries of one workspace directory.
 *
 * @property SandboxFileEntry[] $elements
 * @method SandboxFileEntry|null first()
 * @method SandboxFileEntry|null getByUniqueKey(string $uniqueKey)
 * @method SandboxFileEntry[] getElements()
 */
class SandboxFileEntries extends ObjectSet
{
}
