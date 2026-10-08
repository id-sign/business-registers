<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Isir\Internal;

use IdSign\BusinessRegisters\Address;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\StreetLine;
use IdSign\BusinessRegisters\Internal\XmlReader;
use IdSign\BusinessRegisters\Isir\InsolvencyProceeding;
use IdSign\BusinessRegisters\Isir\InsolvencyProceedings;
use IdSign\BusinessRegisters\Source;

/**
 * Parses SOAP responses of the ISIR IsirWsCuzkService.
 *
 * @internal
 */
final class ResponseParser
{
    /**
     * Distinct proceedings of a complete answer. The service caps distinct proceedings at maxPocetVysledku (this plus
     * one) and returns every debtor row of each, so more than this many distinct references means a cut list.
     */
    public const int MAX_PROCEEDINGS = 100;

    private const array NAMESPACES = [
        's' => 'http://schemas.xmlsoap.org/soap/envelope/',
        'ns2' => 'http://isirws.cca.cz/types/',
    ];

    private const string EMPTY_RESULT = 'WS2';

    private function __construct()
    {
    }

    /**
     * Proceedings of a getIsirWsCuzkDataResponse in response order.
     *
     * @throws InvalidResponse
     * @throws ServiceUnavailable
     */
    public static function parseProceedings(string $xml): InsolvencyProceedings
    {
        $body = XmlReader::fromString($xml, Source::Isir, self::NAMESPACES)->element('s:Body');

        $fault = $body->optionalElement('s:Fault');
        if (null !== $fault) {
            throw new ServiceUnavailable('ISIR returned a SOAP Fault', Source::Isir, $fault->optionalString('faultcode'));
        }

        $response = $body->element('ns2:getIsirWsCuzkDataResponse');
        $status = $response->element('stav');

        $code = $status->optionalString('kodChyby');
        if (self::EMPTY_RESULT === $code) {
            return new InsolvencyProceedings([], self::synchronisedAt($status));
        }
        if (null !== $code) {
            throw match ($code) {
                'WS4' => new ServiceUnavailable('ISIR data are not current', Source::Isir, $code),
                'SQL1' => new ServiceUnavailable('ISIR database error', Source::Isir, $code),
                'SERVER1' => new ServiceUnavailable('ISIR application error', Source::Isir, $code),
                default => $status->invalid('kodChyby', 'no error code, WS2, WS4, SQL1 or SERVER1'),
            };
        }

        $rows = $response->elements('data');
        if ($status->int('pocetVysledku') > \count($rows)) {
            throw $status->invalid('pocetVysledku', 'a count not above the number of data elements');
        }

        $proceedings = array_map(self::proceeding(...), $rows);
        // a case reference is unique per court only
        $references = array_unique(array_map(static fn (InsolvencyProceeding $p): string => $p->court.'|'.$p->reference(), $proceedings));
        if (\count($references) > self::MAX_PROCEEDINGS) {
            throw $response->invalid('data', 'at most '.self::MAX_PROCEEDINGS.' distinct proceedings');
        }

        return new InsolvencyProceedings($proceedings, self::synchronisedAt($status));
    }

    /**
     * The freshness hint casSynchronizace, read leniently: it never decides a verdict, so an unreadable value is null.
     */
    private static function synchronisedAt(XmlReader $status): ?\DateTimeImmutable
    {
        try {
            return $status->optionalDateTimePrague('casSynchronizace');
        } catch (InvalidResponse) {
            return null;
        }
    }

    /**
     * The `faultcode` of a SOAP Fault body, or null when the body is not a readable Fault carrying one.
     */
    public static function faultCode(string $xml): ?string
    {
        try {
            return XmlReader::fromString($xml, Source::Isir, self::NAMESPACES)
                ->optionalElement('s:Body')
                ?->optionalElement('s:Fault')
                ?->optionalString('faultcode');
        } catch (InvalidResponse) {
            return null;
        }
    }

    /**
     * @throws InvalidResponse
     */
    private static function proceeding(XmlReader $row): InsolvencyProceeding
    {
        return new InsolvencyProceeding(
            companyId: self::companyId($row),
            birthNumber: $row->optionalString('rc'),
            senate: $row->int('cisloSenatu'),
            caseType: $row->string('druhVec'),
            caseNumber: $row->int('bcVec'),
            year: $row->int('rocnik'),
            court: $row->optionalString('nazevOrganizace'),
            bornOn: $row->optionalDate('datumNarozeni'),
            titleBefore: $row->optionalString('titulPred'),
            titleAfter: $row->optionalString('titulZa'),
            firstName: $row->optionalString('jmeno'),
            name: $row->optionalString('nazevOsoby'),
            addressKind: $row->optionalString('druhAdresy'),
            address: self::address($row),
            stateCode: $row->optionalString('druhStavKonkursu'),
            detailUrl: $row->optionalString('urlDetailRizeni'),
            otherDebtorInProceeding: self::flag($row, 'dalsiDluznikVRizeni'),
            insolvencyDeclaredOn: $row->optionalDate('datumPmZahajeniUpadku'),
            endedOn: $row->optionalDate('datumPmUkonceniUpadku'),
        );
    }

    /**
     * @throws InvalidResponse
     */
    private static function companyId(XmlReader $row): ?CompanyId
    {
        $ic = $row->optionalString('ic');
        if (null === $ic) {
            return null;
        }

        try {
            return CompanyId::fromRegister($ic);
        } catch (InvalidInput) {
            throw $row->invalid('ic', 'company id');
        }
    }

    private static function address(XmlReader $row): ?Address
    {
        $streetName = $row->optionalString('ulice');
        $numbers = $row->optionalString('cisloPopisne');
        $city = $row->optionalString('mesto');
        $postalCode = self::withoutWhitespace($row->optionalString('psc'));
        $county = $row->optionalString('okres');
        $countryName = $row->optionalString('zeme');

        if (null === $streetName && null === $numbers && null === $city && null === $postalCode && null === $county && null === $countryName) {
            return null;
        }

        $parts = null === $numbers ? null : StreetLine::splitNumbers($numbers);
        // numbers of an unknown shape stay in the street line only, never in the split fields
        $street = null === $parts
            ? (null === $numbers ? $streetName : trim(($streetName ?? '').' '.$numbers))
            : StreetLine::compose($streetName, $parts['houseNumber'], $parts['orientationNumber']) ?? $numbers;

        return new Address(
            street: $street,
            streetName: $streetName,
            houseNumber: $parts['houseNumber'] ?? null,
            houseNumberType: $parts['houseNumberType'] ?? null,
            orientationNumber: $parts['orientationNumber'] ?? null,
            city: $city,
            postalCode: $postalCode,
            county: $county,
            countryName: $countryName,
        );
    }

    private static function withoutWhitespace(?string $value): ?string
    {
        return null === $value ? null : preg_replace('/\s+/u', '', $value);
    }

    /**
     * @throws InvalidResponse
     */
    private static function flag(XmlReader $row, string $name): bool
    {
        return match ($row->optionalString($name)) {
            'T' => true,
            'F', null => false,
            default => throw $row->invalid($name, 'T or F'),
        };
    }
}
