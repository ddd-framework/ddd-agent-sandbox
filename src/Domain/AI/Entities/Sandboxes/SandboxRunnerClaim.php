<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/** A session a runner pod granted: the pod's address and the runner's claim answer (session id, uid, restored files) */
class SandboxRunnerClaim
{
    public string $runnerAddress = '';

    public string $sessionId = '';

    public int $restoredFiles = 0;

    /** @var array<string, mixed> The runner's claim answer as it came (a live store reads its mode's part) */
    public array $claimAnswer = [];
}
