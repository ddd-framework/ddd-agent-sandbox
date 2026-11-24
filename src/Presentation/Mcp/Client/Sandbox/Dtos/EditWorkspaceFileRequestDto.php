<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Sandbox\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One exact replacement in a workspace text file. */
class EditWorkspaceFileRequestDto extends RequestDto
{
    /** @var string|null Path relative to /workspace (required) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $path = null;

    /** @var string|null The exact text to replace (required); must occur exactly once in the file, whitespace included */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $oldText = null;

    /** @var string|null The text that takes its place (required; empty removes oldText) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $newText = null;
}
