<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Internal;

/**
 * Pure integer conversion shared by the readers; returns null for input it cannot read, callers add the key path.
 *
 * @internal
 */
final class Integers
{
    private function __construct()
    {
    }

    /**
     * A non-negative integer written as digits only; null for any other text or a value beyond PHP_INT_MAX.
     */
    public static function fromDigits(string $text): ?int
    {
        if (1 !== preg_match('/^\d+$/D', $text)) {
            return null;
        }

        $int = (int) $text;
        // the cast saturates on overflow
        if ((string) $int !== (preg_replace('/^0+(?=\d)/', '', $text) ?? $text)) {
            return null;
        }

        return $int;
    }
}
