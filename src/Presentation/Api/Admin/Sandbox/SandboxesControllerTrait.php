<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox;

use DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntry;
use DDD\Domain\AI\Services\Sandboxes\SandboxesAdminService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxActionLogEntriesGetResponseDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxActionPostRequestDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxActionPostResponseDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxCommandsGetResponseDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxConversationsGetResponseDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxDriveStatusGetResponseDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxFileContentGetRequestDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxFilesGetRequestDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxFilesGetResponseDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxJobKillPostRequestDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxListGetRequestDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxProcessesGetResponseDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxSessionEventsGetResponseDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxWorkspaceGetRequestDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxWorkspaceGetResponseDto;
use DDD\Presentation\Api\Admin\Sandbox\Dtos\SandboxWorkspacesGetResponseDto;
use DDD\Presentation\Base\Dtos\FileResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;

/**
 * The sandbox endpoints of an admin API: the sandbox workspaces with session state and resource use, a read-only file
 * tree with safe previews, unsaved drive changes, processes, commands, session log, the space's conversations, the
 * audit, and the actions start, offload, kill a job, end, reset ({@see SandboxesAdminService}). An application mounts
 * the endpoints with one controller of its own, which sets the path prefix, the tag and the access (operators only):
 *
 *     #[Route('/maintenance/sandboxes')]
 *     #[Tag(group: 'Maintenance', name: 'Sandboxes', description: 'Sandbox workspaces and their sessions')]
 *     class AdminSandboxesController extends AdminController
 *     {
 *         use SandboxesControllerTrait;
 *
 *         protected function throwForbiddenUnlessSandboxesAccessAllowed(): void { … superadmin only … }
 *     }
 *
 * Every action is refused (409) while a conversation of the workspace's agent space holds a live turn, unless it is
 * forced with a reason; every action is audited, refusals included. File names, command lines and outputs are foreign
 * text: a screen shows them as text only.
 */
trait SandboxesControllerTrait
{
    /**
     * Every workspace the sandbox knows, with its state, resource use, mount mode, owner and conversation count.
     */
    #[Get('/list')]
    #[Summary('Sandboxes List')]
    public function listSandboxes(SandboxesAdminService $sandboxesAdminService): SandboxWorkspacesGetResponseDto
    {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new SandboxWorkspacesGetResponseDto();
        $responseDto->isConfigured = $sandboxesAdminService->isConfigured();
        $responseDto->workspaces = $sandboxesAdminService->findWorkspaces();
        return $responseDto;
    }

    /**
     * One workspace.
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Details')]
    public function getSandbox(
        SandboxWorkspaceGetRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxWorkspaceGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new SandboxWorkspaceGetResponseDto();
        $responseDto->workspace = $sandboxesAdminService->findWorkspace($requestDto->workspaceKey);
        return $responseDto;
    }

    /**
     * One directory of the workspace, read-only.
     *
     * @throws NotFoundException
     * @throws BadRequestException When the path leaves the workspace
     */
    #[Get('/{workspaceKey}/files', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Files')]
    public function listSandboxFiles(
        SandboxFilesGetRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxFilesGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new SandboxFilesGetResponseDto();
        $responseDto->directoryPath = trim(trim((string)$requestDto->path), '/');
        $responseDto->entries = $sandboxesAdminService->findFileEntries($requestDto->workspaceKey, (string)$requestDto->path);
        return $responseDto;
    }

