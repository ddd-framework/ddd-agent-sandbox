<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One workspace, by its key */
class SandboxWorkspaceGetRequestDto extends RequestDto
{
    /** @var string The workspace's key */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $workspaceKey = '';
}
