<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Vies;

use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\HttpTransport;
use IdSign\BusinessRegisters\Internal\JsonReader;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\VatId;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of the VIES REST API (check-vat-number).
 */
final readonly class ViesClient implements Vies
{
    public const string ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    private const array INPUT_ERROR_CODES = ['INVALID_INPUT', 'INVALID_REQUESTER_INFO'];
    private const string UNDISCLOSED = '---';

    private HttpTransport $transport;

    /**
     * @param float $timeout seconds, applied as both the idle timeout and the maximum duration
     */
    public function __construct(
        HttpClientInterface $httpClient,
        private string $endpoint = self::ENDPOINT,
        float $timeout = 10.0,
    ) {
        $this->transport = new HttpTransport($httpClient, Source::Vies, $timeout);
    }

    public function check(VatId|string $vatId, VatId|string|null $requester = null, ?TraderDetails $trader = null): ViesResult
    {
        $vatId = $vatId instanceof VatId ? $vatId : VatId::parse($vatId);
        $requester = \is_string($requester) ? VatId::parse($requester) : $requester;

        $body = ['countryCode' => $vatId->countryCode, 'vatNumber' => $vatId->number];
        if (null !== $requester) {
            $body['requesterMemberStateCode'] = $requester->countryCode;
            $body['requesterNumber'] = $requester->number;
        }
        if (null !== $trader) {
            $body += array_filter([
                'traderName' => $trader->name,
                'traderStreet' => $trader->street,
                'traderPostalCode' => $trader->postalCode,
                'traderCity' => $trader->city,
                'traderCompanyType' => $trader->companyType,
                // preg_match() returns false on invalid UTF-8, so such a value is kept and fails JSON encoding below.
            ], static fn (?string $value): bool => null !== $value && 1 !== preg_match('/^[\s\p{Z}]*$/u', $value));
        }

        try {
            $json = json_encode($body, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidInput(\sprintf('VIES request for VAT id %s cannot be encoded as JSON (invalid UTF-8)', $vatId), previous: $e);
        }

        [$status, $content] = $this->transport->exchange(
            'POST',
            $this->endpoint,
            [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => $json,
            ],
            'VAT id '.$vatId,
        );

        if (400 === $status) {
            throw new InvalidInput(\sprintf('VIES rejected the request for VAT id %s', $vatId), self::errorCode($content));
        }
        if (200 !== $status) {
            throw new ServiceUnavailable(\sprintf('VIES returned HTTP %d for VAT id %s', $status, $vatId), Source::Vies, self::errorCode($content));
        }

        $reader = JsonReader::fromJson($content, Source::Vies);
        if (false === $reader->optionalBool('actionSucceed') || [] !== $reader->optionalObjectList('errorWrappers')) {
            throw self::failure($reader, $vatId);
        }

        return self::result($reader);
    }

    /**
     * @throws InvalidResponse
     */
    private static function failure(JsonReader $reader, VatId $vatId): InvalidInput|ServiceUnavailable
    {
        $first = $reader->objectList('errorWrappers')[0] ?? throw $reader->invalid('errorWrappers', 'non-empty list of errors');
        $code = $first->string('error');

        if (\in_array($code, self::INPUT_ERROR_CODES, true)) {
            return new InvalidInput(\sprintf('VIES rejected the request for VAT id %s', $vatId), $code);
        }

        return new ServiceUnavailable(\sprintf('VIES could not check VAT id %s', $vatId), Source::Vies, $code);
    }

    /**
     * @throws InvalidResponse
     */
    private static function result(JsonReader $reader): ViesResult
    {
        $valid = $reader->bool('valid');
        $vatId = VatId::tryParse($reader->string('countryCode').$reader->string('vatNumber'))
            ?? throw $reader->invalid('vatNumber', 'VAT number');

        return new ViesResult(
            $vatId,
            $valid,
            self::disclosed($reader->optionalString('name')),
            self::disclosed($reader->optionalString('address')),
            self::match($reader, 'traderNameMatch'),
            self::match($reader, 'traderStreetMatch'),
            self::match($reader, 'traderPostalCodeMatch'),
            self::match($reader, 'traderCityMatch'),
            self::match($reader, 'traderCompanyTypeMatch'),
            $reader->optionalString('requestIdentifier'),
            $reader->dateTimeUtc('requestDate'),
        );
    }

    /**
     * @throws InvalidResponse
     */
    private static function match(JsonReader $reader, string $key): ?MatchResult
    {
        $value = $reader->optionalString($key);

        return null === $value ? null : MatchResult::tryFrom($value) ?? throw $reader->invalid($key, 'match result');
    }

    private static function disclosed(?string $value): ?string
    {
        return self::UNDISCLOSED === $value ? null : $value;
    }

    /**
     * First error code of a non-200 body; null when the body is not readable, so it never hides the status.
     */
    private static function errorCode(string $content): ?string
    {
        try {
            $first = JsonReader::tryFromJson($content, Source::Vies)?->optionalObjectList('errorWrappers')[0] ?? null;

            return $first?->optionalString('error');
        } catch (InvalidResponse) {
            return null;
        }
    }
}
