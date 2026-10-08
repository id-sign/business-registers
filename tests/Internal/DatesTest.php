<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Internal;

use IdSign\BusinessRegisters\Internal\Dates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Dates::class)]
final class DatesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideValidDates(): iterable
    {
        yield 'summer date' => ['2022-09-13', '2022-09-13T00:00:00+02:00'];
        yield 'winter date' => ['2022-01-15', '2022-01-15T00:00:00+01:00'];
        yield 'date with zone suffix' => ['2022-09-13Z', '2022-09-13T00:00:00+02:00'];
        yield 'winter date with zone suffix' => ['2022-01-15Z', '2022-01-15T00:00:00+01:00'];
        yield 'leap day' => ['2024-02-29', '2024-02-29T00:00:00+01:00'];
    }

    #[DataProvider('provideValidDates')]
    public function testDateIsMidnightInPrague(string $input, string $expected): void
    {
        $date = Dates::date($input);

        self::assertNotNull($date);
        self::assertSame($expected, $date->format('c'));
        self::assertSame('Europe/Prague', $date->getTimezone()->getName());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidDates(): iterable
    {
        yield 'month and day overflow' => ['2022-13-45'];
        yield 'day overflow in a valid month' => ['2022-02-30'];
        yield 'leap day in a common year' => ['2023-02-29'];
        yield 'letters' => ['abc'];
        yield 'empty' => [''];
        yield 'only the zone suffix' => ['Z'];
        yield 'doubled zone suffix' => ['2022-09-13ZZ'];
        yield 'not zero padded' => ['2022-9-3'];
        yield 'datetime instead of date' => ['2022-09-13T10:00:00'];
        yield 'leading whitespace' => [' 2022-09-13'];
        yield 'trailing garbage' => ['2022-09-13x'];
    }

    #[DataProvider('provideInvalidDates')]
    public function testMalformedOrOverflowingDateYieldsNull(string $input): void
    {
        self::assertNull(Dates::date($input));
    }

    public function testDatetimeWithZuluSuffixIsTheSameInstantInUtc(): void
    {
        $dateTime = Dates::dateTimeUtc('2026-10-03T14:18:12.832Z');

        self::assertNotNull($dateTime);
        self::assertSame(0, $dateTime->getOffset());
        self::assertSame('2026-10-03T14:18:12.832+00:00', $dateTime->format('Y-m-d\TH:i:s.vP'));
    }

    public function testDatetimeWithExplicitOffsetIsConvertedToTheSameInstant(): void
    {
        $dateTime = Dates::dateTimeUtc('2026-10-03T16:18:12+02:00');

        self::assertNotNull($dateTime);
        self::assertSame(
            (new \DateTimeImmutable('2026-10-03T14:18:12Z'))->getTimestamp(),
            $dateTime->getTimestamp(),
        );
    }

    public function testDatetimeWithCompactOffsetIsConvertedToUtc(): void
    {
        $dateTime = Dates::dateTimeUtc('2026-10-03T19:48:12+0530');

        self::assertNotNull($dateTime);
        self::assertSame('2026-10-03T14:18:12+00:00', $dateTime->format('c'));
    }

    public function testDatetimeWithoutZoneIsInterpretedAsUtc(): void
    {
        $dateTime = Dates::dateTimeUtc('2026-10-03 14:18:12');

        self::assertNotNull($dateTime);
        self::assertSame(0, $dateTime->getOffset());
        self::assertSame('2026-10-03T14:18:12+00:00', $dateTime->format('c'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidDateTimes(): iterable
    {
        yield 'garbage' => ['garbage'];
        yield 'empty' => [''];
        yield 'overflowing day' => ['2026-02-30T10:00:00Z'];
        yield 'month out of range' => ['2026-13-01T10:00:00Z'];
        yield 'hour out of range' => ['2026-10-03T25:00:00Z'];
        yield 'relative now' => ['now'];
        yield 'relative tomorrow' => ['tomorrow'];
    }

    #[DataProvider('provideInvalidDateTimes')]
    public function testUnparsableDatetimeYieldsNull(string $input): void
    {
        self::assertNull(Dates::dateTimeUtc($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePragueDateTimes(): iterable
    {
        yield 'zulu suffix is not a zone' => ['2026-10-08T09:26:35.000Z', '2026-10-08T09:26:35+02:00'];
        yield 'explicit offset is ignored' => ['2026-10-08T09:26:35+02:00', '2026-10-08T09:26:35+02:00'];
        yield 'foreign offset is ignored' => ['2026-10-08T09:26:35-05:00', '2026-10-08T09:26:35+02:00'];
        yield 'compact offset is ignored' => ['2026-10-08T09:26:35+0530', '2026-10-08T09:26:35+02:00'];
        yield 'no zone suffix' => ['2026-10-08T09:26:35', '2026-10-08T09:26:35+02:00'];
        yield 'space separator' => ['2026-10-08 09:26:35', '2026-10-08T09:26:35+02:00'];
        yield 'fraction is dropped' => ['2026-10-08T09:26:35.987Z', '2026-10-08T09:26:35+02:00'];
        yield 'winter value keeps the winter offset' => ['2026-01-15T09:26:35.000Z', '2026-01-15T09:26:35+01:00'];
        yield 'summer value keeps the summer offset' => ['2026-07-15T09:26:35.000Z', '2026-07-15T09:26:35+02:00'];
        yield 'leap day' => ['2024-02-29T23:59:59Z', '2024-02-29T23:59:59+01:00'];
    }

    #[DataProvider('providePragueDateTimes')]
    public function testPragueDateTimeReadsTheClockValueAsLocalTimeWhateverTheSuffix(string $input, string $expected): void
    {
        $dateTime = Dates::dateTimePrague($input);

        self::assertNotNull($dateTime);
        self::assertSame($expected, $dateTime->format('c'));
        self::assertSame('Europe/Prague', $dateTime->getTimezone()->getName());
    }

    public function testPragueDateTimeDropsTheFraction(): void
    {
        $dateTime = Dates::dateTimePrague('2026-10-08T09:26:35.987Z');

        self::assertNotNull($dateTime);
        self::assertSame('000000', $dateTime->format('u'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideClockValuesAroundTheSwitchToDaylightSaving(): iterable
    {
        yield 'hour skipped by the spring switch' => ['2026-03-29T02:30:00Z'];
        yield 'hour repeated by the autumn switch' => ['2026-10-25T02:30:00Z'];
    }

    #[DataProvider('provideClockValuesAroundTheSwitchToDaylightSaving')]
    public function testPragueDateTimeAcceptsAClockValueAroundAZoneSwitch(string $input): void
    {
        self::assertNotNull(Dates::dateTimePrague($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidPragueDateTimes(): iterable
    {
        yield 'garbage' => ['garbage'];
        yield 'empty' => [''];
        yield 'date without time' => ['2026-10-08'];
        yield 'date with zone suffix and no time' => ['2026-10-08Z'];
        yield 'overflowing day' => ['2026-02-30T10:00:00Z'];
        yield 'leap day in a common year' => ['2026-02-29T10:00:00Z'];
        yield 'month out of range' => ['2026-13-01T10:00:00Z'];
        yield 'hour out of range' => ['2026-10-08T25:00:00Z'];
        yield 'minute out of range' => ['2026-10-08T10:61:00Z'];
        yield 'relative now' => ['now'];
        yield 'relative tomorrow' => ['tomorrow'];
        yield 'leading whitespace' => [' 2026-10-08T09:26:35Z'];
        yield 'trailing garbage' => ['2026-10-08T09:26:35Zx'];
        yield 'named zone' => ['2026-10-08T09:26:35 Europe/London'];
    }

    #[DataProvider('provideInvalidPragueDateTimes')]
    public function testUnparsablePragueDateTimeYieldsNull(string $input): void
    {
        self::assertNull(Dates::dateTimePrague($input));
    }

    public function testHelperCannotBeInstantiated(): void
    {
        $constructor = (new \ReflectionClass(Dates::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }
}
