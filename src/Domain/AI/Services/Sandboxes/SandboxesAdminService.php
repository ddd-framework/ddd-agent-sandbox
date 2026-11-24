<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntries;
use DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntry;
use DDD\Domain\AI\Entities\Sandboxes\SandboxActionResult;
use DDD\Domain\AI\Entities\Sandboxes\SandboxCommands;
use DDD\Domain\AI\Entities\Sandboxes\SandboxConversations;
use DDD\Domain\AI\Entities\Sandboxes\SandboxDriveStatus;
use DDD\Domain\AI\Entities\Sandboxes\SandboxFileEntries;
use DDD\Domain\AI\Entities\Sandboxes\SandboxProcesses;
use DDD\Domain\AI\Entities\Sandboxes\SandboxSessionEvents;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspace;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaces;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\ContainedPath;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use DDD\Presentation\Base\Dtos\FileResponseDto;
use DDD\Presentation\Base\Dtos\UntrustedContentResponseDto;
use Throwable;

/**
 * The sandbox administration as the admin screen uses it: {@see SandboxAdministrationService} (the runner and the
 * workspaces) plus the conversations of a workspace's agent space and the guard against acting while a turn runs there
 * (both from {@see SandboxWorkspaceActivity}, the agent part), the audit of every action
 * ({@see SandboxActionLogEntriesService}) and the delivery of workspace files under the untrusted-content rules (raster
 * images and PDF inline, text as text, every other type an opaque attachment).
 */
class SandboxesAdminService extends Service
{
    /** @var int The largest file the screen reads (preview and download) */
    public const int MAX_FILE_CONTENT_BYTES = 26214400;

    /** @var int The largest text a preview shows; a longer text is cut and marked */
    public const int MAX_TEXT_PREVIEW_BYTES = 262144;

    /** @var int Bytes looked at to tell text from binary */
    public const int TEXT_SNIFF_BYTES = 8192;

    /** @var int The most commands, events, conversations or audit entries one read answers */
    public const int MAX_LIST_LIMIT = 200;

    /** @var string Header that marks a cut text preview */
    public const string TRUNCATED_HEADER = 'X-Content-Truncated';

    /** True when a runner host is configured (the screen shows "not set up" otherwise) */
    public function isConfigured(): bool
    {
        return $this->getSandboxRunnerClientService()->getRunnerPool()->host !== '';
    }

    /** The runner and workspace side of the administration */
    public function getAdministration(): SandboxAdministrationService
    {
        /** @var SandboxAdministrationService $sandboxAdministrationService */
        $sandboxAdministrationService = DDDService::instance()->getService(SandboxAdministrationService::class);
        return $sandboxAdministrationService;
    }

    /** Every workspace with its conversation count and live-turn flag; empty when nothing is set up */
    public function findWorkspaces(): SandboxWorkspaces
    {
        $sandboxWorkspaces = $this->getAdministration()->findWorkspaces();
        $this->addConversationFacts($sandboxWorkspaces);
        return $sandboxWorkspaces;
    }

    /**
     * One workspace with its conversation count and live-turn flag.
     *
     * @throws NotFoundException
     */
    public function findWorkspace(string $workspaceKey): SandboxWorkspace
    {
        $sandboxWorkspace = $this->getAdministration()->findWorkspace($workspaceKey);
        if ($sandboxWorkspace === null) {
            throw new NotFoundException('No workspace with this key');
        }
        $sandboxWorkspaces = new SandboxWorkspaces();
        $sandboxWorkspaces->add($sandboxWorkspace);
        $this->addConversationFacts($sandboxWorkspaces);
        return $sandboxWorkspace;
    }

    /**
     * One directory of a workspace ('' = the root).
     *
     * @throws NotFoundException
     * @throws BadRequestException when the path leaves the workspace
     */
    public function findFileEntries(string $workspaceKey, string $directoryPath): SandboxFileEntries
    {
        $normalizedDirectoryPath = $this->normalizeRelativePath($directoryPath, true);
        return $this->getAdministration()->findFileEntries($workspaceKey, $normalizedDirectoryPath);
    }

