<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One file of a workspace, for a preview or a download */
class SandboxFileContentGetRequestDto extends RequestDto
{
    /** @var string The workspace's key */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $workspaceKey = '';

    /** @var string The file relative to the workspace root */
    #[Parameter(in: Parameter::QUERY, required: true)]
    public string $path = '';

    /** @var string|null `inline` (a preview: raster images and PDF inline, text cut at 256 KB) or `attachment` (default) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Choice(choices: [self::DISPOSITION_INLINE, self::DISPOSITION_ATTACHMENT])]
    public ?string $disposition = null;

    public const string DISPOSITION_INLINE = 'inline';
    public const string DISPOSITION_ATTACHMENT = 'attachment';

    public function isInlineWanted(): bool
    {
        return $this->disposition === self::DISPOSITION_INLINE;
    }
}
