<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntries;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The audit of the actions on a workspace */
class SandboxActionLogEntriesGetResponseDto extends RestResponseDto
{
    /** @var SandboxActionLogEntries The entries, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SandboxActionLogEntries $actionLogEntries;
}
