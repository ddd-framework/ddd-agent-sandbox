<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\SandboxConversations;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The newest root conversations of the workspace's agent space */
class SandboxConversationsGetResponseDto extends RestResponseDto
{
    /** @var SandboxConversations The conversations, newest change first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SandboxConversations $conversations;
}
