<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Sandbox\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A command for the workspace shell, or the state of a background job. */
class ShellRequestDto extends RequestDto
{
    /** @var string|null The bash command (required unless jobId), run in /workspace or the cwd of the last command */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $command = null;

    /** @var int|null Seconds before the command is killed: default 60, at most 180; background default 600, at most 1800 */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $timeoutSeconds = null;

    /** @var bool|null true = start as a background job and answer its jobId at once (for work longer than a minute) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $background = null;

    /** @var string|null A background job: answers its state and output so far (no command) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $jobId = null;

    /** @var bool|null With jobId: kill the job */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $killJob = null;
}
