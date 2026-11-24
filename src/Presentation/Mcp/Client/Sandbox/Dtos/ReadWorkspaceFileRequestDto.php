<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Sandbox\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A window of one workspace file. */
class ReadWorkspaceFileRequestDto extends RequestDto
{
    /** @var string|null Path relative to /workspace (required), e.g. out/report.xlsx */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $path = null;

    /** @var int|null First line (or row) to show, counted from 1; default 1 */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $offset = null;

    /** @var int|null Lines (or rows per sheet, pages of a PDF) to show; default 200, at most 2000 */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;

    /** @var string|null xlsx: only this sheet */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $sheet = null;
}
