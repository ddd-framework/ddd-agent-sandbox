<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Config;

use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use DDD\Domain\AI\Services\Skills\AISkillSourcesService;
use DDD\Infrastructure\Libs\Config;
use PHPUnit\Framework\TestCase;

/** The module's agent skill files pass the ddd-agents skill-file lint (frontmatter parses, known keys, list shapes) */
class SandboxSkillFilesLintTest extends TestCase
{
    public function testTheSkillFilesPassTheLint(): void
    {
        $skillRootDirectory = dirname(__DIR__, 2) . '/config/app/AI/Skills';
        // a sub-skill named in a frontmatter is resolved through the config roots, as in a host
        Config::addConfigDirectory(dirname(__DIR__, 2) . '/config/app', true);
        $skillNames = (new AISkillSourcesService())->findDottedNamesInFolder($skillRootDirectory);
        sort($skillNames);
        self::assertSame(['Agent.Sandbox.Documents', 'Agent.Sandbox.Excel', 'Agent.Sandbox.Pdf', 'Agent.Sandbox.Pptx', 'Agent.Sandbox.Shell'], $skillNames, 'the lint sees the five skills');
        $skillFileProblems = (new AISkillFilesLintService())->findSkillFileProblems([$skillRootDirectory]);
        self::assertSame([], $skillFileProblems);
    }
}
