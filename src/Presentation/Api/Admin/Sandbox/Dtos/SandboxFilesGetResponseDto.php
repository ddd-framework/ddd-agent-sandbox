<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\SandboxFileEntries;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One directory of a workspace, read-only */
class SandboxFilesGetResponseDto extends RestResponseDto
{
    /** @var string The directory relative to the workspace root; empty = the root */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $directoryPath = '';

    /** @var SandboxFileEntries Its entries */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SandboxFileEntries $entries;
}
