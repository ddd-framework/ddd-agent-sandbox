<?php

declare(strict_types=1);

/**
 * Raw sink calls of this package that are fine, each with its reason (skill ddd-untrusted-input-specialist).
 * Checked by tests/UntrustedSinkScanTest.php: a new raw call fails until it goes through a helper of
 * DDD\Infrastructure\Untrusted or is listed here; an entry without a matching call fails too.
 */
return [
    ['file' => 'src/Domain/AI/Services/Sandboxes/SandboxRunnerClientService.php', 'sink' => 'http-client', 'reason' => 'client of the configured runner pods (AI.Sandbox.runner.host), never a URL from outside'],
    ['file' => 'src/Domain/AI/Services/Sandboxes/SandboxManifestWorkspaceStoreService.php', 'sink' => 'http-client', 'reason' => 'reads presigned URLs of the configured object store, which this service created itself'],
    ['file' => 'src/Domain/AI/Services/Sandboxes/SandboxWorkspacesService.php', 'sink' => 'shell', 'reason' => 'starts our own console command (flush) detached: fixed command, the workspace key checked against a plain-key pattern, every argument escaped'],
    ['file' => 'src/Domain/AI/Services/Sandboxes/SandboxImageConfigService.php', 'sink' => 'yaml-parse', 'reason' => 'the project\'s own config/app/AI/Sandbox/image.yaml, no object flags'],
    ['file' => 'src/Symfony/Commands/Sandbox/SandboxBuildImage.php', 'sink' => 'process', 'reason' => 'docker buildx build as an argument array on the context this command wrote; operator console only'],
];
