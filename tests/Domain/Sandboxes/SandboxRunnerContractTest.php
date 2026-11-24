<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Domain\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerContract;
use DDD\Domain\AI\Entities\Sandboxes\SandboxRunnerHealth;
use PHPUnit\Framework\TestCase;

class SandboxRunnerContractTest extends TestCase
{
    public function testWhileTheMajorIsZeroTheMinorMustMatch(): void
    {
        self::assertTrue(SandboxRunnerContract::isCompatibleContractVersion('0.1.0'));
        self::assertTrue(SandboxRunnerContract::isCompatibleContractVersion('0.1.7'));
        self::assertTrue(SandboxRunnerContract::isCompatibleContractVersion('0.1.3-rc.1'));
        self::assertFalse(SandboxRunnerContract::isCompatibleContractVersion('0.2.0'));
        self::assertFalse(SandboxRunnerContract::isCompatibleContractVersion('1.1.0'));
        self::assertFalse(SandboxRunnerContract::isCompatibleContractVersion('0.0.0-dev'));
        self::assertFalse(SandboxRunnerContract::isCompatibleContractVersion(null));
        self::assertFalse(SandboxRunnerContract::isCompatibleContractVersion('latest'));
    }

    public function testTheContractVersionIsThePackageVersion(): void
    {
        $composerJson = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
        self::assertSame(SandboxRunnerContract::extractCoreVersion($composerJson['version']), SandboxRunnerContract::CONTRACT_VERSION);
    }

    public function testHealthzIsRead(): void
    {
        $sandboxRunnerHealth = SandboxRunnerHealth::fromArray([
            'status' => 'ok',
            'state' => 'free',
            'contractVersion' => '0.1.0',
            'toolkit' => 'full',
            'imageConfigHash' => 'abc',
            'capabilities' => ['drive' => true, 'fuse' => false, 'transferProxy' => true],
            'limits' => ['quotaBytes' => 100, 'maxJobs' => 4, 'unknown' => 1],
        ], '10.0.0.1:8080', 200);
        self::assertTrue($sandboxRunnerHealth->isReady());
        self::assertTrue($sandboxRunnerHealth->supportsDrive);
        self::assertFalse($sandboxRunnerHealth->supportsFuse);
        self::assertSame(100, $sandboxRunnerHealth->limits->quotaBytes);
        self::assertSame(4, $sandboxRunnerHealth->limits->maxJobs);
        self::assertFalse(SandboxRunnerHealth::fromArray(['status' => 'broken'], 'a', 503)->isReady());
    }
}