    /**
     * The response that serves one workspace file under the untrusted-content rules: JPEG/PNG/GIF/WebP (by their magic
     * bytes) inline when wanted, PDF inline when wanted, UTF-8 text as `text/plain` (a preview cut at
     * {@see MAX_TEXT_PREVIEW_BYTES}), anything else an opaque attachment. The file name is the path's last segment.
     *
     * @throws NotFoundException
     * @throws BadRequestException when the path leaves the workspace or the file is too large
     */
    public function buildFileContentResponse(string $workspaceKey, string $relativePath, bool $inlineWanted): FileResponseDto
    {
        $normalizedRelativePath = $this->normalizeRelativePath($relativePath, false);
        $fileBytes = $this->getAdministration()->readFileBytes($workspaceKey, $normalizedRelativePath, static::MAX_FILE_CONTENT_BYTES);
        if ($fileBytes === null) {
            throw new NotFoundException('No file at this path');
        }
        $fileName = basename($normalizedRelativePath);
        $isLargerThanMax = strlen($fileBytes) > static::MAX_FILE_CONTENT_BYTES;
        $rasterMimeType = RasterImageDecoder::detectMimeType($fileBytes);
        if ($rasterMimeType !== null) {
            $this->throwIfLargerThanMax($isLargerThanMax);
            return new UntrustedContentResponseDto($fileBytes, $rasterMimeType, $fileName, $inlineWanted);
        }
        if (str_starts_with($fileBytes, '%PDF-')) {
            $this->throwIfLargerThanMax($isLargerThanMax);
            if ($inlineWanted) {
                return new FileResponseDto($fileBytes, 200, ['Content-Disposition' => UntrustedContentHeaders::buildContentDisposition(true, $fileName)], 'application/pdf');
            }
            return new UntrustedContentResponseDto($fileBytes, 'application/pdf', $fileName, false);
        }
        if ($this->isText($fileBytes)) {
            $isTruncated = false;
            if ($inlineWanted && strlen($fileBytes) > static::MAX_TEXT_PREVIEW_BYTES) {
                $fileBytes = mb_strcut($fileBytes, 0, static::MAX_TEXT_PREVIEW_BYTES, 'UTF-8');
                $isTruncated = true;
            } else {
                $this->throwIfLargerThanMax($isLargerThanMax);
            }
            $textResponse = new UntrustedContentResponseDto($fileBytes, 'text/plain', $fileName, false);
            $textResponse->headers->set('Content-Type', 'text/plain; charset=utf-8');
            $textResponse->headers->set(static::TRUNCATED_HEADER, $isTruncated ? 'true' : 'false');
            return $textResponse;
        }
        $this->throwIfLargerThanMax($isLargerThanMax);
        return new UntrustedContentResponseDto($fileBytes, null, $fileName, false);
    }

    /**
     * @throws NotFoundException
     */
    public function findDriveStatus(string $workspaceKey): SandboxDriveStatus
    {
        return $this->getAdministration()->findDriveStatus($workspaceKey);
    }

    /**
     * @throws NotFoundException
     */
    public function findProcesses(string $workspaceKey): SandboxProcesses
    {
        return $this->getAdministration()->findProcesses($workspaceKey);
    }

    /**
     * @throws NotFoundException
     */
    public function findRecentCommands(string $workspaceKey, int $limit): SandboxCommands
    {
        return $this->getAdministration()->findRecentCommands($workspaceKey, $this->clampLimit($limit));
    }

    /**
     * @throws NotFoundException
     */
    public function findSessionEvents(string $workspaceKey, int $limit): SandboxSessionEvents
    {
        return $this->getAdministration()->findSessionEvents($workspaceKey, $this->clampLimit($limit));
    }

    /**
     * The newest root conversations of the workspace's agent space; empty at account level.
     *
     * @throws NotFoundException
     */
    public function findConversations(string $workspaceKey, int $limit): SandboxConversations
    {
        $sandboxWorkspace = $this->findWorkspace($workspaceKey);
        if ($sandboxWorkspace->agentSpaceId === null) {
            return new SandboxConversations();
        }
        $sandboxWorkspaceActivity = $this->getAdministration()->findWorkspaceActivity();
        if ($sandboxWorkspaceActivity === null) {
            return new SandboxConversations();
        }
        return $sandboxWorkspaceActivity->findNewestConversationsOfAgentSpace($sandboxWorkspace->agentSpaceId, $this->clampLimit($limit));
    }

    /** The newest audit entries of a workspace */
    public function findActionLogEntries(string $workspaceKey, int $limit): SandboxActionLogEntries
    {
        /** @var SandboxActionLogEntriesService $sandboxActionLogEntriesService */
        $sandboxActionLogEntriesService = DDDService::instance()->getService(SandboxActionLogEntriesService::class);
        return $sandboxActionLogEntriesService->findNewestActionLogEntriesOfWorkspace($workspaceKey, $this->clampLimit($limit));
    }

