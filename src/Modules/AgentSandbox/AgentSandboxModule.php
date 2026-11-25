<?php

declare(strict_types=1);

namespace DDD\Modules\AgentSandbox;

use DDD\Infrastructure\Modules\DDDModule;

/**
 * The agent sandbox module: isolated runner sessions on the sandbox runner (a separate service; its image is built
 * from `runner/` of this repository), persistent workspaces with a pluggable store, the administration with its audit
 * and, beside `mgamadeus/ddd-agents`, the agent's shell tools, skills, the flush at a settled turn, the workspace
 * activity of the admin screen and the eval participant; beside ddd-agents and `mgamadeus/ddd-clouddrive` also the drive
 * store (the workspace of an agent space is the working copy of its own drive).
 */
class AgentSandboxModule extends DDDModule
{
    /** @var string A class of ddd-agents: the agent part of the module registers only beside it */
    public const string AGENTS_PACKAGE_CLASS = 'DDD\\Domain\\AI\\Entities\\MCPPackages\\MCPPackage';

    /**
     * @var string A class of ddd-clouddrive 0.2 (its agent part): the drive store registers only beside it and ddd-agents
     *     (Composer's `conflict` keeps ddd-clouddrive within the versions the store was built against)
     */
    public const string CLOUDDRIVE_PACKAGE_CLASS = 'DDD\\Domain\\CloudDrive\\Services\\AI\\DriveSpaceMountResolverService';

    /** @var string The table condition port of ddd-agents (>= 0.5.0): the sandbox tables' condition registers only beside it */
    public const string FEATURE_TABLE_CONDITION_PROVIDER_CLASS = 'DDD\\Domain\\AI\\Services\\Installation\\AgentFeatureTableConditionProvider';

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
            'DDD\\Symfony\\Commands\\Sandbox\\Agents\\' => self::AGENTS_PACKAGE_CLASS,
            // the drive store: beside ddd-clouddrive AND ddd-agents (both prefixes above and below apply)
            'DDD\\Domain\\AI\\Services\\Sandboxes\\Agents\\Drive\\' => self::CLOUDDRIVE_PACKAGE_CLASS,
            'DDD\\Domain\\AI\\Entities\\Sandboxes\\Drive\\' => self::CLOUDDRIVE_PACKAGE_CLASS,
            'DDD\\Symfony\\Commands\\Sandbox\\Agents\\Drive\\' => self::CLOUDDRIVE_PACKAGE_CLASS,
            'DDD\\Domain\\AI\\Services\\Sandboxes\\Installation\\' => self::FEATURE_TABLE_CONDITION_PROVIDER_CLASS,
        ];
    }
}
