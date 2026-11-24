<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\SandboxActionResult;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** What an action on a workspace did */
class SandboxActionPostResponseDto extends RestResponseDto
{
    /** @var SandboxActionResult The action, its outcome and its audit entry */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SandboxActionResult $actionResult;
}
