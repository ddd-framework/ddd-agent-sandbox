<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes\Agents;

use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalScratchFiles;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use DDD\Domain\AI\Services\Eval\AgentEvalWorkspaceParticipant;
use DDD\Domain\AI\Services\Sandboxes\SandboxRunnerClientService;
use DDD\Domain\AI\Services\Sandboxes\SandboxWorkspacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The sandbox's part of an eval case's workspace: the case's scratch files become the workspace of the case's key
 * (the next shell call claims and restores exactly these files), and the cleanup removes it from the store. Never
 * fails the case: a throw is reported by the eval runner as one line.
 */
class SandboxAgentEvalWorkspaceParticipantService extends Service implements AgentEvalWorkspaceParticipant
{
    public function prepareWorkspaceOfCase(
        AgentEvalScope $agentEvalScope,
        string $workspaceKey,
        AgentEvalScratchFiles $agentEvalScratchFiles,
        AIConversationProgressReporterInterface $progressReporter
    ): array {
        if (!$this->getSandboxRunnerClientService()->isSandboxEnabled()) {
            return [];
        }
        $contentsByPath = [];
        foreach ($agentEvalScratchFiles->getElements() as $agentEvalScratchFile) {
            $contentsByPath[$agentEvalScratchFile->path] = $agentEvalScratchFile->content;
        }
        $sandboxWorkspaceManifest = $this->getSandboxWorkspacesService()->createWorkspaceFromFiles($workspaceKey, $contentsByPath);
        return [sprintf('sandbox workspace %s: %d files', $workspaceKey, $sandboxWorkspaceManifest->countFiles())];
    }

    public function cleanUpWorkspaceOfCase(string $workspaceKey, AIConversationProgressReporterInterface $progressReporter): array
    {
        if (!$this->getSandboxRunnerClientService()->isSandboxEnabled()) {
            return [];
        }
        $sandboxWorkspacesService = $this->getSandboxWorkspacesService();
        if (!$sandboxWorkspacesService->isWorkspaceKnown($workspaceKey)) {
            return [];
        }
        $removedObjects = $sandboxWorkspacesService->deleteWorkspace($workspaceKey);
        return [sprintf('sandbox workspace %s removed (%d stored objects)', $workspaceKey, $removedObjects)];
    }

    protected function getSandboxWorkspacesService(): SandboxWorkspacesService
    {
        /** @var SandboxWorkspacesService $sandboxWorkspacesService */
        $sandboxWorkspacesService = DDDService::instance()->getService(SandboxWorkspacesService::class);
        return $sandboxWorkspacesService;
    }

    protected function getSandboxRunnerClientService(): SandboxRunnerClientService
    {
        /** @var SandboxRunnerClientService $sandboxRunnerClientService */
        $sandboxRunnerClientService = DDDService::instance()->getService(SandboxRunnerClientService::class);
        return $sandboxRunnerClientService;
    }
}
