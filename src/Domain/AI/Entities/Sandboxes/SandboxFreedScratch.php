<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/** Scratch files of other conversations removed from a full workspace to make room */
class SandboxFreedScratch
{
    /** @var string[] */
    public array $removedPaths = [];

    public int $removedBytes = 0;
}
