<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Ares;

use IdSign\BusinessRegisters\Ares\AresClient;
use IdSign\BusinessRegisters\Ares\Companies;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\CompanyDirectory;
use IdSign\BusinessRegisters\Ares\CompanySearch;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\Double\CountingHttpClient;
use IdSign\BusinessRegisters\Tests\FixtureLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(AresClient::class)]
final class AresClientBulkTest extends TestCase
{
    private const string EMPTY_PAGE = '{"pocetCelkem":0,"ekonomickeSubjekty":[]}';

    private static function json(string $fixture, int $status = 200): MockResponse
    {
        return new MockResponse(FixtureLoader::read('Ares/'.$fixture), ['http_code' => $status]);
    }

    /**
     * @return array<mixed>
     */
    private function sentBody(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'];
        self::assertIsString($body);
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function sorted(array $data): array
    {
        ksort($data);

        return array_map(static fn (mixed $value): mixed => \is_array($value) ? self::sorted($value) : $value, $data);
    }

    /**
     * @return list<string> valid company ids, all distinct
     */
    private static function companyIds(int $count): array
    {
        $ids = [];
        for ($n = 1; $n <= $count; ++$n) {
            $base = str_pad((string) $n, 7, '0', \STR_PAD_LEFT);
            $sum = 0;
            foreach ([8, 7, 6, 5, 4, 3, 2] as $position => $weight) {
                $sum += (int) $base[$position] * $weight;
            }
            $ids[] = $base.((11 - $sum % 11) % 10);
        }

        return $ids;
    }

    /**
     * Answers every request with one subject per requested id.
     *
     * @param array<int, array{int, string|\Throwable}> $failures replace the answer, by ordinal of the request
     */
    private static function countingClient(array $failures = []): CountingHttpClient
    {
        return new CountingHttpClient(static function (int $ordinal, string $method, string $url, array $options) use ($failures): array {
            if (isset($failures[$ordinal])) {
                return $failures[$ordinal];
            }

            $sent = $options['body'] ?? null;
            if (!\is_string($sent)) {
                throw new \LogicException('The request body is not a string.');
            }
            $request = json_decode($sent, true, 512, \JSON_THROW_ON_ERROR);
            $requested = \is_array($request) && \is_array($request['ico'] ?? null) ? $request['ico'] : [];
            $subjects = [];
            foreach ($requested as $ico) {
                $subjects[] = ['icoId' => 'ARES_'.(\is_string($ico) ? $ico : ''), 'ico' => $ico, 'obchodniJmeno' => 'Company '.(\is_string($ico) ? $ico : '')];
            }

            return [200, json_encode(['pocetCelkem' => \count($subjects), 'ekonomickeSubjekty' => $subjects], \JSON_THROW_ON_ERROR)];
        });
    }

    public function testClientExposesBulkAndSearchThroughTheCompanyDirectoryInterface(): void
    {
        $directory = self::asDirectory(new AresClient(new MockHttpClient(self::json('vyhledat-ico.json'))));

        self::assertCount(1, $directory->findMany(['45317054', '04957423']));
    }

    private static function asDirectory(CompanyDirectory $directory): CompanyDirectory
    {
        return $directory;
    }

    public function testFindManyPostsTheIdsToTheSearchEndpointAlwaysSendingTheCount(): void
    {
        $response = self::json('vyhledat-ico.json');

        new AresClient(new MockHttpClient($response))->findMany(['45317054', '04957423']);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/vyhledat',
            $response->getRequestUrl(),
        );
        self::assertSame(['ico' => ['45317054', '04957423'], 'pocet' => 2, 'start' => 0], $this->sentBody($response));
        $headers = $response->getRequestOptions()['headers'];
        self::assertIsArray($headers);
        self::assertContains('Accept: application/json', $headers);
        self::assertContains('Content-Type: application/json', $headers);
    }

    public function testFindManySendsConfiguredTimeoutAsTimeoutAndMaxDuration(): void
    {
        $response = self::json('vyhledat-ico.json');

        new AresClient(new MockHttpClient($response), timeout: 2.5)->findMany(['45317054']);

        self::assertSame(2.5, $response->getRequestOptions()['timeout']);
        self::assertSame(2.5, $response->getRequestOptions()['max_duration']);
    }

