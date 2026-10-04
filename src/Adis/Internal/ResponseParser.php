<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Adis\Internal;

use IdSign\BusinessRegisters\Address;
use IdSign\BusinessRegisters\Adis\BankAccount;
use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\UnreliablePayer;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\XmlReader;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\VatId;

/**
 * Parses SOAP responses of the ADIS rozhraniCRPDPH service.
 *
 * @internal
 */
final class ResponseParser
{
    private const array NAMESPACES = [
        's' => 'http://schemas.xmlsoap.org/soap/envelope/',
        'r' => 'http://adis.mfcr.cz/rozhraniCRPDPH/',
    ];

    private const string NOT_FOUND = 'NENALEZEN';

    private function __construct()
    {
    }

    /**
     * Subjects of a StatusNespolehlivySubjektRozsirenyResponse in response order, without the not-found ones.
     *
     * @return list<VatSubject>
     *
     * @throws InvalidResponse
     * @throws ServiceUnavailable
     */
    public static function parseSubjects(string $xml): array
    {
        [$response, $checkedAt] = self::open($xml, 'StatusNespolehlivySubjektRozsirenyResponse');

        $subjects = [];
        foreach ($response->elements('r:statusSubjektu') as $element) {
            $typeValue = $element->attribute('typSubjektu');
            if (self::NOT_FOUND === $typeValue) {
                continue;
            }
            $type = SubjectType::tryFrom($typeValue) ?? throw $element->invalid('@typSubjektu', 'known subject type');

            $subjects[] = new VatSubject(
                vatId: self::vatId($element),
                type: $type,
                unreliable: 'ANO' === $element->attribute('nespolehlivyPlatce'),
                unreliableSince: $element->optionalDateAttribute('datumZverejneniNespolehlivosti'),
                taxOfficeCode: self::taxOfficeCode($element),
                name: $element->optionalString('r:nazevSubjektu'),
                address: self::address($element->optionalElement('r:adresa')),
                bankAccounts: self::bankAccounts($element),
                checkedAt: $checkedAt,
            );
        }

        return $subjects;
    }

    /**
     * Entries of a SeznamNespolehlivyPlatceResponse in response order.
     *
     * @return list<UnreliablePayer>
     *
     * @throws InvalidResponse
     * @throws ServiceUnavailable
     */
    public static function parseUnreliablePayers(string $xml): array
    {
        [$response] = self::open($xml, 'SeznamNespolehlivyPlatceResponse');

        return array_map(
            static fn (XmlReader $entry): UnreliablePayer => new UnreliablePayer(
                vatId: self::vatId($entry),
                since: $entry->optionalDateAttribute('datumZverejneniNespolehlivosti'),
                taxOfficeCode: self::taxOfficeCode($entry),
            ),
            $response->elements('r:statusPlatceDPH'),
        );
    }

    /**
     * Checks the envelope and the status, returns the response element and the generation date.
     *
     * @return array{XmlReader, \DateTimeImmutable}
     *
     * @throws InvalidResponse
     * @throws ServiceUnavailable
     */
    private static function open(string $xml, string $responseElement): array
    {
        $body = XmlReader::fromString($xml, Source::Adis, self::NAMESPACES)->element('s:Body');

        $fault = $body->optionalElement('s:Fault');
        if (null !== $fault) {
            throw new ServiceUnavailable('ADIS returned a SOAP Fault', Source::Adis, $fault->optionalString('faultcode'));
        }

        $response = $body->element('r:'.$responseElement);
        $status = $response->element('r:status');

        $code = $status->attribute('statusCode');
        match ($code) {
            '0' => null,
            '1' => throw $status->invalid('@statusCode', 'status code 0'),
            '2' => throw new ServiceUnavailable('ADIS is in scheduled maintenance', Source::Adis, $code),
            '3' => throw new ServiceUnavailable('ADIS service is unavailable', Source::Adis, $code),
            default => throw $status->invalid('@statusCode', 'status code 0 to 3'),
        };

        return [$response, $status->dateAttribute('odpovedGenerovana')];
    }

    /**
     * @throws InvalidResponse
     */
    private static function vatId(XmlReader $element): VatId
    {
        $dic = $element->attribute('dic');

        try {
            return VatId::parse($dic, 'CZ');
        } catch (InvalidInput) {
            throw $element->invalid('@dic', 'VAT id');
        }
    }

    private static function taxOfficeCode(XmlReader $element): ?string
    {
        $code = $element->optionalAttribute('cisloFu');

        return null === $code ? null : str_pad($code, 3, '0', \STR_PAD_LEFT);
    }

    private static function address(?XmlReader $address): ?Address
    {
        if (null === $address) {
            return null;
        }

        return new Address(
            street: $address->optionalString('r:uliceCislo'),
            district: $address->optionalString('r:castObce'),
            city: $address->optionalString('r:mesto'),
            postalCode: $address->optionalString('r:psc'),
            countryName: $address->optionalString('r:stat'),
        );
    }

    /**
     * @return list<BankAccount>
     *
     * @throws InvalidResponse
     */
    private static function bankAccounts(XmlReader $subject): array
    {
        $accounts = [];
        foreach ($subject->optionalElement('r:zverejneneUcty')?->elements('r:ucet') ?? [] as $account) {
            $publishedFrom = $account->dateAttribute('datumZverejneni');
            $publishedUntil = $account->optionalDateAttribute('datumZverejneniUkonceni');

            $standard = $account->optionalElement('r:standardniUcet');
            $accounts[] = null === $standard
                ? new BankAccount(
                    prefix: null,
                    number: $account->element('r:nestandardniUcet')->attribute('cislo'),
                    bankCode: null,
                    publishedFrom: $publishedFrom,
                    publishedUntil: $publishedUntil,
                )
                : new BankAccount(
                    prefix: $standard->optionalAttribute('predcisli'),
                    number: $standard->attribute('cislo'),
                    bankCode: $standard->attribute('kodBanky'),
                    publishedFrom: $publishedFrom,
                    publishedUntil: $publishedUntil,
                );
        }

        return $accounts;
    }
}
