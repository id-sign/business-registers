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
        yield 'registration number prefix' => ['č.ev.12', ['houseNumber' => '12', 'orientationNumber' => null, 'houseNumberType' => 2]];
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
     * Shapes seen in ADIS uliceCislo (live sample of 478 VAT payers, 2026-10-09).
     *
     * @return iterable<string, array{string, array{streetName: ?string, houseNumber: string, orientationNumber: ?string, houseNumberType: ?int}}>
     */
    public static function provideLines(): iterable
    {
        yield 'street with both numbers' => ['Kobližná 70/4', self::parts('Kobližná', '70', '4')];
        yield 'street name of several words' => ['nám. Přemysla Otakara 123', self::parts('nám. Přemysla Otakara', '123')];
        yield 'orientation number with a letter' => ['Vinohradská 1999/120a', self::parts('Vinohradská', '1999', '120a')];
        yield 'number without a street name' => ['153', self::parts(null, '153')];
        yield 'street name abbreviated with a dot' => ['Masarykovo nám. 292', self::parts('Masarykovo nám.', '292')];
        yield 'street name ending with a dotted numeral' => ['nám. Přemysla Otakara II. 128/39', self::parts('nám. Přemysla Otakara II.', '128', '39')];
        yield 'street name starting with a number' => ['9. května 2452', self::parts('9. května', '2452')];
        yield 'registration number' => ['Pramenná č.ev.3', self::parts('Pramenná', '3', null, 2)];
        yield 'registration number without a street name' => ['č.ev.92', self::parts(null, '92', null, 2)];
        yield 'registration number with an orientation number' => ['Floriánské nám. č.ev.206/10', self::parts('Floriánské nám.', '206', '10', 2)];
        yield 'registration number label with a space' => ['Šedesátá č.ev. 64/2', self::parts('Šedesátá', '64', '2', 2)];
        yield 'registration number label reversed' => ['Lhota ev.č. 12', self::parts('Lhota', '12', null, 2)];
        yield 'descriptive number label' => ['č.p. 153', self::parts(null, '153', null, 1)];
        yield 'descriptive number label in upper case' => ['LHOTA ČP. 153', self::parts('LHOTA', '153', null, 1)];
    }

    /**
     * @param array{streetName: ?string, houseNumber: string, orientationNumber: ?string, houseNumberType: ?int} $expected
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
        yield 'unknown number label' => ['č.pop. 12'];
        yield 'unknown number label after a street name' => ['Lhota čís. 12'];
        yield 'number label without a dot' => ['Lhota čp 153'];
        yield 'non-ASCII digits' => ['Duhová １４４４'];
    }

    #[DataProvider('provideUnsplitLines')]
    public function testSplitLineLeavesAnUncertainLineWhole(string $line): void
    {
        self::assertNull(StreetLine::splitLine($line));
    }

    /**
     * @return array{streetName: ?string, houseNumber: string, orientationNumber: ?string, houseNumberType: ?int}
     */
    private static function parts(?string $streetName, string $houseNumber, ?string $orientationNumber = null, ?int $houseNumberType = null): array
    {
        return ['streetName' => $streetName, 'houseNumber' => $houseNumber, 'orientationNumber' => $orientationNumber, 'houseNumberType' => $houseNumberType];
    }
}
