<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Sandbox\Dtos;

use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\Dtos\RequestDto;

/** A whole text file to write into the workspace. */
class WriteWorkspaceFileRequestDto extends RequestDto
{
    /** @var string|null Path relative to /workspace (required); parent folders are created */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $path = null;

    /** @var string|null The whole content (required, text, at most 1 MB); replaces an existing file */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $content = null;
}
