<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares;

use IdSign\BusinessRegisters\Ares\Internal\CompanyMapper;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\HttpTransport;
use IdSign\BusinessRegisters\Internal\Identifiers;
use IdSign\BusinessRegisters\Internal\JsonReader;
use IdSign\BusinessRegisters\Internal\ListElement;
use IdSign\BusinessRegisters\Source;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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

    private const int MAX_CONCURRENCY = 4;

    private HttpTransport $transport;

    /** @var int<1, max> */
    private int $maxConcurrency;

    /**
     * @param float $timeout        seconds, applied as both the idle timeout and the maximum duration
     * @param int   $maxConcurrency batches of one findMany() call sent at the same time, 1 to 4
     *
     * @throws \InvalidArgumentException when `$maxConcurrency` is outside 1 to 4
     */
    public function __construct(
        HttpClientInterface $httpClient,
        private string $endpoint = self::ENDPOINT,
        float $timeout = 10.0,
        int $maxConcurrency = 2,
    ) {
        $this->transport = new HttpTransport($httpClient, Source::Ares, $timeout);
        $this->maxConcurrency = HttpTransport::concurrency($maxConcurrency, self::MAX_CONCURRENCY);
    }

    public function find(CompanyId|string $id): ?Company
    {
        $id = Identifiers::companyId($id);
        $subject = 'IČO '.$id;

        [$status, $content] = $this->transport->exchange(
            'GET',
            $this->endpoint.'/ekonomicke-subjekty/'.$id->value,
            ['headers' => ['Accept' => 'application/json']],
            $subject,
        );

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
            $values[] = Identifiers::companyId(ListElement::idOrString($id, $index, CompanyId::class))->value;
        }

        $subject = 'a batch of company ids';
        $senders = [];
        foreach (array_chunk(array_values(array_unique($values)), self::BATCH_SIZE) as $batch) {
            $json = self::encode(['ico' => $batch, 'pocet' => \count($batch), 'start' => 0], $subject);
            $senders[] = fn (): ResponseInterface => $this->transport->send('POST', $this->endpoint.self::SEARCH_PATH, self::postOptions($json), $subject);
        }

        $companies = [];
        $seen = [];
        $this->transport->sendInWaves($senders, $this->maxConcurrency, static function (int $status, string $content) use ($subject, &$companies, &$seen): void {
            foreach (self::page($status, $content, $subject)->objectList('ekonomickeSubjekty') as $element) {
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
        });

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
        [$status, $content] = $this->transport->exchange(
            'POST',
            $this->endpoint.self::SEARCH_PATH,
            self::postOptions(self::encode($body, $subject)),
            $subject,
        );

        return self::page($status, $content, $subject);
    }

    /**
     * @throws InvalidInput
     * @throws ServiceUnavailable
     * @throws InvalidResponse
     */
    private static function page(int $status, string $content, string $subject): JsonReader
    {
        if (200 === $status) {
            return JsonReader::fromJson($content, Source::Ares);
        }

        throw self::failure($status, JsonReader::tryFromJson($content, Source::Ares), $subject);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws InvalidInput
     */
    private static function encode(array $body, string $subject): string
    {
        try {
            return json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new InvalidInput(\sprintf('ARES request for %s cannot be encoded as JSON (invalid UTF-8)', $subject), previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function postOptions(string $json): array
    {
        return ['headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'], 'body' => $json];
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
        if ($query->offset < 0) {
            throw new InvalidInput(\sprintf('ARES search offset must be 0 or greater, %d given', $query->offset));
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
