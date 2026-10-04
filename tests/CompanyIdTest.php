<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\ExceptionInterface;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompanyId::class)]
final class CompanyIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideValidInputs(): iterable
    {
        yield 'plain eight digits' => ['45274649', '45274649'];
        yield 'with spaces' => ['452 746 49', '45274649'];
        yield 'padded with leading and trailing whitespace' => ["  45274649\t\n", '45274649'];
        yield 'short input is left-padded' => ['64581', '00064581'];
        yield 'already padded' => ['00064581', '00064581'];
        yield 'another valid id' => ['25083325', '25083325'];
        yield 'leading zero' => ['03024130', '03024130'];
        yield 'bank' => ['45317054', '45317054'];
    }

    #[DataProvider('provideValidInputs')]
    public function testValidInputIsNormalisedToEightDigits(string $input, string $expected): void
    {
        self::assertSame($expected, CompanyId::parse($input)->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidInputs(): iterable
    {
        yield 'wrong checksum' => ['12345678'];
        yield 'all zeros' => ['00000000'];
        yield 'letters' => ['abc'];
        yield 'empty' => [''];
        yield 'only whitespace' => ['   '];
        yield 'nine digits' => ['123456789'];
        yield 'nine digits with leading zero' => ['045274649'];
        yield 'digits with a letter' => ['4527464a'];
        yield 'negative sign' => ['-4527464'];
    }

    #[DataProvider('provideInvalidInputs')]
    public function testInvalidInputIsRejectedWithInvalidInput(string $input): void
    {
        $this->expectException(InvalidInput::class);

        CompanyId::parse($input);
    }

    public function testRejectionIsCatchableThroughTheLibraryMarkerInterface(): void
    {
        $this->expectException(ExceptionInterface::class);

        CompanyId::parse('12345678');
    }

    #[DataProvider('provideInvalidInputs')]
    public function testTryParseReturnsNullForInvalidInput(string $input): void
    {
        self::assertNull(CompanyId::tryParse($input));
    }

    #[DataProvider('provideValidInputs')]
    public function testTryParseReturnsTheSameValueAsParseForValidInput(string $input, string $expected): void
    {
        $id = CompanyId::tryParse($input);

        self::assertNotNull($id);
        self::assertSame($expected, $id->value);
    }

    public function testIdsWithTheSameValueAreEqualRegardlessOfInputForm(): void
    {
        self::assertTrue(CompanyId::parse('64581')->equals(CompanyId::parse('00064581')));
    }

    public function testIdsWithDifferentValuesAreNotEqual(): void
    {
        self::assertFalse(CompanyId::parse('45274649')->equals(CompanyId::parse('45317054')));
    }

    public function testStringRepresentationIsTheEightDigitValue(): void
    {
        self::assertSame('00064581', (string) CompanyId::parse('64581'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRegisterInputs(): iterable
    {
        yield 'valid id' => ['45274649', '45274649'];
        yield 'with spaces' => ['452 746 49', '45274649'];
        yield 'padded with leading and trailing whitespace' => ["  45274649\t\n", '45274649'];
        yield 'short input is left-padded' => ['64581', '00064581'];
        yield 'active subject with a wrong check digit' => ['00123562', '00123562'];
        yield 'short active subject with a wrong check digit' => ['123562', '00123562'];
        yield 'another active subject with a wrong check digit' => ['29340042', '29340042'];
        yield 'all zeros' => ['00000000', '00000000'];
    }

    #[DataProvider('provideRegisterInputs')]
    public function testFromRegisterNormalisesToEightDigitsWithoutCheckingTheCheckDigit(string $input, string $expected): void
    {
        self::assertSame($expected, CompanyId::fromRegister($input)->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedRegisterInputs(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'empty' => [''];
        yield 'only whitespace' => ['   '];
        yield 'nine digits' => ['123456789'];
        yield 'nine digits with leading zero' => ['045274649'];
        yield 'digits with a letter' => ['4527464a'];
        yield 'negative sign' => ['-4527464'];
    }

    #[DataProvider('provideMalformedRegisterInputs')]
    public function testFromRegisterRejectsAMalformedValueWithInvalidInput(string $input): void
    {
        $this->expectException(InvalidInput::class);

        CompanyId::fromRegister($input);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideCheckDigitResults(): iterable
    {
        yield 'valid id' => ['45274649', true];
        yield 'valid id with leading zeros' => ['00064581', true];
        yield 'active subject with a wrong check digit' => ['00123562', false];
        yield 'another active subject with a wrong check digit' => ['29340042', false];
        yield 'all zeros' => ['00000000', false];
        yield 'wrong checksum' => ['12345678', false];
    }

    #[DataProvider('provideCheckDigitResults')]
    public function testHasValidCheckDigitReportsWhetherTheCheckDigitMatches(string $input, bool $expected): void
    {
        self::assertSame($expected, CompanyId::fromRegister($input)->hasValidCheckDigit());
    }

    public function testParsedIdAlwaysHasAValidCheckDigit(): void
    {
        self::assertTrue(CompanyId::parse('45274649')->hasValidCheckDigit());
    }

    public function testParseStaysStrictForAnIdThatFromRegisterAccepts(): void
    {
        $this->expectException(InvalidInput::class);

        CompanyId::parse('00123562');
    }

    public function testTryParseStaysStrictForAnIdThatFromRegisterAccepts(): void
    {
        self::assertNull(CompanyId::tryParse('29340042'));
    }

    public function testIdFromRegisterEqualsTheSameValueWhateverFormItWasGivenIn(): void
    {
        self::assertTrue(CompanyId::fromRegister('123562')->equals(CompanyId::fromRegister('00123562')));
        self::assertTrue(CompanyId::fromRegister('45274649')->equals(CompanyId::parse('45274649')));
    }

    public function testStringRepresentationOfAnIdFromRegisterIsTheEightDigitValue(): void
    {
        self::assertSame('00123562', (string) CompanyId::fromRegister('123562'));
    }

    public function testValueObjectCannotBeConstructedDirectly(): void
    {
        $constructor = (new \ReflectionClass(CompanyId::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }
}
