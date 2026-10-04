<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Adis\Internal;

use IdSign\BusinessRegisters\Adis\Internal\BankAccountNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BankAccountNumber::class)]
final class BankAccountNumberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideRecognisedForms(): iterable
    {
        yield 'prefix and number with leading zeros' => ['000019-0002808601/0100', '19', '2808601', '0100'];
        yield 'prefix without leading zeros' => ['19-2808601/0100', '19', '2808601', '0100'];
        yield 'no prefix' => ['2808601/0100', '', '2808601', '0100'];
        yield 'spaces around separators' => [' 19 - 2808601 / 0100 ', '19', '2808601', '0100'];
        yield 'number with leading zeros and no prefix' => ['0002808601/0100', '', '2808601', '0100'];
        yield 'zero prefix means no prefix' => ['000000-2808601/0100', '', '2808601', '0100'];
        yield 'czech iban from the specification' => ['CZ65 0800 0000 1920 0014 5399', '19', '2000145399', '0800'];
        yield 'czech iban without spaces' => ['CZ6508000000192000145399', '19', '2000145399', '0800'];
        yield 'czech iban in lower case' => ['cz65 0800 0000 1920 0014 5399', '19', '2000145399', '0800'];
        yield 'czech iban without prefix' => ['CZ64 2600 0000 0020 0126 8200', '', '2001268200', '2600'];
        yield 'narrow no-break spaces around the bank separator' => ["19-2808601\u{202F}/\u{202F}0100", '19', '2808601', '0100'];
        yield 'no-break spaces around the bank separator' => ["19-2808601\u{00A0}/\u{00A0}0100", '19', '2808601', '0100'];
        yield 'thin space inside digits' => ["19-28086\u{2009}01/0100", '19', '2808601', '0100'];
        yield 'hyphen as prefix separator' => ["19\u{2010}2808601/0100", '19', '2808601', '0100'];
        yield 'en dash as prefix separator' => ["19\u{2013}2808601/0100", '19', '2808601', '0100'];
        yield 'em dash as prefix separator' => ["19\u{2014}2808601/0100", '19', '2808601', '0100'];
        yield 'minus sign as prefix separator' => ["19\u{2212}2808601/0100", '19', '2808601', '0100'];
        yield 'iban check digits are not verified' => ['CZ00 0800 0000 1920 0014 5399', '19', '2000145399', '0800'];
    }

    #[DataProvider('provideRecognisedForms')]
    public function testRecognisedFormsAreDecomposedIntoTheCanonicalDomesticParts(string $input, string $prefix, string $number, string $bankCode): void
    {
        $parsed = BankAccountNumber::tryParse($input);

        self::assertNotNull($parsed);
        self::assertSame($prefix, $parsed->prefix);
        self::assertSame($number, $parsed->number);
        self::assertSame($bankCode, $parsed->bankCode);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEquivalentForms(): iterable
    {
        yield 'zero padded and short domestic' => ['000019-0002808601/0100', '19-2808601/0100'];
        yield 'domestic with spaces and without' => ['19 - 2808601 / 0100', '19-2808601/0100'];
        yield 'iban and domestic' => ['CZ65 0800 0000 1920 0014 5399', '19-2000145399/0800'];
        yield 'zero prefix and missing prefix' => ['000000-2808601/0100', '2808601/0100'];
    }

    #[DataProvider('provideEquivalentForms')]
    public function testEquivalentFormsAreEqual(string $first, string $second): void
    {
        $a = BankAccountNumber::tryParse($first);
        $b = BankAccountNumber::tryParse($second);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertTrue($a->equals($b));
        self::assertTrue($b->equals($a));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDifferentAccounts(): iterable
    {
        yield 'different bank code' => ['19-2808601/0100', '19-2808601/0200'];
        yield 'different prefix' => ['19-2808601/0100', '27-2808601/0100'];
        yield 'prefix versus none' => ['19-2808601/0100', '2808601/0100'];
        yield 'different number' => ['19-2808601/0100', '19-2808602/0100'];
    }

    #[DataProvider('provideDifferentAccounts')]
    public function testDifferentAccountsAreNotEqual(string $first, string $second): void
    {
        $a = BankAccountNumber::tryParse($first);
        $b = BankAccountNumber::tryParse($second);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertFalse($a->equals($b));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnrecognisableInput(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'letters' => ['abc'];
        yield 'number without bank code' => ['19-2808601'];
        yield 'full stop instead of the separator' => ['19.2808601/0100'];
        yield 'zero-width space inside' => ["19-2808601/01\u{200B}00"];
        yield 'bank code too short' => ['19-2808601/01'];
        yield 'bank code too long' => ['19-2808601/01000'];
        yield 'prefix longer than six digits' => ['1234567-2808601/0100'];
        yield 'number longer than ten digits' => ['12345678901/0100'];
        yield 'missing number' => ['/0100'];
        yield 'iban too short' => ['CZ65 0800 0000 1920 0014 539'];
        yield 'iban too long' => ['CZ65 0800 0000 1920 0014 5399 0'];
        yield 'foreign iban' => ['DE89 3704 0044 0532 0130 00'];
        yield 'non-digit in czech iban' => ['CZ65 0800 0000 19X0 0014 5399'];
    }

    #[DataProvider('provideUnrecognisableInput')]
    public function testUnrecognisableInputYieldsNullInsteadOfThrowing(string $input): void
    {
        self::assertNull(BankAccountNumber::tryParse($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLiteralForms(): iterable
    {
        yield 'iban with spaces' => ['de89 3704 0044 0532 0130 00', 'DE89370400440532013000'];
        yield 'already canonical' => ['XY1234', 'XY1234'];
        yield 'unicode spaces and dash variants' => ["xy\u{202F}12\u{2013}34", 'XY12-34'];
        yield 'mixed case with tabs' => ["ab-12\t34", 'AB-1234'];
    }

    #[DataProvider('provideLiteralForms')]
    public function testLiteralRemovesWhitespaceAndUpperCases(string $input, string $expected): void
    {
        self::assertSame($expected, BankAccountNumber::literal($input));
    }
}
