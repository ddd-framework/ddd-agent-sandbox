<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspace;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One agent workspace */
class SandboxWorkspaceGetResponseDto extends RestResponseDto
{
    /** @var SandboxWorkspace The workspace */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SandboxWorkspace $workspace;
}