    public function testFindManyReturnsFoundCompaniesAndOmitsMissingOnes(): void
    {
        $companies = new AresClient(new MockHttpClient(self::json('vyhledat-ico.json')))
            ->findMany(['45317054', '04957423']);

        self::assertCount(1, $companies);
        self::assertTrue($companies->has('45317054'));
        self::assertFalse($companies->has('04957423'));
        self::assertSame('Komerční banka, a.s.', $companies->get('45317054')?->name);
        self::assertEquals([CompanyId::parse('04957423')], $companies->missing(['45317054', '04957423']));
    }

    public function testFindManyReturnsRegisterSubjectsWhoseCompanyIdFailsTheCheckDigit(): void
    {
        $response = self::json('vyhledat-check-digit-mismatch.json');
        $ids = [CompanyId::fromRegister('00123562'), CompanyId::fromRegister('29340042')];

        $companies = new AresClient(new MockHttpClient($response))->findMany($ids);

        self::assertSame(['ico' => ['00123562', '29340042'], 'pocet' => 2, 'start' => 0], $this->sentBody($response));
        self::assertCount(2, $companies);
        self::assertTrue($companies->has($ids[0]));
        self::assertTrue($companies->has($ids[1]));
        self::assertSame('Praha 10 - Gutovka, příspěvková organizace', $companies->get($ids[1])?->name);
        self::assertSame([], $companies->missing($ids));
    }

