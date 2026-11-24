<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaces;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Every sandbox workspace the administration knows */
class SandboxWorkspacesGetResponseDto extends RestResponseDto
{
    /** @var bool False when the application set up no sandbox administration (the list is then empty) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $isConfigured = false;

    /** @var SandboxWorkspaces The workspaces, running and offloaded */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SandboxWorkspaces $workspaces;
}
