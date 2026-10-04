<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests;

use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\VatId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VatId::class)]
final class VatIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, string, string}>
     */
    public static function provideValidInputs(): iterable
    {
        yield 'czech with prefix' => ['CZ45274649', null, 'CZ', '45274649'];
        yield 'lowercase with spaces' => ['cz 4527 4649', null, 'CZ', '45274649'];
        yield 'surrounding whitespace' => [" CZ45274649\n", null, 'CZ', '45274649'];
        yield 'default country applied' => ['45274649', 'CZ', 'CZ', '45274649'];
        yield 'lowercase default country applied' => ['45274649', 'cz', 'CZ', '45274649'];
        yield 'prefix wins over default country' => ['DE811115368', 'CZ', 'DE', '811115368'];
        yield 'greece from default country is normalised to EL' => ['123456789', 'GR', 'EL', '123456789'];
        yield 'greece is normalised to EL' => ['GR123456789', null, 'EL', '123456789'];
        yield 'lowercase greece is normalised to EL' => ['gr123456789', null, 'EL', '123456789'];
        yield 'EL is kept' => ['EL123456789', null, 'EL', '123456789'];
        yield 'german' => ['DE811115368', null, 'DE', '811115368'];
        yield 'northern ireland' => ['XI123456789', null, 'XI', '123456789'];
        yield 'foreign number with letters' => ['IE1234567WA', null, 'IE', '1234567WA'];
        yield 'foreign number with plus and asterisk' => ['FR1+2*34', null, 'FR', '1+2*34'];
        yield 'czech ten digits (natural person)' => ['CZ7001011234', null, 'CZ', '7001011234'];
        yield 'czech eight digits' => ['CZ00121100', null, 'CZ', '00121100'];
        yield 'foreign two character number' => ['SK12', null, 'SK', '12'];
        yield 'foreign twelve character number' => ['SK123456789012', null, 'SK', '123456789012'];
    }

    #[DataProvider('provideValidInputs')]
    public function testValidInputIsSplitIntoCountryAndNumber(string $input, ?string $default, string $country, string $number): void
    {
        $id = VatId::parse($input, $default);

        self::assertSame($country, $id->countryCode);
        self::assertSame($number, $id->number);
    }

    #[DataProvider('provideValidInputs')]
    public function testTryParseReturnsTheSameResultAsParseForValidInput(string $input, ?string $default, string $country, string $number): void
    {
        $id = VatId::tryParse($input, $default);

        self::assertNotNull($id);
        self::assertSame($country.$number, (string) $id);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideInvalidInputs(): iterable
    {
        yield 'no country and no default' => ['45274649', null];
        yield 'empty' => ['', null];
        yield 'empty with default' => ['', 'CZ'];
        yield 'czech too short' => ['CZ123', null];
        yield 'czech seven digits' => ['CZ1234567', null];
        yield 'czech eleven digits' => ['CZ12345678901', null];
        yield 'czech letters' => ['CZABC', null];
        yield 'czech letters in number' => ['CZ4527464A', null];
        yield 'czech through default too short' => ['123', 'CZ'];
        yield 'foreign one character number' => ['DE1', null];
        yield 'foreign thirteen character number' => ['DE1234567890123', null];
        yield 'foreign number with forbidden character' => ['DE12345-678', null];
        yield 'default country is not two letters' => ['45274649', 'CZE'];
        yield 'default country is a single letter' => ['45274649', 'C'];
        yield 'default country contains digits' => ['45274649', '12'];
    }

    #[DataProvider('provideInvalidInputs')]
    public function testInvalidInputIsRejectedWithInvalidInput(string $input, ?string $default): void
    {
        $this->expectException(InvalidInput::class);

        VatId::parse($input, $default);
    }

    #[DataProvider('provideInvalidInputs')]
    public function testTryParseReturnsNullForInvalidInput(string $input, ?string $default): void
    {
        self::assertNull(VatId::tryParse($input, $default));
    }

    public function testStringRepresentationCarriesTheCountryPrefix(): void
    {
        self::assertSame('CZ45274649', (string) VatId::parse('45274649', 'CZ'));
    }

    public function testGreekIdIsRenderedWithTheViesCountryCode(): void
    {
        self::assertSame('EL123456789', (string) VatId::parse('GR123456789'));
    }

    public function testCzechIdIsCzech(): void
    {
        self::assertTrue(VatId::parse('CZ45274649')->isCzech());
    }

    public function testForeignIdIsNotCzech(): void
    {
        self::assertFalse(VatId::parse('DE811115368')->isCzech());
    }

    public function testIdsAreEqualWhenCountryAndNumberMatchRegardlessOfInputForm(): void
    {
        self::assertTrue(VatId::parse('cz 4527 4649')->equals(VatId::parse('45274649', 'CZ')));
    }

    public function testIdsWithDifferentNumbersAreNotEqual(): void
    {
        self::assertFalse(VatId::parse('CZ45274649')->equals(VatId::parse('CZ45317054')));
    }

    public function testIdsWithDifferentCountriesAreNotEqual(): void
    {
        self::assertFalse(VatId::parse('SK1234567890')->equals(VatId::parse('CZ1234567890')));
    }

    public function testDefaultCountryWithTrailingNewlineDoesNotBypassTheCzechNumberRule(): void
    {
        $this->expectException(InvalidInput::class);

        VatId::parse('123', "CZ\n");
    }

    public function testMalformedDefaultCountryIsReportedAsInvalidDefaultCountry(): void
    {
        try {
            VatId::parse('45274649', 'CZE');
            self::fail('InvalidInput expected.');
        } catch (InvalidInput $e) {
            self::assertStringContainsStringIgnoringCase('default country', $e->getMessage());
            self::assertStringNotContainsString('missing country code', $e->getMessage());
        }
    }

    public function testValueObjectCannotBeConstructedDirectly(): void
    {
        $constructor = (new \ReflectionClass(VatId::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }
}
