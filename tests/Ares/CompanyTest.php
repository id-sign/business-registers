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
    private static function company(?string $legalFormCode = null, ?VatId $vatId = null, ?VatId $groupVatId = null): Company
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
            dissolvedOn: null,
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
        yield '100 below' => ['100', false];
        yield '109 above' => ['109', false];
        yield '121 joint-stock company' => ['121', false];
        yield '112 limited company' => ['112', false];
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
}
