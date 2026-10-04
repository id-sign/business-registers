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

    public function testHelperCannotBeInstantiated(): void
    {
        $constructor = (new \ReflectionClass(Dates::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }
}
