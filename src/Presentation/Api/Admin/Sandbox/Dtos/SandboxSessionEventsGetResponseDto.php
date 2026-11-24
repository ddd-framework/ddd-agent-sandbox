<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\SandboxSessionEvents;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The newest entries of a session log */
class SandboxSessionEventsGetResponseDto extends RestResponseDto
{
    /** @var SandboxSessionEvents The entries, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SandboxSessionEvents $sessionEvents;
}
