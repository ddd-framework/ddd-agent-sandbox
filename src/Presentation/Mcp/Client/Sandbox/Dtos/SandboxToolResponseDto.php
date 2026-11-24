<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Sandbox\Dtos;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The answer of a workspace tool of the admin agent: compact lines the model reads (a status line, the output or the
 * file view, the changed paths), never a JSON dump; plus the flat values a state check of the evaluation and the
 * measurements read (`fields.<name>`: exit code, durations, the output text for a check).
 *
 * Example (shell):
 *   exit 0 · 1.4 s · cwd /workspace/out
 *   Summe: 42
 *   changed: out/kampagnen.xlsx (new, 5.1 KB)
 */
class SandboxToolResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var string[] Values a state check or a measurement reads, by name (each as text) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $fields = [];

    /** @var string[] The lines of the answer in order */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $lines = [];

    /**
     * @param string $fieldName
     * @param string|int|bool|null $value
     * @return void
     */
    public function setField(string $fieldName, string|int|bool|null $value): void
    {
        $this->fields[$fieldName] = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string)$value,
        };
    }

    /**
     * @param string ...$lines
     * @return void
     */
    public function addLines(string ...$lines): void
    {
        array_push($this->lines, ...$lines);
    }

    /**
     * @param string $line
     * @return void
     */
    public function prependLine(string $line): void
    {
        array_unshift($this->lines, $line);
    }

    /**
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        return implode("\n", $this->lines);
    }
}
