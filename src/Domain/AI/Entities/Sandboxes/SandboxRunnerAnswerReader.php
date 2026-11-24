<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DateTimeImmutable;
use Exception;

/**
 * Strict reading of one JSON object of a runner's administration answer (services/sandbox-runner/README.md,
 * "Administration"): every field the contract names must be there with its type, otherwise the answer is refused as a
 * whole ({@see SandboxRunnerException} `INVALID_ANSWER`). Fields the contract adds later are ignored, so a newer
 * runner never breaks an older PHP side.
 */
class SandboxRunnerAnswerReader
{
    /** @var string The error code of an answer that does not match the contract */
    public const string INVALID_ANSWER = 'INVALID_ANSWER';

    /**
     * @param array<string, mixed> $data the decoded object
     * @param string $context what is read, for the error message (e.g. `sessions[0]`)
     */
    public function __construct(
        public readonly array $data,
        public readonly string $context
    ) {
    }

    /**
     * @param mixed $value
     * @param string $context
     * @return static
     * @throws SandboxRunnerException the value is no JSON object
     */
    public static function of(mixed $value, string $context): static
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw static::invalid($context, 'is no object');
        }
        return new static($value, $context);
    }

    /**
     * @param string $context
     * @param string $problem
     * @return SandboxRunnerException
     */
    public static function invalid(string $context, string $problem): SandboxRunnerException
    {
        return SandboxRunnerException::fromRunner(200, static::INVALID_ANSWER, $context . ' ' . $problem);
    }

    /**
     * @param string $field
     * @return string
     * @throws SandboxRunnerException
     */
    public function requireString(string $field): string
    {
        $value = $this->data[$field] ?? null;
        if (!is_string($value)) {
            throw static::invalid($this->context . '.' . $field, 'is no string');
        }
        return $value;
    }

    /**
     * @param string $field
     * @return string|null null when the field is missing (the runner leaves empty optional fields out)
     * @throws SandboxRunnerException the field is there but no string
     */
    public function findString(string $field): ?string
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null) {
            return null;
        }
        return $this->requireString($field);
    }

    /**
     * @param string $field
     * @return int
     * @throws SandboxRunnerException
     */
    public function requireInt(string $field): int
    {
        $value = $this->data[$field] ?? null;
        if (!is_int($value)) {
            throw static::invalid($this->context . '.' . $field, 'is no integer');
        }
        return $value;
    }

    /**
     * @param string $field
     * @return int|null null when missing or null (an exit code while the command runs)
     * @throws SandboxRunnerException
     */
    public function findInt(string $field): ?int
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null) {
            return null;
        }
        return $this->requireInt($field);
    }

    /**
     * @param string $field
     * @return float
     * @throws SandboxRunnerException
     */
    public function requireNumber(string $field): float
    {
        $value = $this->data[$field] ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw static::invalid($this->context . '.' . $field, 'is no number');
        }
        return (float)$value;
    }

    /**
     * @param string $field
     * @return bool
     * @throws SandboxRunnerException
     */
    public function requireBool(string $field): bool
    {
        $value = $this->data[$field] ?? null;
        if (!is_bool($value)) {
            throw static::invalid($this->context . '.' . $field, 'is no boolean');
        }
        return $value;
    }

    /**
     * @param string $field
     * @return bool false when missing (the runner leaves false optional flags out)
     * @throws SandboxRunnerException
     */
    public function findBool(string $field): bool
    {
        if (!array_key_exists($field, $this->data)) {
            return false;
        }
        return $this->requireBool($field);
    }

    /**
     * @param string $field
     * @return DateTimeImmutable
     * @throws SandboxRunnerException
     */
    public function requireMoment(string $field): DateTimeImmutable
    {
        $moment = $this->findMoment($field);
        if ($moment === null) {
            throw static::invalid($this->context . '.' . $field, 'is no RFC 3339 moment');
        }
        return $moment;
    }

    /**
     * @param string $field
     * @return DateTimeImmutable|null null when missing or null (a start time /proc could not give)
     * @throws SandboxRunnerException the field is there but no RFC 3339 moment
     */
    public function findMoment(string $field): ?DateTimeImmutable
    {
        $text = $this->findString($field);
        if ($text === null || $text === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($text);
        } catch (Exception) {
            throw static::invalid($this->context . '.' . $field, 'is no RFC 3339 moment');
        }
    }

    /**
     * @param string $field
     * @return array<int, static> each element read strictly as an object
     * @throws SandboxRunnerException
     */
    public function requireObjectList(string $field): array
    {
        $value = $this->data[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw static::invalid($this->context . '.' . $field, 'is no list');
        }
        $readers = [];
        foreach ($value as $index => $element) {
            $readers[] = static::of($element, $this->context . '.' . $field . '[' . $index . ']');
        }
        return $readers;
    }

    /**
     * @param string $field
     * @return array<string, int> a map of byte counts (e.g. byDirectory); empty when missing
     * @throws SandboxRunnerException
     */
    public function findIntMap(string $field): array
    {
        $value = $this->data[$field] ?? [];
        if (!is_array($value)) {
            throw static::invalid($this->context . '.' . $field, 'is no object');
        }
        $map = [];
        foreach ($value as $key => $count) {
            if (!is_int($count)) {
                throw static::invalid($this->context . '.' . $field . '.' . $key, 'is no integer');
            }
            $map[(string)$key] = $count;
        }
        return $map;
    }
}
