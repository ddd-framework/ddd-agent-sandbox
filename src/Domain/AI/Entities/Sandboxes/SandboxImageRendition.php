<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/** A picture rendered to look at: its bytes, type and size */
class SandboxImageRendition
{
    public string $bytes = '';

    /** @var string image/png or image/jpeg */
    public string $mimeType = '';

    public int $width = 0;

    public int $height = 0;
}
