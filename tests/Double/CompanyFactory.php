<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Double;

use IdSign\BusinessRegisters\Address;
use IdSign\BusinessRegisters\Ares\AresRegister;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\Registrations;
use IdSign\BusinessRegisters\Ares\RegistrationStatus;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\VatId;

/**
 * Builds a complete Company for tests; every argument has a sane default.
 */
final class CompanyFactory
{
    /**
     * @param CompanyId|string|null             $id       null builds a subject without a company id
     * @param ?string                           $aresId   defaults to the company id, or ARES_00369838 without one
     * @param array<string, RegistrationStatus> $statuses overrides keyed by AresRegister::value; the rest is Nonexistent
     */
    public static function create(
        CompanyId|string|null $id = '45274649',
        string $name = 'Test s.r.o.',
        ?VatId $vatId = null,
        ?VatId $groupVatId = null,
        ?\DateTimeImmutable $dissolvedOn = null,
        array $statuses = [],
        ?string $legalFormCode = '121',
        ?string $aresId = null,
        ?Address $seat = null,
    ): Company {
        $companyId = \is_string($id) ? CompanyId::parse($id) : $id;

        $registrations = [];
        foreach (AresRegister::cases() as $register) {
            $registrations[$register->value] = $statuses[$register->value] ?? RegistrationStatus::Nonexistent;
        }

        return new Company(
            aresId: $aresId ?? ($companyId instanceof CompanyId ? $companyId->value : 'ARES_00369838'),
            id: $companyId,
            name: $name,
            legalFormCode: $legalFormCode,
            vatId: $vatId,
            groupVatId: $groupVatId,
            taxOfficeCode: null,
            seat: $seat,
            deliveryAddressLines: [],
            establishedOn: null,
            dissolvedOn: $dissolvedOn,
            updatedOn: null,
            naceCodes: [],
            naceCodes2008: [],
            fileNumber: null,
            primarySource: null,
            registrations: new Registrations($registrations),
        );
    }
}
