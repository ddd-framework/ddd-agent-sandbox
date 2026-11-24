<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Sandbox\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A search over the workspace files (ripgrep). */
class SearchWorkspaceFilesRequestDto extends RequestDto
{
    /** @var string|null Regular expression to find in the file contents, case-insensitive; without it the matching paths are listed */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $pattern = null;

    /** @var string|null Only files whose path matches this glob, e.g. "*.csv" or "out/**" */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $glob = null;
}
