<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Internal;

use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Source;

/**
 * Typed access to one JSON object of a response.
 *
 * Methods without a prefix are mandatory: an absent key, JSON null, a text that is empty after
 * trimming, or a value of the wrong type throw InvalidResponse. The optional… variants return
 * null (an empty array for lists) for an absent, null or empty value and still throw for a value
 * of the wrong type.
 *
 * Error messages name the key path and the expected type, never a value from the response.
 * Paths are jq-style: keys joined by dots, list indices 0-based (zaznamy[0].sidlo.psc).
 *
 * @internal
 */
final readonly class JsonReader
{
    /**
     * @param array<mixed> $data
     */
    private function __construct(
        private array $data,
        public Source $source,
        private string $path,
    ) {
    }

    /**
     * @throws InvalidResponse when the body is not JSON or its root is not an object
     */
    public static function fromJson(string $json, Source $source): self
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidResponse(self::label($source).': response is not valid JSON', $source, previous: $e);
        }

        if (!self::isObject($data)) {
            throw new InvalidResponse(self::label($source).': expected object at root', $source);
        }

        return new self($data, $source, '');
    }

    /**
     * Lenient variant for error bodies: null when the body is not a JSON object.
     */
    public static function tryFromJson(string $json, Source $source): ?self
    {
        try {
            return self::fromJson($json, $source);
        } catch (InvalidResponse) {
            return null;
        }
    }

    /**
     * Accepts a JSON integer as well, so a source switching a numeric code between string and
     * number does not break every lookup.
     *
     * @throws InvalidResponse
     */
    public function string(string $key): string
    {
        return $this->optionalString($key) ?? throw $this->missing($key);
    }

    /**
     * Accepts a JSON integer as well, see string().
     *
     * @throws InvalidResponse
     */
    public function optionalString(string $key): ?string
    {
        return $this->text($this->raw($key), $key, 'string');
    }

    /**
     * Accepts a JSON integer or a text of digits.
     *
     * @throws InvalidResponse
     */
    public function int(string $key): int
    {
        return $this->optionalInt($key) ?? throw $this->missing($key);
    }

    /**
     * @throws InvalidResponse
     */
    public function optionalInt(string $key): ?int
    {
        $value = $this->raw($key);
        if (\is_int($value)) {
            return $value;
        }

        $text = $this->text($value, $key, 'integer');
        if (null === $text) {
            return null;
        }

        return Integers::fromDigits($text) ?? throw $this->unexpected($key, 'integer');
    }

    /**
     * @throws InvalidResponse
     */
    public function bool(string $key): bool
    {
        return $this->optionalBool($key) ?? throw $this->missing($key);
    }

    /**
     * @throws InvalidResponse
     */
    public function optionalBool(string $key): ?bool
    {
        $value = $this->raw($key);
        if (null === $value || \is_bool($value)) {
            return $value;
        }

        throw $this->unexpected($key, 'boolean');
    }

    /**
     * Y-m-d as midnight in Europe/Prague.
     *
     * @throws InvalidResponse
     */
    public function date(string $key): \DateTimeImmutable
    {
        return $this->optionalDate($key) ?? throw $this->missing($key);
    }

    /**
     * @throws InvalidResponse
     */
    public function optionalDate(string $key): ?\DateTimeImmutable
    {
        $expected = 'date (Y-m-d)';
        $text = $this->text($this->raw($key), $key, $expected);
        if (null === $text) {
            return null;
        }

        return Dates::date($text) ?? throw $this->unexpected($key, $expected);
    }

    /**
     * ISO 8601 date and time converted to UTC.
     *
     * @throws InvalidResponse
     */
    public function dateTimeUtc(string $key): \DateTimeImmutable
    {
        return $this->optionalDateTimeUtc($key) ?? throw $this->missing($key);
    }

    /**
     * @throws InvalidResponse
     */
    public function optionalDateTimeUtc(string $key): ?\DateTimeImmutable
    {
        $expected = 'datetime';
        $text = $this->text($this->raw($key), $key, $expected);
        if (null === $text) {
            return null;
        }

        return Dates::dateTimeUtc($text) ?? throw $this->unexpected($key, $expected);
    }

    /**
     * @throws InvalidResponse
     */
    public function object(string $key): self
    {
        return $this->optionalObject($key) ?? throw $this->missing($key);
    }

    /**
     * @throws InvalidResponse
     */
    public function optionalObject(string $key): ?self
    {
        $value = $this->raw($key);
        if (null === $value) {
            return null;
        }

        if (!self::isObject($value)) {
            throw $this->unexpected($key, 'object');
        }

        return new self($value, $this->source, $this->childPath($key));
    }

    /**
     * @return list<self>
     *
     * @throws InvalidResponse
     */
    public function objectList(string $key): array
    {
        if (null === $this->raw($key)) {
            throw $this->missing($key);
        }

        return $this->optionalObjectList($key);
    }

    /**
     * @return list<self>
     *
     * @throws InvalidResponse
     */
    public function optionalObjectList(string $key): array
    {
        $readers = [];
        foreach ($this->list($key, 'list of objects') as $index => $element) {
            $indexed = \sprintf('%s[%d]', $key, $index);
            if (!self::isObject($element)) {
                throw $this->unexpected($indexed, 'object');
            }

            $readers[] = new self($element, $this->source, $this->childPath($indexed));
        }

        return $readers;
    }

    /**
     * Elements follow the string() rule; empty elements are dropped.
     *
     * @return list<string>
     *
     * @throws InvalidResponse
     */
    public function stringList(string $key): array
    {
        if (null === $this->raw($key)) {
            throw $this->missing($key);
        }

        return $this->optionalStringList($key);
    }

    /**
     * @return list<string>
     *
     * @throws InvalidResponse
     */
    public function optionalStringList(string $key): array
    {
        $strings = [];
        foreach ($this->list($key, 'list of strings') as $index => $element) {
            $text = $this->text($element, \sprintf('%s[%d]', $key, $index), 'string');
            if (null !== $text) {
                $strings[] = $text;
            }
        }

        return $strings;
    }

    /**
     * For the caller's own validation: `throw $reader->invalid('ico', 'company id')`.
     */
    public function invalid(string $key, string $expected): InvalidResponse
    {
        return $this->unexpected($key, $expected);
    }

    private function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @return list<mixed>
     *
     * @throws InvalidResponse
     */
    private function list(string $key, string $expected): array
    {
        $value = $this->raw($key);
        if (null === $value) {
            return [];
        }

        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->unexpected($key, $expected);
        }

        return $value;
    }

    /**
     * A JSON string or integer, trimmed; null when absent or empty.
     *
     * @param string $key may carry a list index suffix
     *
     * @throws InvalidResponse
     */
    private function text(mixed $value, string $key, string $expected): ?string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw $this->unexpected($key, $expected);
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @phpstan-assert-if-true array<mixed> $value
     */
    private static function isObject(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    private function childPath(string $key): string
    {
        return '' === $this->path ? $key : $this->path.'.'.$key;
    }

    private function missing(string $key): InvalidResponse
    {
        return new InvalidResponse(\sprintf('%s: missing %s', self::label($this->source), $this->childPath($key)), $this->source);
    }

    private function unexpected(string $key, string $expected): InvalidResponse
    {
        return new InvalidResponse(
            \sprintf('%s: expected %s at %s', self::label($this->source), $expected, $this->childPath($key)),
            $this->source,
        );
    }

    private static function label(Source $source): string
    {
        return strtoupper($source->value);
    }
}
