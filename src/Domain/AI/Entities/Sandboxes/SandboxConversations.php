<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The newest root conversations of a workspace's agent space.
 *
 * @property SandboxConversation[] $elements
 * @method SandboxConversation|null first()
 * @method SandboxConversation|null getByUniqueKey(string $uniqueKey)
 * @method SandboxConversation[] getElements()
 */
class SandboxConversations extends ObjectSet
{
}
