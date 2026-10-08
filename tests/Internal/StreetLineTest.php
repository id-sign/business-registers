<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Internal;

use IdSign\BusinessRegisters\Internal\StreetLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreetLine::class)]
final class StreetLineTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, ?string, ?string}>
     */
    public static function provideCompositions(): iterable
    {
        yield 'house and orientation number' => ['Duhová', '1444', '2', 'Duhová 1444/2'];
        yield 'house number only' => ['Libotenice', '153', null, 'Libotenice 153'];
        yield 'orientation number only' => ['Duhová', null, '2', 'Duhová 2'];
        yield 'name only' => ['Duhová', null, null, 'Duhová'];
        yield 'no name' => [null, '153', null, null];
    }

    #[DataProvider('provideCompositions')]
    public function testComposeWritesTheNameAndTheNumbers(?string $name, ?string $house, ?string $orientation, ?string $expected): void
    {
        self::assertSame($expected, StreetLine::compose($name, $house, $orientation));
    }

    /**
     * Shapes seen in ISIR cisloPopisne.
     *
     * @return iterable<string, array{string, array{houseNumber: string, orientationNumber: ?string, houseNumberType: ?int}}>
     */
    public static function provideNumbers(): iterable
    {
        yield 'house number' => ['2179', ['houseNumber' => '2179', 'orientationNumber' => null, 'houseNumberType' => null]];
        yield 'house and orientation number' => ['921/2', ['houseNumber' => '921', 'orientationNumber' => '2', 'houseNumberType' => null]];
        yield 'orientation number with a letter' => ['1068/30a', ['houseNumber' => '1068', 'orientationNumber' => '30a', 'houseNumberType' => null]];
        yield 'descriptive number prefix' => ['čp.153', ['houseNumber' => '153', 'orientationNumber' => null, 'houseNumberType' => 1]];
    }

    /**
     * @param array{houseNumber: string, orientationNumber: ?string, houseNumberType: ?int} $expected
     */
    #[DataProvider('provideNumbers')]
    public function testSplitNumbersReadsTheKnownShapes(string $value, array $expected): void
    {
        self::assertSame($expected, StreetLine::splitNumbers($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnknownNumbers(): iterable
    {
        yield 'letter on the house number' => ['332E'];
        yield 'text' => ['neuvedeno'];
        yield 'two slashes' => ['1/2/3'];
        yield 'non-ASCII digits' => ['１５３'];
    }

    #[DataProvider('provideUnknownNumbers')]
    public function testSplitNumbersRejectsUnknownShapes(string $value): void
    {
        self::assertNull(StreetLine::splitNumbers($value));
    }

    /**
     * Shapes seen in ADIS uliceCislo.
     *
     * @return iterable<string, array{string, array{streetName: ?string, houseNumber: string, orientationNumber: ?string}}>
     */
    public static function provideLines(): iterable
    {
        yield 'street with both numbers' => ['Kobližná 70/4', ['streetName' => 'Kobližná', 'houseNumber' => '70', 'orientationNumber' => '4']];
        yield 'street name of several words' => ['nám. Přemysla Otakara 123', ['streetName' => 'nám. Přemysla Otakara', 'houseNumber' => '123', 'orientationNumber' => null]];
        yield 'orientation number with a letter' => ['Vinohradská 1999/120a', ['streetName' => 'Vinohradská', 'houseNumber' => '1999', 'orientationNumber' => '120a']];
        yield 'number without a street name' => ['153', ['streetName' => null, 'houseNumber' => '153', 'orientationNumber' => null]];
    }

    /**
     * @param array{streetName: ?string, houseNumber: string, orientationNumber: ?string} $expected
     */
    #[DataProvider('provideLines')]
    public function testSplitLineSeparatesTheTrailingNumbers(string $line, array $expected): void
    {
        self::assertSame($expected, StreetLine::splitLine($line));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsplitLines(): iterable
    {
        yield 'no trailing numbers' => ['Náměstí Míru'];
        yield 'descriptive number label' => ['č.p. 153'];
        yield 'registration number label' => ['Lhota ev.č. 12'];
        yield 'non-ASCII digits' => ['Duhová １４４４'];
    }

    #[DataProvider('provideUnsplitLines')]
    public function testSplitLineLeavesAnUncertainLineWhole(string $line): void
    {
        self::assertNull(StreetLine::splitLine($line));
    }
}