    /**
     * One file of the workspace: raster images and PDF inline for a preview, UTF-8 text as text/plain (a preview cut
     * at 256 KB, header X-Content-Truncated), anything else an opaque attachment.
     *
     * @throws NotFoundException
     * @throws BadRequestException When the path leaves the workspace or the file is too large
     */
    #[Get('/{workspaceKey}/files/content', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox File Content')]
    public function getSandboxFileContent(
        SandboxFileContentGetRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): FileResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        return $sandboxesAdminService->buildFileContentResponse($requestDto->workspaceKey, $requestDto->path, $requestDto->isInlineWanted());
    }

    /**
     * What the workspace holds that the drive does not have yet.
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}/driveStatus', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Drive Status')]
    public function getSandboxDriveStatus(
        SandboxWorkspaceGetRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxDriveStatusGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new SandboxDriveStatusGetResponseDto();
        $responseDto->driveStatus = $sandboxesAdminService->findDriveStatus($requestDto->workspaceKey);
        return $responseDto;
    }

    /**
     * The processes and background jobs of the session.
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}/processes', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Processes')]
    public function listSandboxProcesses(
        SandboxWorkspaceGetRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxProcessesGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new SandboxProcessesGetResponseDto();
        $responseDto->processes = $sandboxesAdminService->findProcesses($requestDto->workspaceKey);
        return $responseDto;
    }

    /**
     * The last commands of the session with exit code, duration and output tails.
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}/commands', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Commands')]
    public function listSandboxCommands(
        SandboxListGetRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxCommandsGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new SandboxCommandsGetResponseDto();
        $responseDto->commands = $sandboxesAdminService->findRecentCommands($requestDto->workspaceKey, $requestDto->getLimitOrDefault());
        return $responseDto;
    }

    /**
     * The newest entries of the session log (limits hit, refused system calls, timeouts).
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}/events', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Session Events')]
    public function listSandboxSessionEvents(
        SandboxListGetRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxSessionEventsGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new SandboxSessionEventsGetResponseDto();
        $responseDto->sessionEvents = $sandboxesAdminService->findSessionEvents($requestDto->workspaceKey, $requestDto->getLimitOrDefault());
        return $responseDto;
    }

    /**
     * The newest root conversations of the workspace's agent space.
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}/conversations', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Conversations')]
    public function listSandboxConversations(
        SandboxListGetRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxConversationsGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new SandboxConversationsGetResponseDto();
        $responseDto->conversations = $sandboxesAdminService->findConversations($requestDto->workspaceKey, $requestDto->getLimitOrDefault());
        return $responseDto;
    }

    /**
     * The audit of the actions on the workspace, refusals included.
     */
    #[Get('/{workspaceKey}/actions', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Action Log')]
    public function listSandboxActionLogEntries(
        SandboxListGetRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxActionLogEntriesGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new SandboxActionLogEntriesGetResponseDto();
        $responseDto->actionLogEntries = $sandboxesAdminService->findActionLogEntries($requestDto->workspaceKey, $requestDto->getLimitOrDefault());
        return $responseDto;
    }

    /**
     * Starts (warms) a session for the workspace.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException When a turn runs on the space and the action is not forced
     */
    #[Post('/{workspaceKey}/start', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Start')]
    public function startSandbox(
        SandboxActionPostRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxActionPostResponseDto {
        return $this->runSandboxAction(SandboxActionLogEntry::ACTION_START, $requestDto, null, $sandboxesAdminService);
    }

    /**
     * Commits the pending drive changes, then releases the session; the workspace persists.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException When a turn runs on the space and the action is not forced
     */
    #[Post('/{workspaceKey}/offload', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Offload')]
    public function offloadSandbox(
        SandboxActionPostRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxActionPostResponseDto {
        return $this->runSandboxAction(SandboxActionLogEntry::ACTION_OFFLOAD, $requestDto, null, $sandboxesAdminService);
    }

    /**
     * Kills one background job of the session.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException When a turn runs on the space and the action is not forced
     */
    #[Post('/{workspaceKey}/jobs/{jobId}/kill', requirements: ['workspaceKey' => '[^/]+', 'jobId' => '[^/]+'])]
    #[Summary('Sandbox Job Kill')]
    public function killSandboxJob(
        SandboxActionPostRequestDto $requestDto,
        SandboxJobKillPostRequestDto $jobKillRequestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxActionPostResponseDto {
        return $this->runSandboxAction(SandboxActionLogEntry::ACTION_KILL_JOB, $requestDto, $jobKillRequestDto->jobId, $sandboxesAdminService);
    }

    /**
     * Ends the session without committing.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException When a turn runs on the space and the action is not forced
     */
    #[Post('/{workspaceKey}/end', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox End')]
    public function endSandbox(
        SandboxActionPostRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxActionPostResponseDto {
        return $this->runSandboxAction(SandboxActionLogEntry::ACTION_END, $requestDto, null, $sandboxesAdminService);
    }

    /**
     * Wipes the workspace's local state and keeps the drive; needs the workspace's name as `confirmation`.
     *
     * @throws NotFoundException
     * @throws BadRequestException When the confirmation does not match
     * @throws ConflictException When a turn runs on the space and the action is not forced
     */
    #[Post('/{workspaceKey}/reset', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('Sandbox Reset')]
    public function resetSandbox(
        SandboxActionPostRequestDto $requestDto,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxActionPostResponseDto {
        return $this->runSandboxAction(SandboxActionLogEntry::ACTION_RESET, $requestDto, null, $sandboxesAdminService);
    }

    /**
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException
     */
    protected function runSandboxAction(
        string $action,
        SandboxActionPostRequestDto $requestDto,
        ?string $jobId,
        SandboxesAdminService $sandboxesAdminService
    ): SandboxActionPostResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new SandboxActionPostResponseDto();
        $responseDto->actionResult = $sandboxesAdminService->runAction(
            $requestDto->workspaceKey,
            $action,
            $jobId,
            $requestDto->force === true,
            $requestDto->reason,
            $requestDto->confirmation
        );
        return $responseDto;
    }

    /**
     * The access hook of every sandbox endpoint, called first. Empty by default: the application restricts the path
     * (`access_control`) and overrides this for an account predicate (operators only) by throwing a
     * {@see ForbiddenException}.
     *
     * @throws ForbiddenException
     */
    protected function throwForbiddenUnlessSandboxesAccessAllowed(): void
    {
    }
}