    /**
     * Runs one action on a workspace and audits it. Refused (and audited as REFUSED): a forced action without a reason,
     * a reset whose confirmation is not the workspace's name, any action while a conversation of the workspace's agent
     * space holds a live turn unless forced. A host call that throws is audited as FAILED and its error rethrown.
     *
     * @param string $workspaceKey
     * @param string $action one of {@see SandboxActionLogEntry::ACTIONS}
     * @param string|null $jobId the job of a KILL_JOB
     * @param bool $force act although a turn runs (needs a reason)
     * @param string|null $reason why the action is forced
     * @param string|null $confirmation for a reset: the workspace's display name (its key when it has none)
     * @return SandboxActionResult
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException when a turn runs on the space and the action is not forced
     */
    public function runAction(
        string $workspaceKey,
        string $action,
        ?string $jobId,
        bool $force,
        ?string $reason,
        ?string $confirmation
    ): SandboxActionResult {
        if (!in_array($action, SandboxActionLogEntry::ACTIONS, true)) {
            throw new BadRequestException('Unknown sandbox action: ' . $action);
        }
        $sandboxWorkspace = $this->findWorkspace($workspaceKey);
        $sandboxActionLogEntry = SandboxActionLogEntry::newInstance();
        $sandboxActionLogEntry->workspaceKey = $sandboxWorkspace->workspaceKey;
        $sandboxActionLogEntry->agentSpaceId = $sandboxWorkspace->agentSpaceId;
        $sandboxActionLogEntry->action = $action;
        $sandboxActionLogEntry->jobId = $jobId;
        $sandboxActionLogEntry->actorAccountId = $this->findActorAccountId();
        $sandboxActionLogEntry->forced = $force;
        $sandboxActionLogEntry->reason = $reason;

        if ($force && trim((string)$reason) === '') {
            $this->refuseAction($sandboxActionLogEntry, new BadRequestException('A forced action needs a reason'));
        }
        if ($action === SandboxActionLogEntry::ACTION_KILL_JOB && trim((string)$jobId) === '') {
            $this->refuseAction($sandboxActionLogEntry, new BadRequestException('Killing a job needs the job id'));
        }
        if ($action === SandboxActionLogEntry::ACTION_RESET) {
            $expectedConfirmation = $this->buildResetConfirmation($sandboxWorkspace);
            if (trim((string)$confirmation) !== $expectedConfirmation) {
                $this->refuseAction($sandboxActionLogEntry, new BadRequestException('A reset needs the workspace\'s name as confirmation'));
            }
        }
        if ($sandboxWorkspace->hasLiveTurn && !$force) {
            $this->refuseAction(
                $sandboxActionLogEntry,
                new ConflictException('A conversation turn is running on this space: wait for it to end, or force the action with a reason')
            );
        }

        $sandboxActionResult = new SandboxActionResult();
        $sandboxActionResult->action = $action;
        $sandboxActionResult->workspaceKey = $sandboxWorkspace->workspaceKey;
        try {
            $sandboxAdministrationService = $this->getAdministration();
            switch ($action) {
                case SandboxActionLogEntry::ACTION_START:
                    $sandboxAdministrationService->startSession($sandboxWorkspace->workspaceKey);
                    break;
                case SandboxActionLogEntry::ACTION_OFFLOAD:
                    $sandboxActionResult->offloadResult = $sandboxAdministrationService->offloadSession($sandboxWorkspace->workspaceKey);
                    $sandboxActionLogEntry->outcomeDetail = 'committed ' . $sandboxActionResult->offloadResult->committedChangeCount
                        . ' change(s), session ' . ($sandboxActionResult->offloadResult->wasSessionReleased ? 'released' : 'kept')
                        . ($sandboxActionResult->offloadResult->detail !== null ? ': ' . $sandboxActionResult->offloadResult->detail : '');
                    break;
                case SandboxActionLogEntry::ACTION_KILL_JOB:
                    $sandboxAdministrationService->killJob($sandboxWorkspace->workspaceKey, trim((string)$jobId));
                    break;
                case SandboxActionLogEntry::ACTION_END:
                    $sandboxAdministrationService->endSession($sandboxWorkspace->workspaceKey);
                    break;
                case SandboxActionLogEntry::ACTION_RESET:
                    $sandboxAdministrationService->resetWorkspace($sandboxWorkspace->workspaceKey);
                    break;
            }
        } catch (Throwable $throwable) {
            $sandboxActionLogEntry->outcome = SandboxActionLogEntry::OUTCOME_FAILED;
            $sandboxActionLogEntry->outcomeDetail = $throwable->getMessage();
            $this->recordActionLogEntry($sandboxActionLogEntry);
            throw $throwable;
        }
        $sandboxActionLogEntry->outcome = SandboxActionLogEntry::OUTCOME_SUCCEEDED;
        $storedActionLogEntry = $this->recordActionLogEntry($sandboxActionLogEntry);
        $sandboxActionResult->outcome = SandboxActionLogEntry::OUTCOME_SUCCEEDED;
        $sandboxActionResult->actionLogEntryId = $storedActionLogEntry !== null && isset($storedActionLogEntry->id) ? (int)$storedActionLogEntry->id : null;
        return $sandboxActionResult;
    }

    /** The text a reset must be confirmed with: the workspace's display name, its key when it has none */
    public function buildResetConfirmation(SandboxWorkspace $sandboxWorkspace): string
    {
        $displayName = trim((string)$sandboxWorkspace->displayName);
        return $displayName !== '' ? $displayName : $sandboxWorkspace->workspaceKey;
    }

