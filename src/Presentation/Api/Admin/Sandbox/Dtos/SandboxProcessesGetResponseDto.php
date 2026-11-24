<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\SandboxProcesses;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The processes and background jobs of a workspace session */
class SandboxProcessesGetResponseDto extends RestResponseDto
{
    /** @var SandboxProcesses The processes */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SandboxProcesses $processes;
}
