<?php

declare(strict_types=1);

namespace DDD\Modules\AgentSandbox;

use DDD\Infrastructure\Modules\DDDModule;

/**
 * The agent sandbox module: isolated runner sessions on the sandbox runner (a separate service; its image is built
 * from `runner/` of this repository), persistent workspaces with a pluggable store, the administration with its audit
 * and, beside `mgamadeus/ddd-agents`, the agent's shell tools, skills, the flush at a settled turn, the workspace
 * activity of the admin screen and the eval participant.
 */
class AgentSandboxModule extends DDDModule
{
    /** @var string A class of ddd-agents: the agent part of the module registers only beside it */
    public const string AGENTS_PACKAGE_CLASS = 'DDD\\Domain\\AI\\Entities\\MCPPackages\\MCPPackage';

    public static function getSourcePath(): string
    {
        return __DIR__ . '/../..';
    }

    public static function getConfigPath(): ?string
    {
        return __DIR__ . '/../../../config/app';
    }

    public static function getPublicServiceNamespaces(): array
    {
        return [
            'DDD\\Domain\\AI\\Services\\Sandboxes\\',
        ];
    }

    public static function getExcludePatterns(): array
    {
        return [...parent::getExcludePatterns(), 'Dtos', 'Modules', 'Testing'];
    }

    public static function getNamespacesRequiringClasses(): array
    {
        return [
            'DDD\\Domain\\AI\\Services\\Sandboxes\\Agents\\' => self::AGENTS_PACKAGE_CLASS,
            'DDD\\Presentation\\Mcp\\Client\\Sandbox\\' => self::AGENTS_PACKAGE_CLASS,
        ];
    }
}
