<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxImageConfig;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders a project's Kustomize overlay of the runner pool from `AI.Sandbox.runner.deploy` and `.network`: namespace,
 * replicas, image (the project's image tag when it has an image.yaml), CPU and memory limits, the workspace volume
 * size, the runtime class, the callers allowed on 8080 and, with a host allow-list, the egress proxy component. The
 * base is `deploy/k8s/base` of this package; the overlay references it by a relative path, so it is rendered into the
 * project (e.g. `deploy/sandbox/<stage>`) and applied from there.
 */
class SandboxDeployRenderService extends Service
{
    /** @var string The workspace limit the runner enforces, as a share of the volume (ext4 metadata, the reserve) */
    public const float WORKSPACE_LIMIT_SHARE_OF_VOLUME = 0.9;

    /** The package's deploy directory */
    public function getModuleDeployDirectory(): string
    {
        return dirname(__DIR__, 5) . '/deploy/k8s';
    }

    /**
     * The files of the overlay by their name relative to the overlay directory.
     *
     * @param string $overlayDirectory where the overlay will be written (for the relative path to the base)
     * @param string $imageRepository the image without tag (the project's registry path when it builds its own image)
     * @param string $imageTag
     * @return string[] content by file name
     * @throws BadRequestException
     */
    public function renderOverlayFiles(string $overlayDirectory, string $imageRepository, string $imageTag): array
    {
        $deployConfig = (array)(Config::get('AI.Sandbox.runner.deploy') ?? []);
        $networkConfig = (array)(Config::get('AI.Sandbox.runner.network') ?? []);
        $namespaceName = (string)($deployConfig['namespace'] ?? 'sandbox');
        if (preg_match('/^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$/', $namespaceName) !== 1) {
            throw new BadRequestException('AI.Sandbox.runner.deploy.namespace is no namespace name: ' . $namespaceName);
        }
        $workspaceSizeLimit = (string)($deployConfig['workspaceSizeLimit'] ?? '4Gi');
        $workspaceLimitBytes = (int)floor(static::parseQuantityBytes($workspaceSizeLimit) * static::WORKSPACE_LIMIT_SHARE_OF_VOLUME);
        $baseDirectory = Path::makeRelative($this->getModuleDeployDirectory() . '/base', Path::canonicalize($overlayDirectory));
        $usesEgressProxy = ($networkConfig['mode'] ?? SandboxSessionRulesService::NETWORK_MODE_OFF) === SandboxSessionRulesService::NETWORK_MODE_ALLOW_LIST;
        $kustomization = [
            'apiVersion' => 'kustomize.config.k8s.io/v1beta1',
            'kind' => 'Kustomization',
            'namespace' => $namespaceName,
            'resources' => [$baseDirectory],
            'images' => [['name' => SandboxImageConfigService::BASE_IMAGE, 'newName' => $imageRepository, 'newTag' => $imageTag]],
            'patches' => [
                ['path' => 'runner-patch.yaml'],
                ['path' => 'network-policy-patch.yaml'],
                ['target' => ['kind' => 'Namespace', 'name' => 'sandbox'], 'patch' => Yaml::dump([['op' => 'replace', 'path' => '/metadata/name', 'value' => $namespaceName]])],
            ],
        ];
        $overlayFiles = [];
        if ($usesEgressProxy) {
            $kustomization['components'] = [Path::makeRelative($this->getModuleDeployDirectory() . '/egress-proxy', Path::canonicalize($overlayDirectory))];
            $kustomization['configMapGenerator'] = [['name' => 'sandbox-egress-proxy-config', 'behavior' => 'merge', 'files' => ['allowed-hosts.txt']]];
            $overlayFiles['allowed-hosts.txt'] = $this->renderAllowedHosts((array)($networkConfig['allowedHosts'] ?? []));
        }
        $runnerPatch = [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => 'sandbox-runner'],
            'spec' => [
                'replicas' => (int)($deployConfig['replicas'] ?? 4),
                'template' => ['spec' => [
                    'runtimeClassName' => (string)($deployConfig['runtimeClassName'] ?? 'gvisor'),
                    'containers' => [[
                        'name' => 'runner',
                        'env' => [
                            ['name' => 'RUNNER_WORKSPACE_LIMIT_BYTES', 'value' => (string)$workspaceLimitBytes],
                            ['name' => 'RUNNER_MAX_PROCESSES', 'value' => (string)(int)($deployConfig['maxProcesses'] ?? 256)],
                            ['name' => 'RUNNER_SESSION_TZ', 'value' => (string)(Config::get('AI.Sandbox.runner.timezone') ?? 'UTC')],
                        ],
                        'resources' => ['limits' => ['cpu' => (string)($deployConfig['cpuLimit'] ?? '2'), 'memory' => (string)($deployConfig['memoryLimit'] ?? '4Gi')]],
                    ]],
                    'volumes' => [[
                        'name' => 'workspace',
                        'ephemeral' => ['volumeClaimTemplate' => ['spec' => ['resources' => ['requests' => ['storage' => $workspaceSizeLimit]]]]],
                    ]],
                ]],
            ],
        ];
        $networkPolicyPatch = [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => ['name' => 'sandbox-runner-isolation'],
            'spec' => ['ingress' => [[
                'from' => [[
                    'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => (string)($deployConfig['callerNamespace'] ?? 'app')]],
                    'podSelector' => ['matchLabels' => (array)($deployConfig['callerPodLabels'] ?? ['app.kubernetes.io/name' => 'app'])],
                ]],
                'ports' => [['protocol' => 'TCP', 'port' => 8080]],
            ]]],
        ];
        $header = "# Rendered by app:agent-sandbox:render-deploy from config/app/AI/Sandbox/runner.php — do not edit, change the config\n";
        $overlayFiles['kustomization.yaml'] = $header . Yaml::dump($kustomization, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $overlayFiles['runner-patch.yaml'] = $header . Yaml::dump($runnerPatch, 12, 2);
        $overlayFiles['network-policy-patch.yaml'] = $header . Yaml::dump($networkPolicyPatch, 12, 2);
        ksort($overlayFiles);
        return $overlayFiles;
    }

    /**
     * The image the overlay deploys: the module's image, or the project's when it has an image.yaml.
     *
     * @param SandboxImageConfig|null $sandboxImageConfig
     * @param string|null $projectImageRepository the registry path of the project's image
     * @return string
     */
    public function buildImageRepository(?SandboxImageConfig $sandboxImageConfig, ?string $projectImageRepository): string
    {
        return $sandboxImageConfig !== null && $projectImageRepository !== null && $projectImageRepository !== ''
            ? $projectImageRepository
            : SandboxImageConfigService::BASE_IMAGE;
    }

    /**
     * @param array<int, mixed> $allowedHosts
     * @return string one anchored expression per host
     * @throws BadRequestException
     */
    protected function renderAllowedHosts(array $allowedHosts): string
    {
        $lines = ['# rendered from AI.Sandbox.runner.network.allowedHosts'];
        foreach ($allowedHosts as $allowedHost) {
            if (!is_string($allowedHost) || preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $allowedHost) !== 1) {
                throw new BadRequestException('AI.Sandbox.runner.network.allowedHosts: ' . json_encode($allowedHost) . ' is no host name');
            }
            $lines[] = str_starts_with($allowedHost, '*.')
                ? '(^|\.)' . preg_quote(substr($allowedHost, 2), '/') . '$'
                : '^' . preg_quote($allowedHost, '/') . '$';
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Bytes of a Kubernetes quantity (`4Gi`, `500Mi`, `2G`, `1000`).
     */
    public static function parseQuantityBytes(string $quantity): int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(Ki|Mi|Gi|Ti|k|M|G|T)?$/', trim($quantity), $matches) !== 1) {
            return 0;
        }
        $multipliers = ['' => 1, 'Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3, 'Ti' => 1024 ** 4, 'k' => 1000, 'M' => 1000 ** 2, 'G' => 1000 ** 3, 'T' => 1000 ** 4];
        return (int)round((float)$matches[1] * $multipliers[$matches[2] ?? '']);
    }
}
