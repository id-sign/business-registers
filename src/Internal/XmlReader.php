<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Internal;

use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Source;

/**
 * Typed access to one element of an XML response.
 *
 * Names are qualified with the prefixes passed to fromString(); an unprefixed name matches only
 * elements without a namespace. Lookups see direct children only.
 *
 * Methods without a prefix are mandatory: an absent node or a text that is empty after trimming
 * throws InvalidResponse; the optional… variants return null instead. An unreadable integer, date
 * or date-time throws in both.
 *
 * Error messages name the path and the expected type, never a value from the response.
 * Paths are XPath-style: names joined by "/", positions 1-based, attributes with "@"
 * (s:Body/r:Response/r:statusSubjektu[2]/@dic).
 *
 * @internal
 */
final readonly class XmlReader
{
    private function __construct(
        private \Dom\Element $element,
        private \Dom\XPath $xpath,
        public Source $source,
        private string $path,
    ) {
    }

    /**
     * @param array<string, string> $namespaces prefix => namespace URI
     *
     * @throws InvalidResponse when the body is not well-formed XML
     */
    public static function fromString(string $xml, Source $source, array $namespaces): self
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = \Dom\XMLDocument::createFromString($xml, \LIBXML_NONET);
        } catch (\DOMException|\ValueError $e) {
            // an empty string is a ValueError, not a parse error
            throw new InvalidResponse(self::label($source).': response is not well-formed XML', $source, previous: $e);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;
        if (null === $root) {
            throw new InvalidResponse(self::label($source).': response is not well-formed XML', $source);
        }

        // only the prefixes given here resolve, whatever prefixes the document itself uses
        $xpath = new \Dom\XPath($document, false);
        foreach ($namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return new self($root, $xpath, $source, '');
    }

    /**
     * @throws InvalidResponse
     */
    public function element(string $name): self
    {
        return $this->optionalElement($name) ?? throw $this->missing($name);
    }

    public function optionalElement(string $name): ?self
    {
        $children = $this->children($name);

        return [] === $children ? null : new self($children[0], $this->xpath, $this->source, $this->childPath($name));
    }

    /**
     * @return list<self>
     */
    public function elements(string $name): array
    {
        $readers = [];
        foreach ($this->children($name) as $index => $child) {
            $readers[] = new self($child, $this->xpath, $this->source, \sprintf('%s[%d]', $this->childPath($name), $index + 1));
        }

        return $readers;
    }

    /**
     * @throws InvalidResponse
     */
    public function attribute(string $name): string
    {
        return $this->optionalAttribute($name) ?? throw $this->missing('@'.$name);
    }

    public function optionalAttribute(string $name): ?string
    {
        return self::nonEmpty($this->element->getAttribute($name));
    }

    /**
     * Y-m-d as midnight in Europe/Prague.
     *
     * @throws InvalidResponse
     */
    public function dateAttribute(string $name): \DateTimeImmutable
    {
        return $this->optionalDateAttribute($name) ?? throw $this->missing('@'.$name);
    }

    /**
     * @throws InvalidResponse
     */
    public function optionalDateAttribute(string $name): ?\DateTimeImmutable
    {
        $value = $this->optionalAttribute($name);
        if (null === $value) {
            return null;
        }

        return Dates::date($value) ?? throw $this->invalid('@'.$name, 'date (Y-m-d)');
    }

    /**
     * Trimmed text of the first child element $name.
     *
     * @throws InvalidResponse
     */
    public function string(string $name): string
    {
        return $this->optionalString($name) ?? throw $this->missing($name);
    }

    public function optionalString(string $name): ?string
    {
        $children = $this->children($name);

        return [] === $children ? null : self::nonEmpty($children[0]->textContent);
    }

    /**
     * Child text of digits as an integer.
     *
     * @throws InvalidResponse
     */
    public function int(string $name): int
    {
        return $this->optionalInt($name) ?? throw $this->missing($name);
    }

    /**
     * @throws InvalidResponse
     */
    public function optionalInt(string $name): ?int
    {
        $text = $this->optionalString($name);
        if (null === $text) {
            return null;
        }

        return Integers::fromDigits($text) ?? throw $this->invalid($name, 'integer');
    }

    /**
     * Child text Y-m-d as midnight in Europe/Prague; no mandatory twin, no source needs one.
     *
     * @throws InvalidResponse
     */
    public function optionalDate(string $name): ?\DateTimeImmutable
    {
        $value = $this->optionalString($name);
        if (null === $value) {
            return null;
        }

        // xsd:date allows one zone offset; like the "Z" suffix it is ignored
        $value = preg_replace('/^(\d{4}-\d{2}-\d{2})[+-]\d{2}:\d{2}$/D', '$1', $value) ?? $value;

        return Dates::date($value) ?? throw $this->invalid($name, 'date (Y-m-d)');
    }

    /**
     * Child text date and time as Europe/Prague local time, see Dates::dateTimePrague().
     *
     * @throws InvalidResponse
     */
    public function optionalDateTimePrague(string $name): ?\DateTimeImmutable
    {
        $value = $this->optionalString($name);
        if (null === $value) {
            return null;
        }

        return Dates::dateTimePrague($value) ?? throw $this->invalid($name, 'date-time');
    }

    /**
     * For the caller's own validation: `throw $reader->invalid('@typSubjektu', 'known subject type')`.
     *
     * @param string $relative "@attribute" or a child element name
     */
    public function invalid(string $relative, string $expected, ?string $errorCode = null): InvalidResponse
    {
        return new InvalidResponse(
            \sprintf('%s: expected %s at %s', self::label($this->source), $expected, $this->childPath($relative)),
            $this->source,
            $errorCode,
        );
    }

    /**
     * @return list<\Dom\Element>
     */
    private function children(string $name): array
    {
        $elements = [];
        foreach ($this->xpath->query('./'.$name, $this->element) as $node) {
            if ($node instanceof \Dom\Element) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private static function nonEmpty(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function childPath(string $name): string
    {
        return '' === $this->path ? $name : $this->path.'/'.$name;
    }

    private function missing(string $relative): InvalidResponse
    {
        return new InvalidResponse(\sprintf('%s: missing %s', self::label($this->source), $this->childPath($relative)), $this->source);
    }

    private static function label(Source $source): string
    {
        return strtoupper($source->value);
    }
}
