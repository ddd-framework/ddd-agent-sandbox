<?php

declare(strict_types=1);

/**
 * Agent sandbox module: the runner pods (image `ghcr.io/ddd-framework/ddd-agent-sandbox-runner`, built from `runner/` of
 * the module's repository; contract in runner/README.md, checked by `app:agent-sandbox:check-runner`).
 *
 * A config file replaces the module's default file as a whole: keep every key.
 */
return [
    // whether the sandbox may be used (tools, admin actions); the env variable below replaces it when set
    'enabled' => false,
    'enabledEnv' => 'AGENT_SANDBOX_ENABLED',
    // the headless Service of the runner pods (one A record per pod), or one address; the env variable replaces it
    'host' => null,
    'hostEnv' => 'AGENT_SANDBOX_RUNNER_HOST',
    'port' => 8080,
    'portEnv' => 'AGENT_SANDBOX_RUNNER_PORT',
    // further pools (another image or other hard limits), chosen per workspace by SandboxSessionRulesService:
    // ['reports' => ['host' => 'sandbox-runner-reports.sandbox.svc.cluster.local', 'port' => 8080]]
    'pools' => [],
    // the cache group every process of a workspace shares (sessions, marks, locks); must be shared across hosts
    'cacheGroup' => 'redis',
    // TZ of every session shell and the time zone of the dates the agent reads
    'timezone' => 'UTC',
    // the longest timeout of a foreground command and of a background job (the runner caps them as well)
    'maxCommandTimeoutSeconds' => 600,
    'maxJobTimeoutSeconds' => 3600,
    'allowBackgroundJobs' => true,
    // network inside the sandbox: 'off' (default; the NetworkPolicy lets nothing out) or 'allowList' (HTTP(S)_PROXY of
    // every session points to the egress proxy of deploy/k8s/egress-proxy, which lets only `allowedHosts` through)
    'network' => [
        'mode' => 'off',
        'egressProxyUrl' => 'http://sandbox-egress-proxy.sandbox.svc.cluster.local:3128',
        'allowedHosts' => [],
    ],
    // one line the state note shows about the toolkit (e.g. what the project's image adds); null = none
    'toolkitNote' => null,
    // the runner deployment app:agent-sandbox:render-deploy renders (deploy/k8s/ of the module + this patch)
    'deploy' => [
        'namespace' => 'sandbox',
        'replicas' => 4,
        'image' => null,
        'cpuLimit' => '2',
        'memoryLimit' => '4Gi',
        'workspaceSizeLimit' => '4Gi',
        'maxProcesses' => 256,
        'runtimeClassName' => 'gvisor',
        // namespace and pod labels of the application pods that may call the runner on 8080
        'callerNamespace' => 'app',
        'callerPodLabels' => ['app.kubernetes.io/name' => 'app'],
    ],
];
