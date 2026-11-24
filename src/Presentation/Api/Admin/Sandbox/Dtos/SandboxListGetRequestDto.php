<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Sandbox\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A list of one workspace (commands, session log, conversations, audit) */
class SandboxListGetRequestDto extends RequestDto
{
    /** @var string The workspace's key */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $workspaceKey = '';

    /** @var int|null How many entries to answer (default 50, at most 200) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $limit = null;

    public function getLimitOrDefault(): int
    {
        return $this->limit ?? 50;
    }
}