    public function testFindManyRejectsStringsThatFailTheCheckDigitWithoutAnyRequest(): void
    {
        $httpClient = new MockHttpClient(self::json('vyhledat-check-digit-mismatch.json'));

        try {
            new AresClient($httpClient)->findMany(['00123562', '29340042']);
            self::fail('Expected InvalidInput was not thrown.');
        } catch (InvalidInput) {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    public function testFindManyWithNoIdsReturnsEmptyCollectionWithoutAnyRequest(): void
    {
        $httpClient = new MockHttpClient(self::json('vyhledat-ico.json'));

        $companies = new AresClient($httpClient)->findMany([]);

        self::assertCount(0, $companies);
        self::assertSame([], $companies->all());
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testFindManyRemovesDuplicatesAfterNormalisation(): void
    {
        $response = self::json('vyhledat-ico.json');

        new AresClient(new MockHttpClient($response))->findMany(['45317054', '45317054', '45 317 054']);

        self::assertSame(['ico' => ['45317054'], 'pocet' => 1, 'start' => 0], $this->sentBody($response));
    }

    public function testFindManyPadsShortIdsToEightDigits(): void
    {
        $response = self::json('vyhledat-ico.json');

        new AresClient(new MockHttpClient($response))->findMany(['64581']);

        self::assertSame(['ico' => ['00064581'], 'pocet' => 1, 'start' => 0], $this->sentBody($response));
    }

    public function testFindManyWithExactlyOneHundredIdsUsesASingleRequest(): void
    {
        $response = new MockResponse(self::EMPTY_PAGE);
        $httpClient = new MockHttpClient($response);

        new AresClient($httpClient)->findMany(self::companyIds(100));

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame(100, $this->sentBody($response)['pocet']);
    }

    public function testFindManySplitsTwoHundredFiftyIdsIntoThreeRequestsOfAtMostOneHundred(): void
    {
        $responses = [new MockResponse(self::EMPTY_PAGE), new MockResponse(self::EMPTY_PAGE), new MockResponse(self::EMPTY_PAGE)];
        $httpClient = new MockHttpClient($responses);
        $ids = self::companyIds(250);

        new AresClient($httpClient)->findMany($ids);

        self::assertSame(3, $httpClient->getRequestsCount());
        $sent = [];
        $sizes = [];
        foreach ($responses as $response) {
            $body = $this->sentBody($response);
            self::assertSame(0, $body['start']);
            self::assertIsArray($body['ico']);
            self::assertSame(\count($body['ico']), $body['pocet']);
            $sizes[] = $body['pocet'];
            foreach ($body['ico'] as $ico) {
                self::assertIsString($ico);
                $sent[] = $ico;
            }
        }
        self::assertSame([100, 100, 50], $sizes);
        self::assertSame($ids, $sent);
    }

    public function testFindManyMergesResultsOfAllChunks(): void
    {
        $httpClient = new MockHttpClient([self::json('vyhledat-ico.json'), self::json('vyhledat-search.json')]);

        $companies = new AresClient($httpClient)->findMany(self::companyIds(101));

        $ids = array_map(static fn (Company $company): ?string => $company->id?->value, $companies->all());
        sort($ids);
        self::assertCount(4, $companies);
        self::assertSame(['14504219', '24729035', '28255933', '45317054'], $ids);
    }

    public function testFindManyRejectsAnInvalidIdWithoutAnyRequest(): void
    {
        $httpClient = new MockHttpClient(self::json('vyhledat-ico.json'));

        try {
            new AresClient($httpClient)->findMany(['45317054', '12345678']);
            self::fail('Expected InvalidInput was not thrown.');
        } catch (InvalidInput) {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideNonStringIds(): iterable
    {
        yield 'int' => [45317054];
        yield 'float' => [45317054.0];
        yield 'null' => [null];
        yield 'array' => [['45317054']];
    }

    #[DataProvider('provideNonStringIds')]
    public function testFindManyRejectsANonStringElementNamingItsIndexAndTheExpectedTypeWithoutAnyRequest(mixed $element): void
    {
        $httpClient = new MockHttpClient(self::json('vyhledat-ico.json'));
        $ids = ['45317054', '64581', $element];

        try {
            // reflection: PHPStan rejects a non-string element in the typed list; the runtime check is what non-analysed callers hit
            $client = new AresClient($httpClient);
            new \ReflectionMethod($client, 'findMany')->invoke($client, $ids);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('2', $e->getMessage());
            self::assertStringContainsString('string', $e->getMessage());
            self::assertSame(0, $httpClient->getRequestsCount());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testFindManyAcceptsAStringKeyedArrayOfValidIds(): void
    {
        $response = self::json('vyhledat-ico.json');
        $client = new AresClient(new MockHttpClient($response));

        // reflection: PHPStan requires a list; non-analysed callers may pass string keys
        $companies = new \ReflectionMethod($client, 'findMany')->invoke($client, ['a' => '45317054', 'b' => '04957423']);

        self::assertInstanceOf(Companies::class, $companies);
        self::assertTrue($companies->has('45317054'));
        self::assertSame(['ico' => ['45317054', '04957423'], 'pocet' => 2, 'start' => 0], $this->sentBody($response));
    }

    public function testFindManyRejectsAWrongTypedElementUnderAStringKeyNamingThatKeyWithoutAnyRequest(): void
    {
        $httpClient = new MockHttpClient(self::json('vyhledat-ico.json'));
        $client = new AresClient($httpClient);

        try {
            // reflection: PHPStan requires a list; non-analysed callers may pass string keys
            new \ReflectionMethod($client, 'findMany')->invoke($client, ['a' => '45317054', 'name' => 45317054]);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('name', $e->getMessage());
            self::assertSame(0, $httpClient->getRequestsCount());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testFindManyResponseRepeatingTheSameSubjectIsInvalidResponseFromAres(): void
    {
        $data = json_decode(FixtureLoader::read('Ares/vyhledat-ico.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $subjects = $data['ekonomickeSubjekty'] ?? null;
        self::assertIsArray($subjects);
        $data['ekonomickeSubjekty'] = [$subjects[0], $subjects[0]];
        $client = new AresClient(new MockHttpClient(new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR))));

        try {
            $client->findMany(['45317054', '00064581']);
        } catch (InvalidResponse $e) {
            self::assertSame(Source::Ares, $e->source);
            self::assertSame('ARES: expected unique company id at ekonomickeSubjekty[1].ico', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    public function testFindManyBadRequestIsInvalidInputCarryingTheSubCode(): void
    {
        $client = new AresClient(new MockHttpClient(self::json('error-400-generic.json', 400)));

        try {
            $client->findMany(['45317054']);
        } catch (InvalidInput $e) {
            self::assertSame('VSTUP_NEVALIDNI_FORMAT_ICO', $e->errorCode);

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testFindManyServerErrorIsServiceUnavailableFromAres(): void
    {
        $client = new AresClient(new MockHttpClient(new MockResponse('{"subKod":"SYSTEMOVA_CHYBA"}', ['http_code' => 503])));

        try {
            $client->findMany(['45317054']);
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Ares, $e->source);
            self::assertSame('SYSTEMOVA_CHYBA', $e->errorCode);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testFindManyTransportErrorIsServiceUnavailable(): void
    {
        $client = new AresClient(new MockHttpClient(new MockResponse(info: ['error' => 'host unreachable'])));

        try {
            $client->findMany(['45317054']);
        } catch (ServiceUnavailable $e) {
            self::assertNotNull($e->getPrevious());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testFindManyResponseElementWithoutCompanyIdIsInvalidResponse(): void
    {
        $body = '{"pocetCelkem":1,"ekonomickeSubjekty":[{"icoId":"ARES_00369838","obchodniJmeno":"SENTINEL-NAME"}]}';
        $client = new AresClient(new MockHttpClient(new MockResponse($body)));

        try {
            $client->findMany(['45317054']);
        } catch (InvalidResponse $e) {
            self::assertSame('ARES: expected company id at ekonomickeSubjekty[0].ico', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL-NAME', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    public function testFindManyBodyThatIsNotJsonIsInvalidResponse(): void
    {
        $client = new AresClient(new MockHttpClient(self::json('find-invalid.json')));

        $this->expectException(InvalidResponse::class);

        $client->findMany(['45317054']);
    }

    // --- concurrency ---

    /**
     * @return list<string|null>
     */
    private static function idsOf(Companies $companies): array
    {
        return array_map(static fn (Company $company): ?string => $company->id?->value, $companies->all());
    }

    public function testFindManyHasAtMostTwoBatchesOpenByDefault(): void
    {
        $http = self::countingClient();
        $ids = self::companyIds(250);

        $companies = new AresClient($http)->findMany($ids);

        self::assertSame(2, $http->maxOpen);
        self::assertSame(3, $http->issued);
        self::assertSame($ids, self::idsOf($companies));
    }

    /**
     * @return iterable<string, array{int, int, int}> concurrency, number of ids, expected peak of open requests
     */
    public static function provideConcurrencyShapes(): iterable
    {
        yield 'one at a time' => [1, 250, 1];
        yield 'waves of two' => [2, 250, 2];
        yield 'waves of three' => [3, 250, 3];
        yield 'fewer batches than the concurrency' => [4, 250, 3];
        yield 'waves of four' => [4, 500, 4];
        yield 'a single batch' => [2, 100, 1];
    }

    #[DataProvider('provideConcurrencyShapes')]
    public function testFindManyNeverHasMoreBatchesOpenThanMaxConcurrencyAndKeepsTheSequentialResult(int $concurrency, int $count, int $expectedPeak): void
    {
        $http = self::countingClient();
        $ids = self::companyIds($count);

        $companies = new AresClient($http, maxConcurrency: $concurrency)->findMany($ids);

        self::assertSame($expectedPeak, $http->maxOpen);
        self::assertSame((int) ceil($count / 100), $http->issued);
        self::assertSame(0, $http->open);
        self::assertSame($ids, self::idsOf($companies));
    }

    /**
     * @return iterable<string, array{int, int, int}> concurrency, ordinal of the failing batch, expected number of requests
     */
    public static function provideFailureWaves(): iterable
    {
        yield 'sequential, second batch' => [1, 1, 2];
        yield 'waves of two, second batch' => [2, 1, 2];
        yield 'waves of two, first batch of the second wave' => [2, 2, 4];
        yield 'waves of three, first batch' => [3, 0, 3];
        yield 'waves of four, second batch' => [4, 1, 4];
        yield 'waves of four, only batch of the second wave' => [4, 4, 5];
    }

    #[DataProvider('provideFailureWaves')]
    public function testFindManyStopsAfterTheWaveWithTheFailingBatchAndThrowsTheFirstFailureInSendingOrder(int $concurrency, int $failingBatch, int $expectedRequests): void
    {
        $failures = [];
        for ($ordinal = $failingBatch + 1; $ordinal < 5; ++$ordinal) {
            $failures[$ordinal] = [503, '{"subKod":"SYSTEMOVA_CHYBA"}'];
        }
        $failures[$failingBatch] = [400, FixtureLoader::read('Ares/error-400-generic.json')];
        $http = self::countingClient($failures);
        $client = new AresClient($http, maxConcurrency: $concurrency);

        try {
            $client->findMany(self::companyIds(450));
            self::fail('Expected InvalidInput was not thrown.');
        } catch (InvalidInput $e) {
            self::assertSame('VSTUP_NEVALIDNI_FORMAT_ICO', $e->errorCode);
        }

        self::assertSame($expectedRequests, $http->issued);
    }

    /**
     * @return iterable<string, array{int, string|\Throwable, class-string<\Throwable>, string|null}> status, body, exception, error code
     */
    public static function provideBatchFailures(): iterable
    {
        yield 'bad request' => [400, '{"kod":"CHYBA_VSTUPU","subKod":"VSTUP_NEVALIDNI_FORMAT_ICO"}', InvalidInput::class, 'VSTUP_NEVALIDNI_FORMAT_ICO'];
        yield 'server error' => [503, '{"subKod":"SYSTEMOVA_CHYBA"}', ServiceUnavailable::class, 'SYSTEMOVA_CHYBA'];
        yield 'body is not json' => [200, 'not json', InvalidResponse::class, null];
        yield 'transport error' => [200, new TransportException('connection reset'), ServiceUnavailable::class, null];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('provideBatchFailures')]
    public function testFindManyFailureOfOneBatchInAWaveThrowsTheSameExceptionAsTheSequentialPath(int $status, string|\Throwable $body, string $exception, ?string $errorCode): void
    {
        $failures = [1 => [$status, $body], 2 => [503, '{"subKod":"SYSTEMOVA_CHYBA"}'], 3 => [503, '{"subKod":"SYSTEMOVA_CHYBA"}']];
        $http = self::countingClient($failures);
        $client = new AresClient($http, maxConcurrency: 4);

        try {
            $client->findMany(self::companyIds(450));
            self::fail('Expected an exception was not thrown.');
        } catch (InvalidInput|ServiceUnavailable|InvalidResponse $e) {
            self::assertInstanceOf($exception, $e);
            if (!$e instanceof InvalidInput) {
                self::assertSame(Source::Ares, $e->source);
            }
            if (!$e instanceof InvalidResponse) {
                self::assertSame($errorCode, $e->errorCode);
            }
        }

        self::assertSame(4, $http->issued);
    }

    public function testFindManySubjectRepeatedInAnotherBatchIsInvalidResponseFromAres(): void
    {
        $page = '{"pocetCelkem":1,"ekonomickeSubjekty":[{"icoId":"ARES_45317054","ico":"45317054","obchodniJmeno":"Repeated"}]}';
        $client = new AresClient(new MockHttpClient([new MockResponse($page), new MockResponse($page)]), maxConcurrency: 2);

        try {
            $client->findMany(self::companyIds(101));
        } catch (InvalidResponse $e) {
            self::assertSame(Source::Ares, $e->source);
            self::assertSame('ARES: expected unique company id at ekonomickeSubjekty[0].ico', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidMaxConcurrency(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above the limit' => [5];
        yield 'far above the limit' => [100];
    }

    #[DataProvider('provideInvalidMaxConcurrency')]
    public function testMaxConcurrencyOutsideOneToFourIsRejectedByTheConstructor(int $maxConcurrency): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $client = new AresClient(new MockHttpClient(), maxConcurrency: $maxConcurrency);

        unset($client);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideValidMaxConcurrency(): iterable
    {
        yield 'one' => [1];
        yield 'two' => [2];
        yield 'three' => [3];
        yield 'four' => [4];
    }

    #[DataProvider('provideValidMaxConcurrency')]
    public function testMaxConcurrencyFromOneToFourIsAccepted(int $maxConcurrency): void
    {
        $companies = new AresClient(new MockHttpClient(self::json('vyhledat-ico.json')), maxConcurrency: $maxConcurrency)
            ->findMany(['45317054']);

        self::assertCount(1, $companies);
    }

    public function testSearchPostsToTheSearchEndpointWithJsonHeadersAndTimeout(): void
    {
        $response = self::json('vyhledat-search.json');

        new AresClient(new MockHttpClient($response), timeout: 4.0)->search(new CompanySearch(name: 'ČEZ'));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/vyhledat',
            $response->getRequestUrl(),
        );
        $headers = $response->getRequestOptions()['headers'];
        self::assertIsArray($headers);
        self::assertContains('Accept: application/json', $headers);
        self::assertContains('Content-Type: application/json', $headers);
        self::assertSame(4.0, $response->getRequestOptions()['timeout']);
        self::assertSame(4.0, $response->getRequestOptions()['max_duration']);
    }

    /**
     * @return iterable<string, array{CompanySearch, array<mixed>}>
     */
    public static function provideSearchesAndTheirBodies(): iterable
    {
        yield 'name only, defaults for paging' => [
            new CompanySearch(name: 'ČEZ'),
            ['obchodniJmeno' => 'ČEZ', 'pocet' => 20, 'start' => 0],
        ];
        yield 'address only' => [
            new CompanySearch(address: 'Duhová 2/1444, Praha'),
            ['sidlo' => ['textovaAdresa' => 'Duhová 2/1444, Praha'], 'pocet' => 20, 'start' => 0],
        ];
        yield 'municipality only' => [
            new CompanySearch(municipalityCode: 554782),
            ['sidlo' => ['kodObce' => 554782], 'pocet' => 20, 'start' => 0],
        ];
        yield 'address and municipality share one seat object' => [
            new CompanySearch(address: 'Duhová', municipalityCode: 554782),
            ['sidlo' => ['textovaAdresa' => 'Duhová', 'kodObce' => 554782], 'pocet' => 20, 'start' => 0],
        ];
        yield 'legal forms' => [
            new CompanySearch(legalFormCodes: ['121', '112']),
            ['pravniForma' => ['121', '112'], 'pocet' => 20, 'start' => 0],
        ];
        yield 'nace codes' => [
            new CompanySearch(naceCodes: ['62100']),
            ['czNace' => ['62100'], 'pocet' => 20, 'start' => 0],
        ];
        yield 'tax offices' => [
            new CompanySearch(taxOfficeCodes: ['001', '002']),
            ['financniUrad' => ['001', '002'], 'pocet' => 20, 'start' => 0],
        ];
        yield 'paging and ordering' => [
            new CompanySearch(name: 'ČEZ', limit: 50, offset: 100, orderBy: ['obchodniJmeno', '-ico']),
            ['obchodniJmeno' => 'ČEZ', 'pocet' => 50, 'start' => 100, 'razeni' => ['obchodniJmeno', '-ico']],
        ];
        yield 'every criterion' => [
            new CompanySearch('ČEZ', 'Praha', 554782, ['121'], ['35110'], ['001'], 1000, 5, ['-ico']),
            [
                'obchodniJmeno' => 'ČEZ',
                'sidlo' => ['textovaAdresa' => 'Praha', 'kodObce' => 554782],
                'pravniForma' => ['121'],
                'czNace' => ['35110'],
                'financniUrad' => ['001'],
                'pocet' => 1000,
                'start' => 5,
                'razeni' => ['-ico'],
            ],
        ];
        yield 'smallest limit' => [
            new CompanySearch(name: 'ČEZ', limit: 1),
            ['obchodniJmeno' => 'ČEZ', 'pocet' => 1, 'start' => 0],
        ];
    }

    /**
     * @param array<mixed> $expected
     */
    #[DataProvider('provideSearchesAndTheirBodies')]
    public function testSearchSendsOnlyTheFilledCriteria(CompanySearch $query, array $expected): void
    {
        $response = self::json('vyhledat-search.json');

        new AresClient(new MockHttpClient($response))->search($query);

        self::assertSame(self::sorted($expected), self::sorted($this->sentBody($response)));
    }

    /**
     * @return iterable<string, array{CompanySearch}>
     */
    public static function provideRejectedSearches(): iterable
    {
        yield 'no criterion' => [new CompanySearch()];
        yield 'only paging and ordering' => [new CompanySearch(limit: 10, offset: 5, orderBy: ['ico'])];
        yield 'limit zero' => [new CompanySearch(name: 'ČEZ', limit: 0)];
        yield 'negative limit' => [new CompanySearch(name: 'ČEZ', limit: -1)];
        yield 'limit above one thousand' => [new CompanySearch(name: 'ČEZ', limit: 1001)];
        yield 'negative offset' => [new CompanySearch(name: 'ČEZ', offset: -1)];
        yield 'blank name' => [new CompanySearch(name: '   ')];
        yield 'blank name and address' => [new CompanySearch(name: '', address: " \t")];
    }

    #[DataProvider('provideRejectedSearches')]
    public function testSearchRejectsInvalidQueryWithoutAnyRequest(CompanySearch $query): void
    {
        $httpClient = new MockHttpClient(self::json('vyhledat-search.json'));

        try {
            new AresClient($httpClient)->search($query);
            self::fail('Expected InvalidInput was not thrown.');
        } catch (InvalidInput) {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    public function testSearchWithInvalidUtf8TextIsInvalidInputWithoutRequestOrTheTextInTheMessage(): void
    {
        $httpClient = new MockHttpClient(self::json('vyhledat-search.json'));

        try {
            new AresClient($httpClient)->search(new CompanySearch(name: "\xB1\x31"));
        } catch (InvalidInput $e) {
            self::assertSame(0, $httpClient->getRequestsCount());
            self::assertTrue(mb_check_encoding($e->getMessage(), 'UTF-8'));
            self::assertStringNotContainsString("\xB1", $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testSearchReturnsTheTotalFromTheResponseNotTheNumberOfReturnedCompanies(): void
    {
        $result = new AresClient(new MockHttpClient(self::json('vyhledat-search.json')))
            ->search(new CompanySearch(name: 'ČEZ', limit: 3));

        self::assertSame(46, $result->total);
        self::assertCount(3, $result->companies);
        self::assertSame('ČEZ Distribuce, a. s.', $result->companies[1]->name);
    }

    public function testSearchReturnsEverySubjectOfAPageIncludingOnesWhoseCompanyIdFailsTheCheckDigit(): void
    {
        $result = new AresClient(new MockHttpClient(self::json('vyhledat-check-digit-mismatch.json')))
            ->search(new CompanySearch(name: 'Předměřice'));

        self::assertSame(2, $result->total);
        self::assertCount(2, $result->companies);
        self::assertSame('00123562', $result->companies[0]->id?->value);
        self::assertSame('29340042', $result->companies[1]->id?->value);
    }

    public function testSearchReturnsSubjectsWithoutCompanyIdAlongsideOthers(): void
    {
        $result = new AresClient(new MockHttpClient(self::json('vyhledat-search-no-ico.json')))
            ->search(new CompanySearch(name: 'ČEZ'));

        self::assertCount(4, $result->companies);
        self::assertNotNull($result->companies[0]->id);
        self::assertNull($result->companies[3]->id);
        self::assertSame('ARES_00369838', $result->companies[3]->aresId);
    }

    public function testSearchWithTooManyResultsIsInvalidInputWithAresErrorCode(): void
    {
        $client = new AresClient(new MockHttpClient(self::json('vyhledat-too-many-400.json', 400)));

        try {
            $client->search(new CompanySearch(name: 's.r.o.'));
        } catch (InvalidInput $e) {
            self::assertSame('VYSTUP_PRILIS_MNOHO_VYSLEDKU', $e->errorCode);
            self::assertStringNotContainsString('585 478', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testSearchServerErrorIsServiceUnavailableFromAres(): void
    {
        $client = new AresClient(new MockHttpClient(new MockResponse('{"subKod":"SYSTEMOVA_CHYBA"}', ['http_code' => 500])));

        try {
            $client->search(new CompanySearch(name: 'ČEZ'));
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Ares, $e->source);
            self::assertSame('SYSTEMOVA_CHYBA', $e->errorCode);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testSearchTransportErrorIsServiceUnavailable(): void
    {
        $client = new AresClient(new MockHttpClient(new MockResponse(info: ['error' => 'host unreachable'])));

        $this->expectException(ServiceUnavailable::class);

        $client->search(new CompanySearch(name: 'ČEZ'));
    }

    public function testSearchResponseWithoutTotalIsInvalidResponse(): void
    {
        $client = new AresClient(new MockHttpClient(new MockResponse('{"ekonomickeSubjekty":[]}')));

        try {
            $client->search(new CompanySearch(name: 'ČEZ'));
        } catch (InvalidResponse $e) {
            self::assertSame('ARES: missing pocetCelkem', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }
}
