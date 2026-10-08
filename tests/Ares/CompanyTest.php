<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Ares;

use IdSign\BusinessRegisters\Ares\AresRegister;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\Registrations;
use IdSign\BusinessRegisters\Ares\RegistrationStatus;
use IdSign\BusinessRegisters\VatId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Company::class)]
final class CompanyTest extends TestCase
{
    private static function company(?string $legalFormCode = null, ?VatId $vatId = null, ?VatId $groupVatId = null, ?\DateTimeImmutable $ceasedOn = null): Company
    {
        $statuses = [];
        foreach (AresRegister::cases() as $register) {
            $statuses[$register->value] = RegistrationStatus::Nonexistent;
        }

        return new Company(
            aresId: '45274649',
            id: null,
            name: 'Test, a.s.',
            legalFormCode: $legalFormCode,
            vatId: $vatId,
            groupVatId: $groupVatId,
            taxOfficeCode: null,
            seat: null,
            deliveryAddressLines: [],
            establishedOn: null,
            ceasedOn: $ceasedOn,
            updatedOn: null,
            naceCodes: [],
            naceCodes2008: [],
            fileNumber: null,
            primarySource: null,
            registrations: new Registrations($statuses),
        );
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function provideLegalForms(): iterable
    {
        yield '101 lower bound' => ['101', true];
        yield '105 inside' => ['105', true];
        yield '108 upper bound' => ['108', true];
        yield '100 domestic self-employed natural person' => ['100', true];
        yield '109 above' => ['109', false];
        yield '111 general partnership' => ['111', false];
        yield '112 limited company' => ['112', false];
        yield '121 joint-stock company' => ['121', false];
        yield '421 branch of a foreign legal person' => ['421', false];
        yield '424 foreign natural person' => ['424', true];
        yield '425 branch of a foreign natural person' => ['425', true];
        yield 'unknown legal form' => [null, false];
    }

    #[DataProvider('provideLegalForms')]
    public function testNaturalPersonIsDecidedByTheLegalFormCode(?string $code, bool $expected): void
    {
        self::assertSame($expected, self::company($code)->isNaturalPerson());
    }

    public function testVatLookupIdIsTheOwnVatIdWhenThereIsNoGroupId(): void
    {
        $own = VatId::parse('CZ45274649');

        self::assertSame($own, self::company(vatId: $own)->vatLookupId());
    }

    public function testVatLookupIdPrefersTheGroupVatId(): void
    {
        $own = VatId::parse('CZ45274649');
        $group = VatId::parse('CZ699001182');

        self::assertSame($group, self::company(vatId: $own, groupVatId: $group)->vatLookupId());
    }

    public function testVatLookupIdIsNullWithoutAnyVatId(): void
    {
        self::assertNull(self::company()->vatLookupId());
    }

    private static function prague(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Prague'));
    }

    /**
     * @return iterable<string, array{?string, string, bool}> ceasedOn, reference date, expected
     */
    public static function provideEndDates(): iterable
    {
        yield 'no end date' => [null, '2035-12-10', false];
        yield 'ceased the day before' => ['2035-12-09', '2035-12-10', true];
        yield 'ceased on the reference day' => ['2035-12-10', '2035-12-10', true];
        yield 'ceased on the reference day, reference with a time' => ['2035-12-10', '2035-12-10 15:00', true];
        yield 'end date the day after' => ['2035-12-11', '2035-12-10', false];
    }

    #[DataProvider('provideEndDates')]
    public function testSubjectHasCeasedWhenTheEndDateIsNotAfterTheReferenceDate(?string $ceasedOn, string $on, bool $expected): void
    {
        $company = self::company(ceasedOn: null === $ceasedOn ? null : self::prague($ceasedOn));

        self::assertSame($expected, $company->hasCeased(self::prague($on)));
    }

    /**
     * @return iterable<string, array{string, bool}> time zone of the reference date, expected
     */
    public static function provideReferenceZones(): iterable
    {
        yield 'UTC' => ['UTC', true];
        yield 'Asia/Tokyo, ahead of Prague' => ['Asia/Tokyo', true];
        yield 'Pacific/Kiritimati, furthest ahead' => ['Pacific/Kiritimati', true];
        yield 'America/Los_Angeles, behind Prague' => ['America/Los_Angeles', true];
    }

    #[DataProvider('provideReferenceZones')]
    public function testReferenceDateIsACalendarDayWhateverItsTimeZone(string $zone, bool $expected): void
    {
        $company = self::company(ceasedOn: self::prague('2035-12-10'));
        $on = new \DateTimeImmutable('2035-12-10', new \DateTimeZone($zone));

        self::assertSame($expected, $company->hasCeased($on));
    }

    /**
     * @return iterable<string, array{string, string, bool}> time zone of the end date, reference day, expected
     */
    public static function provideEndDateZones(): iterable
    {
        yield 'UTC, behind Prague' => ['UTC', '2035-12-10', true];
        yield 'America/Los_Angeles, far behind Prague' => ['America/Los_Angeles', '2035-12-10', true];
        yield 'Asia/Tokyo, ahead of Prague' => ['Asia/Tokyo', '2035-12-10', true];
        yield 'Asia/Tokyo, the day before in Prague' => ['Asia/Tokyo', '2035-12-09', false];
    }

    #[DataProvider('provideEndDateZones')]
    public function testEndDateIsACalendarDayWhateverItsTimeZone(string $zone, string $on, bool $expected): void
    {
        $company = self::company(ceasedOn: new \DateTimeImmutable('2035-12-10', new \DateTimeZone($zone)));

        self::assertSame($expected, $company->hasCeased(self::prague($on)));
    }

    // The two tests below read the real clock; they flip in December 2035 on purpose.
    public function testSubjectWithAPastEndDateHasCeasedToday(): void
    {
        self::assertTrue(self::company(ceasedOn: self::prague('2020-01-31'))->hasCeased());
    }

    public function testSubjectWithAFutureEndDateHasNotCeasedToday(): void
    {
        self::assertFalse(self::company(ceasedOn: self::prague('2035-12-10'))->hasCeased());
    }
}
