<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\SandboxCommands;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The last commands of a workspace session */
class SandboxCommandsGetResponseDto extends RestResponseDto
{
    /** @var SandboxCommands The commands, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SandboxCommands $commands;
}
