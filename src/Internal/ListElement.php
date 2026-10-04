<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Internal;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\VatId;

/**
 * Type check of an element of an id list passed in by the caller.
 *
 * @internal
 */
final class ListElement
{
    private function __construct()
    {
    }

    /**
     * Takes mixed because callers without static analysis can pass any element type.
     *
     * @template T of CompanyId|VatId
     *
     * @param int|string      $index position or key of the element in the caller's array
     * @param class-string<T> $class
     *
     * @return T|string
     *
     * @throws InvalidInput an element that is neither a $class nor a string
     */
    public static function idOrString(mixed $element, int|string $index, string $class): CompanyId|VatId|string
    {
        if ($element instanceof $class || \is_string($element)) {
            return $element;
        }

        throw new InvalidInput(\sprintf('Id at index %s must be a %s or string, %s given.', $index, new \ReflectionClass($class)->getShortName(), get_debug_type($element)));
    }
}
