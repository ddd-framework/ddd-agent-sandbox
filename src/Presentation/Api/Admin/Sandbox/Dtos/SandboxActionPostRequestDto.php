<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** An action on a workspace: force past a running turn with a reason; a reset is confirmed with the workspace's name */
class SandboxActionPostRequestDto extends RequestDto
{
    /** @var string The workspace's key */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $workspaceKey = '';

    /** @var bool|null Act although a conversation turn runs on the space (needs a reason) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $force = null;

    /** @var string|null Why the action is forced */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $reason = null;

    /** @var string|null For a reset: the workspace's display name (its key when it has none) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $confirmation = null;
}
