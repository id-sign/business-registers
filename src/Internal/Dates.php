<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Internal;

/**
 * Pure date conversion; returns null for input it cannot read, callers add the key path.
 *
 * @internal
 */
final class Dates
{
    private const string DATE_FORMAT = 'Y-m-d';
    private const string DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/D';

    private function __construct()
    {
    }

    /**
     * Midnight in Europe/Prague; one trailing "Z" zone suffix (ISIR) is ignored.
     */
    public static function date(string $value): ?\DateTimeImmutable
    {
        if (str_ends_with($value, 'Z')) {
            $value = substr($value, 0, -1);
        }

        $date = \DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value, new \DateTimeZone('Europe/Prague'));

        // createFromFormat() silently overflows values such as 2022-13-45
        if (false === $date || $date->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * ISO 8601 date and time converted to UTC; a value without a zone is read as UTC.
     */
    public static function dateTimeUtc(string $value): ?\DateTimeImmutable
    {
        // the constructor would also accept relative formats such as "now" or "tomorrow"
        if (1 !== preg_match(self::DATE_TIME_PATTERN, $value)) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        try {
            $dateTime = new \DateTimeImmutable($value, $utc);
        } catch (\DateMalformedStringException) {
            return null;
        }

        // the constructor silently overflows values such as 2026-02-30 and reports it only as a warning
        if (false !== \DateTimeImmutable::getLastErrors()) {
            return null;
        }

        return $dateTime->setTimezone($utc);
    }
}
