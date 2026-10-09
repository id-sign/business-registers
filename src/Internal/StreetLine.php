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

    /** Number labels of a known meaning: "č.p." / "čp." descriptive (type 1), "č.ev." / "ev.č." registration (type 2). */
    private const string LABEL = '(č\.\s*p\.|čp\.|č\.\s*ev\.|ev\.\s*č\.)';

    /** A last word that looks like a number label of an unknown spelling ("č.pop.", "čís.", "čp"). */
    private const string LABEL_LIKE = '/(?:^|\s)(?:č|čp|čís|ev)(?:\.\S*)?$/Diu';

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
     * Splits a house-number field such as ISIR cisloPopisne: "921/2", "153", or with a known label "čp.153".
     *
     * @return ?array{houseNumber: string, orientationNumber: ?string, houseNumberType: ?int}
     */
    public static function splitNumbers(string $value): ?array
    {
        if (1 !== preg_match('/^(?:'.self::LABEL.'\s*)?'.self::NUMBERS.'$/Diu', $value, $m)) {
            return null;
        }

        return [
            'houseNumber' => $m[2],
            'orientationNumber' => '' === ($m[3] ?? '') ? null : $m[3],
            'houseNumberType' => self::type($m[1]),
        ];
    }

    /**
     * Splits a whole street line such as ADIS uliceCislo: "Kobližná 70/4", "Masarykovo nám. 292", "Pramenná č.ev.3",
     * or "153" without a street name. A known label sets the house-number type and is left out of the parts; a last
     * word that looks like a label of an unknown spelling ("č.pop. 12") leaves the line unsplit.
     *
     * @return ?array{streetName: ?string, houseNumber: string, orientationNumber: ?string, houseNumberType: ?int}
     */
    public static function splitLine(string $line): ?array
    {
        // the lazy, optional name lets a label directly before the numbers be read as the label, not as part of the name
        if (1 !== preg_match('/^(?:(.*?\S)\s+)??(?:'.self::LABEL.'\s*)?'.self::NUMBERS.'$/Diu', $line, $m)
            || 1 === preg_match(self::LABEL_LIKE, $m[1])) {
            return null;
        }

        return [
            'streetName' => '' === $m[1] ? null : $m[1],
            'houseNumber' => $m[3],
            'orientationNumber' => '' === ($m[4] ?? '') ? null : $m[4],
            'houseNumberType' => self::type($m[2]),
        ];
    }

    private static function type(string $label): ?int
    {
        if ('' === $label) {
            return null;
        }

        return str_contains(mb_strtolower($label), 'ev') ? 2 : 1;
    }
}
