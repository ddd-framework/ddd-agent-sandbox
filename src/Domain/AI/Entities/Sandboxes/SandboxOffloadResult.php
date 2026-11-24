<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What an offload did: the drive changes committed before the session was released.
 */
class SandboxOffloadResult extends ValueObject
{
    /** @var int Changes committed to the drive */
    public int $committedChangeCount = 0;

    /** @var bool The runner session was released */
    public bool $wasSessionReleased = false;

    /** @var string|null A short note (e.g. why a change could not be committed) */
    public ?string $detail = null;
}
