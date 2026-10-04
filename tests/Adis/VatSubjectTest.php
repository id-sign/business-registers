<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Adis;

use IdSign\BusinessRegisters\Adis\BankAccount;
use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\VatId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VatSubject::class)]
#[CoversClass(SubjectType::class)]
final class VatSubjectTest extends TestCase
{
    private static function standard(?string $prefix, string $number, string $bankCode, ?\DateTimeImmutable $until = null): BankAccount
    {
        return new BankAccount(
            prefix: $prefix,
            number: $number,
            bankCode: $bankCode,
            publishedFrom: new \DateTimeImmutable('2013-04-01'),
            publishedUntil: $until,
        );
    }

    private static function nonStandard(string $number, ?\DateTimeImmutable $until = null): BankAccount
    {
        return new BankAccount(
            prefix: null,
            number: $number,
            bankCode: null,
            publishedFrom: new \DateTimeImmutable('2013-04-01'),
            publishedUntil: $until,
        );
    }

    /**
     * @param list<BankAccount> $accounts
     */
    private static function subject(array $accounts = [], SubjectType $type = SubjectType::VatPayer): VatSubject
    {
        return new VatSubject(
            vatId: VatId::parse('CZ45274649'),
            type: $type,
            unreliable: false,
            unreliableSince: null,
            taxOfficeCode: '013',
            name: 'Test a.s.',
            address: null,
            bankAccounts: $accounts,
            checkedAt: new \DateTimeImmutable('2026-10-03'),
        );
    }

    private static function subjectWithPublishedAccounts(): VatSubject
    {
        return self::subject([
            self::standard('19', '2808601', '0100'),
            self::standard(null, '2001260209', '2600'),
            self::nonStandard('CZ6426000000002001268200'),
            self::nonStandard('DE89370400440532013000'),
            self::nonStandard('XY 12-34'),
            self::standard('27', '5868650297', '0100', new \DateTimeImmutable('2020-12-31')),
            self::nonStandard('CZ2326000000002001260305', new \DateTimeImmutable('2021-01-01')),
        ]);
    }

    /**
     * @return iterable<string, array{string, SubjectType}>
     */
    public static function provideSubjectTypeValues(): iterable
    {
        yield 'vat payer' => ['PLATCE_DPH', SubjectType::VatPayer];
        yield 'identified person' => ['IDENTIFIKOVANA_OSOBA', SubjectType::IdentifiedPerson];
        yield 'vat group' => ['SKUPINA_DPH', SubjectType::VatGroup];
        yield 'unreliable person' => ['NESPOLEHLIVA_OSOBA', SubjectType::UnreliablePerson];
    }

