<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares\Internal;

use IdSign\BusinessRegisters\Address;
use IdSign\BusinessRegisters\Ares\AresRegister;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\Registrations;
use IdSign\BusinessRegisters\Ares\RegistrationStatus;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Internal\JsonReader;
use IdSign\BusinessRegisters\VatId;

/**
 * Maps an ARES EkonomickySubjekt object to Company.
 *
 * @internal
 */
final class CompanyMapper
{
    private const string PUBLIC_REGISTER_SOURCE = 'vr';

    private function __construct()
    {
    }

    /**
     * @throws InvalidResponse
     */
    public static function map(JsonReader $subject): Company
    {
        $seat = $subject->optionalObject('sidlo');

        return new Company(
            aresId: $subject->string('icoId'),
            id: self::companyId($subject),
            name: $subject->string('obchodniJmeno'),
            legalFormCode: $subject->optionalString('pravniForma'),
            vatId: self::vatId($subject, 'dic'),
            groupVatId: self::vatId($subject, 'dicSkDph'),
            taxOfficeCode: $subject->optionalString('financniUrad'),
            seat: null === $seat ? null : self::address($seat),
            deliveryAddressLines: self::deliveryAddressLines($subject->optionalObject('adresaDorucovaci')),
            establishedOn: $subject->optionalDate('datumVzniku'),
            dissolvedOn: $subject->optionalDate('datumZaniku'),
            updatedOn: $subject->optionalDate('datumAktualizace'),
            naceCodes: $subject->optionalStringList('czNace'),
            naceCodes2008: $subject->optionalStringList('czNace2008'),
            fileNumber: self::fileNumber($subject),
            primarySource: $subject->optionalString('primarniZdroj'),
            registrations: self::registrations($subject->optionalObject('seznamRegistraci')),
        );
    }

    /**
     * @throws InvalidResponse
     */
    private static function companyId(JsonReader $subject): ?CompanyId
    {
        $value = $subject->optionalString('ico');
        if (null === $value) {
            return null;
        }

        try {
            return CompanyId::parse($value);
        } catch (InvalidInput) {
            throw $subject->invalid('ico', 'company id');
        }
    }

    /**
     * @throws InvalidResponse
     */
    private static function vatId(JsonReader $subject, string $key): ?VatId
    {
        $value = $subject->optionalString($key);
        if (null === $value) {
            return null;
        }

        try {
            return VatId::parse($value, 'CZ');
        } catch (InvalidInput) {
            throw $subject->invalid($key, 'VAT id');
        }
    }

    /**
     * @throws InvalidResponse
     */
    private static function address(JsonReader $address): Address
    {
        $streetName = $address->optionalString('nazevUlice');
        $district = $address->optionalString('nazevCastiObce');
        $city = $address->optionalString('nazevObce');
        $houseNumber = $address->optionalString('cisloDomovni');
        $orientationNumber = $address->optionalString('cisloOrientacni');
        if (null !== $orientationNumber) {
            $orientationNumber .= $address->optionalString('cisloOrientacniPismeno') ?? '';
        }

        return new Address(
            text: $address->optionalString('textovaAdresa'),
            street: self::street($streetName ?? $district ?? $city, $houseNumber, $orientationNumber),
            streetName: $streetName,
            houseNumber: $houseNumber,
            houseNumberType: $address->optionalInt('typCisloDomovni'),
            orientationNumber: $orientationNumber,
            district: $district,
            cityDistrict: $address->optionalString('nazevMestskeCastiObvodu'),
            city: $city,
            postalCode: $address->optionalString('psc') ?? $address->optionalString('pscTxt'),
            county: $address->optionalString('nazevOkresu'),
            region: $address->optionalString('nazevKraje'),
            countryCode: $address->optionalString('kodStatu'),
            countryName: $address->optionalString('nazevStatu'),
            addressPointId: $address->optionalInt('kodAdresnihoMista'),
            municipalityCode: $address->optionalInt('kodObce'),
        );
    }

    /**
     * "Duhová 1444/2"; the name alone without numbers; null without a name.
     */
    private static function street(?string $name, ?string $houseNumber, ?string $orientationNumber): ?string
    {
        if (null === $name) {
            return null;
        }

        $numbers = implode('/', array_filter([$houseNumber, $orientationNumber], static fn (?string $n): bool => null !== $n));

        return '' === $numbers ? $name : $name.' '.$numbers;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidResponse
     */
    private static function deliveryAddressLines(?JsonReader $address): array
    {
        if (null === $address) {
            return [];
        }

        $lines = [];
        foreach (['radekAdresy1', 'radekAdresy2', 'radekAdresy3'] as $key) {
            $line = $address->optionalString($key);
            if (null !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @throws InvalidResponse
     */
    private static function fileNumber(JsonReader $subject): ?string
    {
        foreach ($subject->optionalObjectList('dalsiUdaje') as $record) {
            if (self::PUBLIC_REGISTER_SOURCE === $record->optionalString('datovyZdroj')) {
                return $record->optionalString('spisovaZnacka');
            }
        }

        return null;
    }

    /**
     * Every register gets a status: a missing key is Nonexistent, an unknown value Unknown.
     *
     * @throws InvalidResponse
     */
    private static function registrations(?JsonReader $list): Registrations
    {
        $statuses = [];
        foreach (AresRegister::cases() as $register) {
            $value = $list?->optionalString('stavZdroje'.$register->value);
            $statuses[$register->value] = null === $value
                ? RegistrationStatus::Nonexistent
                : RegistrationStatus::tryFrom($value) ?? RegistrationStatus::Unknown;
        }

        return new Registrations($statuses);
    }
}
