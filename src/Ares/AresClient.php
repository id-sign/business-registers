<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares;

use IdSign\BusinessRegisters\Ares\Internal\CompanyMapper;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\JsonReader;
use IdSign\BusinessRegisters\Internal\ListElement;
use IdSign\BusinessRegisters\Source;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of the ARES REST API (economic subjects).
 */
final readonly class AresClient implements CompanyDirectory
{
    public const string ENDPOINT = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest';

    private const string NOT_FOUND_CODE = 'NENALEZENO';
    private const string NOT_FOUND_SUB_CODE = 'VYSTUP_SUBJEKT_NENALEZEN';
    private const string SEARCH_PATH = '/ekonomicke-subjekty/vyhledat';
    private const int BATCH_SIZE = 100;
    private const int MAX_SEARCH_LIMIT = 1000;

    /**
     * @param float $timeout seconds, applied as both the idle timeout and the maximum duration
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $endpoint = self::ENDPOINT,
        private float $timeout = 10.0,
    ) {
    }

    public function find(CompanyId|string $id): ?Company
    {
        $id = $id instanceof CompanyId ? $id : CompanyId::parse($id);
        $subject = 'IČO '.$id;

        [$status, $content] = $this->send('GET', '/ekonomicke-subjekty/'.$id->value, null, $subject);

        if (200 === $status) {
            return CompanyMapper::map(JsonReader::fromJson($content, Source::Ares));
        }

        $error = JsonReader::tryFromJson($content, Source::Ares);
        if (404 === $status && (self::NOT_FOUND_CODE === self::errorField($error, 'kod') || self::NOT_FOUND_SUB_CODE === self::errorField($error, 'subKod'))) {
            return null;
        }

        throw self::failure($status, $error, $subject);
    }

    public function findMany(array $ids): Companies
    {
        $values = [];
        foreach ($ids as $index => $id) {
            $id = ListElement::idOrString($id, $index, CompanyId::class);
            $values[] = $id instanceof CompanyId ? $id->value : CompanyId::parse($id)->value;
        }

        $companies = [];
        $seen = [];
        foreach (array_chunk(array_values(array_unique($values)), self::BATCH_SIZE) as $batch) {
            $page = $this->post(
                ['ico' => $batch, 'pocet' => \count($batch), 'start' => 0],
                \sprintf('a batch of %d company ids', \count($batch)),
            );

            foreach ($page->objectList('ekonomickeSubjekty') as $element) {
                $company = CompanyMapper::map($element);
                if (null === $company->id) {
                    throw $element->invalid('ico', 'company id');
                }
                if (isset($seen[$company->id->value])) {
                    throw $element->invalid('ico', 'unique company id');
                }
                $seen[$company->id->value] = true;
                $companies[] = $company;
            }
        }

        return new Companies($companies);
    }

    public function search(CompanySearch $query): CompanySearchResult
    {
        $page = $this->post(self::searchBody($query), 'a company search');

        return new CompanySearchResult(
            $page->int('pocetCelkem'),
            array_map(CompanyMapper::map(...), $page->objectList('ekonomickeSubjekty')),
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws InvalidInput
     * @throws ServiceUnavailable
     * @throws InvalidResponse
     */
    private function post(array $body, string $subject): JsonReader
    {
        try {
            $json = json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new InvalidInput(\sprintf('ARES request for %s cannot be encoded as JSON (invalid UTF-8)', $subject), previous: $e);
        }

        [$status, $content] = $this->send('POST', self::SEARCH_PATH, $json, $subject);

        if (200 === $status) {
            return JsonReader::fromJson($content, Source::Ares);
        }

        throw self::failure($status, JsonReader::tryFromJson($content, Source::Ares), $subject);
    }

    /**
     * @return array{int, string} HTTP status and body
     *
     * @throws ServiceUnavailable
     */
    private function send(string $method, string $path, ?string $json, string $subject): array
    {
        $options = [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => $this->timeout,
            'max_duration' => $this->timeout,
        ];
        if (null !== $json) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, $this->endpoint.$path, $options);

            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (TransportExceptionInterface $e) {
            throw new ServiceUnavailable(\sprintf('ARES request for %s failed: %s', $subject, $e->getMessage()), Source::Ares, previous: $e);
        }
    }

    private static function failure(int $status, ?JsonReader $error, string $subject): InvalidInput|ServiceUnavailable
    {
        $subCode = self::errorField($error, 'subKod');

        if (400 === $status) {
            return new InvalidInput(\sprintf('ARES rejected the request for %s', $subject), $subCode);
        }

        return new ServiceUnavailable(\sprintf('ARES returned HTTP %d for %s', $status, $subject), Source::Ares, $subCode);
    }

    /**
     * Only filled criteria are sent.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidInput
     */
    private static function searchBody(CompanySearch $query): array
    {
        $body = [];
        if (null !== $query->name && '' !== trim($query->name)) {
            $body['obchodniJmeno'] = $query->name;
        }
        $seat = [];
        if (null !== $query->address && '' !== trim($query->address)) {
            $seat['textovaAdresa'] = $query->address;
        }
        if (null !== $query->municipalityCode) {
            $seat['kodObce'] = $query->municipalityCode;
        }
        if ([] !== $seat) {
            $body['sidlo'] = $seat;
        }
        foreach (['pravniForma' => $query->legalFormCodes, 'czNace' => $query->naceCodes, 'financniUrad' => $query->taxOfficeCodes] as $key => $codes) {
            if ([] !== $codes) {
                $body[$key] = $codes;
            }
        }

        if ([] === $body) {
            throw new InvalidInput('ARES search needs at least one criterion: name, address, municipality, legal form, NACE or tax office code');
        }
        if ($query->limit < 1 || $query->limit > self::MAX_SEARCH_LIMIT) {
            throw new InvalidInput(\sprintf('ARES search limit must be between 1 and %d, %d given', self::MAX_SEARCH_LIMIT, $query->limit));
        }

        $body['pocet'] = $query->limit;
        $body['start'] = $query->offset;
        if ([] !== $query->orderBy) {
            $body['razeni'] = $query->orderBy;
        }

        return $body;
    }

    /**
     * A field of an error body; null when absent or not text, so a malformed error body never hides the HTTP status.
     */
    private static function errorField(?JsonReader $error, string $key): ?string
    {
        try {
            return $error?->optionalString($key);
        } catch (InvalidResponse) {
            return null;
        }
    }
}