    #[DataProvider('provideSubjectTypeValues')]
    public function testSubjectTypeIsBackedByTheRegisterValue(string $value, SubjectType $expected): void
    {
        self::assertSame($expected, SubjectType::tryFrom($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValuesThatAreNotSubjectTypes(): iterable
    {
        yield 'not found marker' => ['NENALEZEN'];
        yield 'unknown' => ['JINY_TYP'];
    }

    #[DataProvider('provideValuesThatAreNotSubjectTypes')]
    public function testNotFoundMarkerAndUnknownValuesAreNotSubjectTypes(string $value): void
    {
        self::assertNull(SubjectType::tryFrom($value));
    }

    /**
     * @return iterable<string, array{SubjectType, bool}>
     */
    public static function provideVatPayerStatusByType(): iterable
    {
        yield 'vat payer' => [SubjectType::VatPayer, true];
        yield 'vat group' => [SubjectType::VatGroup, true];
        yield 'identified person' => [SubjectType::IdentifiedPerson, false];
        yield 'unreliable person' => [SubjectType::UnreliablePerson, false];
    }

    #[DataProvider('provideVatPayerStatusByType')]
    public function testOnlyVatPayersAndVatGroupsAreVatPayers(SubjectType $type, bool $expected): void
    {
        self::assertSame($expected, self::subject([], $type)->isVatPayer());
    }

    public function testActiveBankAccountsExcludeEndedOnesAndKeepOrder(): void
    {
        $subject = self::subjectWithPublishedAccounts();

        $active = array_map(static fn (BankAccount $account): string => (string) $account, $subject->activeBankAccounts());

        self::assertSame([
            '19-2808601/0100',
            '2001260209/2600',
            'CZ6426000000002001268200',
            'DE89370400440532013000',
            'XY 12-34',
        ], $active);
    }

    public function testSubjectWithoutAccountsHasNoActiveBankAccounts(): void
    {
        self::assertSame([], self::subject()->activeBankAccounts());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePublishedAccountForms(): iterable
    {
        yield 'domestic with prefix' => ['19-2808601/0100'];
        yield 'domestic with zero padding' => ['000019-0002808601/0100'];
        yield 'domestic with spaces' => ['19 - 2808601 / 0100'];
        yield 'domestic without prefix' => ['2001260209/2600'];
        yield 'domestic with narrow no-break spaces' => ["19-2808601\u{202F}/\u{202F}0100"];
        yield 'domestic with no-break spaces' => ["19-2808601\u{00A0}/\u{00A0}0100"];
        yield 'domestic with en dash' => ["19\u{2013}2808601/0100"];
        yield 'domestic with minus sign' => ["19\u{2212}2808601/0100"];
        yield 'free-form account with dash variant' => ["xy12\u{2014}34"];
        yield 'domestic with zero prefix' => ['0000-2001260209/2600'];
        yield 'czech iban of a standard account' => ['CZ00 0100 0000 1900 0280 8601'];
        yield 'czech iban stored as non-standard account' => ['CZ64 2600 0000 0020 0126 8200'];
        yield 'czech iban in lower case' => ['cz64 2600 0000 0020 0126 8200'];
        yield 'domestic form of a czech iban stored as non-standard account' => ['2001268200/2600'];
        yield 'foreign account literally' => ['DE89370400440532013000'];
        yield 'foreign account with spaces and lower case' => ['de89 3704 0044 0532 0130 00'];
        yield 'free-form account literally' => ['xy12-34'];
    }

    #[DataProvider('providePublishedAccountForms')]
    public function testPublishedAccountIsFoundInAnyCommonForm(string $account): void
    {
        self::assertTrue(self::subjectWithPublishedAccounts()->hasPublishedAccount($account));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAccountsThatAreNotPublished(): iterable
    {
        yield 'ended standard account' => ['27-5868650297/0100'];
        yield 'ended non-standard account' => ['CZ23 2600 0000 0020 0126 0305'];
        yield 'other bank code' => ['19-2808601/0200'];
        yield 'other prefix' => ['20-2808601/0100'];
        yield 'missing prefix' => ['2808601/0100'];
        yield 'other foreign account' => ['DE89370400440532013001'];
        yield 'full stop instead of the separator' => ['19.2808601/0100'];
        yield 'zero-width space inside' => ["19-2808601/01\u{200B}00"];
        yield 'empty' => [''];
        yield 'nonsense' => ['not an account'];
        yield 'number without bank code' => ['19-2808601'];
        yield 'too long number' => ['12345678901234/0100'];
    }

    #[DataProvider('provideAccountsThatAreNotPublished')]
    public function testUnpublishedOrUnrecognisableAccountIsReportedAsNotPublishedWithoutThrowing(string $account): void
    {
        self::assertFalse(self::subjectWithPublishedAccounts()->hasPublishedAccount($account));
    }

    public function testSubjectWithoutAccountsHasNoPublishedAccount(): void
    {
        self::assertFalse(self::subject()->hasPublishedAccount('19-2808601/0100'));
    }

    public function testRegistryAccountWithUnicodeSpaceOrDashIsComparedNormalised(): void
    {
        $subject = self::subject([self::nonStandard("XY\u{202F}12\u{2013}34")]);

        self::assertTrue($subject->hasPublishedAccount('xy12-34'));
        self::assertTrue($subject->hasPublishedAccount("XY 12\u{2212}34"));
    }
}
