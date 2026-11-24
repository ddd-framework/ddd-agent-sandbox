<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\SandboxDriveStatus;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** What the workspace holds that the drive does not have yet */
class SandboxDriveStatusGetResponseDto extends RestResponseDto
{
    /** @var SandboxDriveStatus The unsaved changes */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SandboxDriveStatus $driveStatus;
}
