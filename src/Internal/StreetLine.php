<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Internal;

/**
 * The one street-line rule of Address::$street for every source: a name followed by the house number and the
 * orientation number, "Duhová 1444/2". Splitting accepts only unambiguous shapes; anything else returns null, and the
 * caller keeps the source value in Address::$street alone.
 *
 * @internal
 */
final class StreetLine
{
    private const string NUMBERS = '([0-9]+)(?:\/([0-9]+[a-zA-Z]?))?';

    private function __construct()
    {
    }

    /**
     * "Duhová 1444/2"; the name alone without numbers; null without a name.
     */
    public static function compose(?string $name, ?string $houseNumber, ?string $orientationNumber): ?string
    {
        if (null === $name) {
            return null;
        }

        $numbers = implode('/', array_filter([$houseNumber, $orientationNumber], static fn (?string $n): bool => null !== $n));

        return '' === $numbers ? $name : $name.' '.$numbers;
    }

    /**
     * Splits a house-number field such as ISIR cisloPopisne: "921/2", "153" or "čp.153" (descriptive number, type 1).
     *
     * @return ?array{houseNumber: string, orientationNumber: ?string, houseNumberType: ?int}
     */
    public static function splitNumbers(string $value): ?array
    {
        if (1 !== preg_match('/^(čp\.\s*)?'.self::NUMBERS.'$/Du', $value, $m)) {
            return null;
        }

        return [
            'houseNumber' => $m[2],
            'orientationNumber' => '' === ($m[3] ?? '') ? null : $m[3],
            'houseNumberType' => '' === $m[1] ? null : 1,
        ];
    }

    /**
     * Splits a whole street line such as ADIS uliceCislo: "Kobližná 70/4", or "153" without a street name. A name
     * ending with a dot is a number label ("č.p.", "ev.č.") whose meaning is not known, so such a line is not split.
     *
     * @return ?array{streetName: ?string, houseNumber: string, orientationNumber: ?string}
     */
    public static function splitLine(string $line): ?array
    {
        if (1 !== preg_match('/^(?:(.*\S)\s+)?'.self::NUMBERS.'$/Du', $line, $m) || str_ends_with($m[1], '.')) {
            return null;
        }

        return [
            'streetName' => '' === $m[1] ? null : $m[1],
            'houseNumber' => $m[2],
            'orientationNumber' => '' === ($m[3] ?? '') ? null : $m[3],
        ];
    }
}