    /** Adds the agent part's facts (conversation count, live turn) to the workspaces of agent spaces */
    protected function addConversationFacts(SandboxWorkspaces $sandboxWorkspaces): void
    {
        $agentSpaceIds = $sandboxWorkspaces->getAgentSpaceIds();
        if ($agentSpaceIds === []) {
            return;
        }
        $conversationCountsByAgentSpaceId = $this->countRootConversationsPerAgentSpaceId($agentSpaceIds);
        $agentSpaceIdsWithALiveTurn = $this->findAgentSpaceIdsWithALiveTurn($agentSpaceIds);
        foreach ($sandboxWorkspaces->getElements() as $sandboxWorkspace) {
            if ($sandboxWorkspace->agentSpaceId === null) {
                continue;
            }
            $sandboxWorkspace->conversationCount = $conversationCountsByAgentSpaceId[$sandboxWorkspace->agentSpaceId] ?? 0;
            $sandboxWorkspace->hasLiveTurn = in_array($sandboxWorkspace->agentSpaceId, $agentSpaceIdsWithALiveTurn, true);
        }
    }

    /**
     * @param int[] $agentSpaceIds
     * @return int[] agent space id => root conversation count
     */
    protected function countRootConversationsPerAgentSpaceId(array $agentSpaceIds): array
    {
        return $this->getAdministration()->findWorkspaceActivity()?->countConversationsPerAgentSpaceId($agentSpaceIds) ?? [];
    }

    /**
     * @param int[] $agentSpaceIds
     * @return int[]
     */
    protected function findAgentSpaceIdsWithALiveTurn(array $agentSpaceIds): array
    {
        return $this->getAdministration()->findWorkspaceActivity()?->findAgentSpaceIdsWithALiveTurn($agentSpaceIds) ?? [];
    }

    protected function recordActionLogEntry(SandboxActionLogEntry $sandboxActionLogEntry): ?SandboxActionLogEntry
    {
        /** @var SandboxActionLogEntriesService $sandboxActionLogEntriesService */
        $sandboxActionLogEntriesService = DDDService::instance()->getService(SandboxActionLogEntriesService::class);
        return $sandboxActionLogEntriesService->recordActionLogEntry($sandboxActionLogEntry);
    }

    /**
     * Audits a refused action and throws the refusal.
     *
     * @throws BadRequestException|ConflictException
     */
    protected function refuseAction(SandboxActionLogEntry $sandboxActionLogEntry, BadRequestException|ConflictException $refusal): never
    {
        $sandboxActionLogEntry->outcome = SandboxActionLogEntry::OUTCOME_REFUSED;
        $sandboxActionLogEntry->outcomeDetail = $refusal->getMessage();
        $this->recordActionLogEntry($sandboxActionLogEntry);
        throw $refusal;
    }

    protected function findActorAccountId(): ?int
    {
        $authAccount = AuthService::instance()->getAccount();
        return $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
    }

    /**
     * A relative workspace path as the host gets it: `/`-separated, no leading or trailing slash, no `.` or `..`, no
     * encoded separator, no control character.
     *
     * @throws BadRequestException
     */
    protected function normalizeRelativePath(string $relativePath, bool $allowRoot): string
    {
        $trimmedPath = trim(trim($relativePath), '/');
        if ($trimmedPath === '') {
            if ($allowRoot) {
                return '';
            }
            throw new BadRequestException('A file path is needed');
        }
        if (!ContainedPath::isSafeRelativePath($trimmedPath)) {
            throw new BadRequestException('The path leaves the workspace or is not valid');
        }
        return $trimmedPath;
    }

    protected function isText(string $fileBytes): bool
    {
        $sniffedBytes = mb_strcut($fileBytes, 0, static::TEXT_SNIFF_BYTES, 'UTF-8');
        return !str_contains($sniffedBytes, "\0") && mb_check_encoding($sniffedBytes, 'UTF-8');
    }

    /**
     * @throws BadRequestException
     */
    protected function throwIfLargerThanMax(bool $isLargerThanMax): void
    {
        if ($isLargerThanMax) {
            throw new BadRequestException('The file is larger than ' . intdiv(static::MAX_FILE_CONTENT_BYTES, 1048576) . ' MB and is not served here');
        }
    }

    protected function clampLimit(int $limit): int
    {
        return max(1, min($limit, static::MAX_LIST_LIMIT));
    }

    protected function getSandboxRunnerClientService(): SandboxRunnerClientService
    {
        /** @var SandboxRunnerClientService $sandboxRunnerClientService */
        $sandboxRunnerClientService = DDDService::instance()->getService(SandboxRunnerClientService::class);
        return $sandboxRunnerClientService;
    }
}
