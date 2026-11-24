<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Presentation;

use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Mcp\Client\Sandbox\SandboxShellMCPPackage;
use PHPUnit\Framework\TestCase;

/** The pure parts of the shell tools */
class SandboxShellMCPPackageTest extends TestCase
{
    public function testThePackageIdIsTheSkillNameInLowerCase(): void
    {
        self::assertSame('agent.sandbox.shell', SandboxShellMCPPackage::$name);
    }

    public function testTheLeftOutLineNamesTheSpillFile(): void
    {
        self::assertStringContainsString('readFile {"path": ".runner/out-2.log", "offset": 41}', SandboxShellMCPPackage::buildLeftOutLine(1000, '.runner/out-2.log', 41));
        self::assertStringContainsString('no copy of the whole output', SandboxShellMCPPackage::buildLeftOutLine(1000, '', 41));
    }

    public function testRunnerFilePathsAreFoundBelowAnyFolder(): void
    {
        self::assertSame('.runner/out-2.log', SandboxShellMCPPackage::findRunnerFilePath('work/conversation-1/.runner/out-2.log'));
        self::assertNull(SandboxShellMCPPackage::findRunnerFilePath('out/report.csv'));
        self::assertNull(SandboxShellMCPPackage::findRunnerFilePath('.runner'));
    }

    public function testTheNumberedViewIsAWindow(): void
    {
        $view = SandboxShellMCPPackage::buildNumberedLinesView('a.txt', "one\ntwo\nthree\n", 2, 1);
        self::assertSame("a.txt · lines 2–2 of 3\n     2│two\nMore: offset 3.", $view);
    }

    public function testOnlyRasterPicturesAreShown(): void
    {
        self::assertSame('PNG', SandboxShellMCPPackage::requireViewableImageFormat("\x89PNG\r\n\x1a\n" . str_repeat("\0", 16)));
        $this->expectException(BadRequestException::class);
        SandboxShellMCPPackage::requireViewableImageFormat('%!PS-Adobe-3.0');
    }
}
