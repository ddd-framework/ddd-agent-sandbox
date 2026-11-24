<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Sandbox\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The workspace pictures to look at, at most 4. */
class ViewWorkspaceImagesRequestDto extends RequestDto
{
    /** @var string[]|null Paths relative to /workspace of PNG, JPEG or WebP files, e.g. work/check-1.png (required) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $paths = null;
}
