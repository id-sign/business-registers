<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Ares;

use IdSign\BusinessRegisters\Ares\AresClient;
use IdSign\BusinessRegisters\Ares\CompanyDirectory;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\FixtureLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(AresClient::class)]
final class AresClientTest extends TestCase
{
    private static function client(MockResponse $response, float $timeout = 10.0): AresClient
    {
        return new AresClient(new MockHttpClient($response), timeout: $timeout);
    }

    private static function json(string $fixture, int $status = 200): MockResponse
    {
        return new MockResponse(FixtureLoader::read('Ares/'.$fixture), ['http_code' => $status]);
    }

    public function testClientCanBeUsedThroughTheCompanyDirectoryInterface(): void
    {
        $directory = self::asDirectory(self::client(self::json('find-not-found-404.json', 404)));

        self::assertNull($directory->find('04957423'));
    }

    private static function asDirectory(CompanyDirectory $directory): CompanyDirectory
    {
        return $directory;
    }

    public function testFindRequestsTheCompanyByGetWithJsonAcceptHeader(): void
    {
        $response = self::json('find-cez.json');

        self::client($response)->find('45274649');

        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame(
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/45274649',
            $response->getRequestUrl(),
        );
        $headers = $response->getRequestOptions()['headers'];
        self::assertIsArray($headers);
        self::assertContains('Accept: application/json', $headers);
    }

    public function testFindSendsConfiguredTimeoutAsTimeoutAndMaxDuration(): void
    {
        $response = self::json('find-cez.json');

        self::client($response, 3.5)->find('45274649');

        self::assertSame(3.5, $response->getRequestOptions()['timeout']);
        self::assertSame(3.5, $response->getRequestOptions()['max_duration']);
    }

    public function testFindUsesTheOverriddenEndpoint(): void
    {
        $response = self::json('find-cez.json');
        $client = new AresClient(new MockHttpClient($response), 'https://example.test/rest');

        $client->find('45274649');

        self::assertSame('https://example.test/rest/ekonomicke-subjekty/45274649', $response->getRequestUrl());
    }

    public function testFindReturnsTheMappedCompany(): void
    {
        $company = self::client(self::json('find-cez.json'))->find('45274649');

        self::assertNotNull($company);
        self::assertSame('ČEZ, a. s.', $company->name);
        self::assertNotNull($company->vatId);
        self::assertSame('CZ45274649', (string) $company->vatId);
    }

    public function testFindNormalisesTheGivenCompanyIdBeforeRequesting(): void
    {
        $response = self::json('find-cez.json');

        self::client($response)->find('452 746 49');

        self::assertStringEndsWith('/ekonomicke-subjekty/45274649', $response->getRequestUrl());
    }

    public function testFindAcceptsACompanyIdObject(): void
    {
        $response = self::json('find-cez.json');

        self::client($response)->find(CompanyId::parse('45274649'));

        self::assertStringEndsWith('/ekonomicke-subjekty/45274649', $response->getRequestUrl());
    }

    public function testFindRequestsAnIdFromTheRegisterThatFailsTheCheckDigit(): void
    {
        $response = self::json('find-check-digit-mismatch.json');

        $company = self::client($response)->find(CompanyId::fromRegister('00123562'));

        self::assertSame('GET', $response->getRequestMethod());
        self::assertStringEndsWith('/ekonomicke-subjekty/00123562', $response->getRequestUrl());
        self::assertNotNull($company);
        self::assertSame('00123562', $company->id?->value);
    }

    public function testFindRejectsAStringThatFailsTheCheckDigitWithoutAnyRequest(): void
    {
        $httpClient = new MockHttpClient(self::json('find-check-digit-mismatch.json'));

        try {
            new AresClient($httpClient)->find('00123562');
            self::fail('Expected InvalidInput was not thrown.');
        } catch (InvalidInput) {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    public function testFindPadsShortCompanyIdsToEightDigits(): void
    {
        $response = self::json('find-praha.json');

        self::client($response)->find('64581');

        self::assertStringEndsWith('/ekonomicke-subjekty/00064581', $response->getRequestUrl());
    }

    public function testFindReturnsNullWhenAresReportsTheSubjectAsNotFound(): void
    {
        self::assertNull(self::client(self::json('find-not-found-404.json', 404))->find('04957423'));
    }

    public function testNotFoundBodyWithoutJsonOnA404IsServiceUnavailable(): void
    {
        $client = self::client(new MockResponse('<html>Not found</html>', ['http_code' => 404]));

        $this->expectException(ServiceUnavailable::class);

        $client->find('45274649');
    }

    public function testOther404BodyIsServiceUnavailable(): void
    {
        $client = self::client(new MockResponse('{"kod":"JINA_CHYBA","subKod":"NECO_JINEHO"}', ['http_code' => 404]));

        try {
            $client->find('45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Ares, $e->source);
            self::assertSame('NECO_JINEHO', $e->errorCode);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testBadRequestIsInvalidInputCarryingTheSubCode(): void
    {
        $client = self::client(self::json('error-400-generic.json', 400));

        try {
            $client->find('45274649');
        } catch (InvalidInput $e) {
            self::assertSame('VSTUP_NEVALIDNI_FORMAT_ICO', $e->errorCode);
            self::assertStringEndsWith(' (error code VSTUP_NEVALIDNI_FORMAT_ICO)', $e->getMessage());
            self::assertSame(1, substr_count($e->getMessage(), 'VSTUP_NEVALIDNI_FORMAT_ICO'));

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testBadRequestWithoutJsonBodyIsStillInvalidInput(): void
    {
        $client = self::client(new MockResponse('nonsense', ['http_code' => 400]));

        try {
            $client->find('45274649');
        } catch (InvalidInput $e) {
            self::assertNull($e->errorCode);
            self::assertStringNotContainsString('error code', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testBadRequestMessageDoesNotLeakTheResponseDescription(): void
    {
        $body = '{"kod":"CHYBA_VSTUPU","subKod":"VSTUP_NEVALIDNI_FORMAT_ICO","popis":"SENTINEL-POPIS-9f3a"}';
        $client = self::client(new MockResponse($body, ['http_code' => 400]));

        try {
            $client->find('45274649');
        } catch (InvalidInput $e) {
            self::assertStringNotContainsString('SENTINEL-POPIS-9f3a', $e->getMessage());
            self::assertSame('VSTUP_NEVALIDNI_FORMAT_ICO', $e->errorCode);

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testNotFoundBodyWithWrongTypeCodesIsServiceUnavailableWithoutErrorCode(): void
    {
        $client = self::client(new MockResponse('{"kod":123,"subKod":{"a":1}}', ['http_code' => 404]));

        try {
            $client->find('45274649');
        } catch (ServiceUnavailable $e) {
            self::assertNull($e->errorCode);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideUnexpectedStatuses(): iterable
    {
        yield '500' => [500];
        yield '503' => [503];
        yield '429 rate limit' => [429];
        yield '403' => [403];
    }

    #[DataProvider('provideUnexpectedStatuses')]
    public function testUnexpectedHttpStatusIsServiceUnavailableFromAres(int $status): void
    {
        $client = self::client(new MockResponse('{"kod":"CHYBA","subKod":"SYSTEMOVA_CHYBA"}', ['http_code' => $status]));

        try {
            $client->find('45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Ares, $e->source);
            self::assertSame('SYSTEMOVA_CHYBA', $e->errorCode);
            self::assertStringEndsWith(' (error code SYSTEMOVA_CHYBA)', $e->getMessage());
            self::assertSame(1, substr_count($e->getMessage(), 'SYSTEMOVA_CHYBA'));

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testServerErrorWithUndecodableBodyIsServiceUnavailableWithoutErrorCode(): void
    {
        $client = self::client(new MockResponse('<html>Bad gateway</html>', ['http_code' => 502]));

        try {
            $client->find('45274649');
        } catch (ServiceUnavailable $e) {
            self::assertNull($e->errorCode);
            self::assertStringNotContainsString('Bad gateway', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testTransportErrorIsServiceUnavailable(): void
    {
        $client = self::client(new MockResponse(info: ['error' => 'host unreachable']));

        try {
            $client->find('45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Ares, $e->source);
            self::assertNotNull($e->getPrevious());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testIdleTimeoutIsServiceUnavailable(): void
    {
        $silent = new MockResponse((static function (): \Generator {
            yield '';
        })(), ['http_code' => 200]);

        $this->expectException(ServiceUnavailable::class);

        self::client($silent, 0.1)->find('45274649');
    }

    public function testBodyThatIsNotJsonIsInvalidResponse(): void
    {
        $client = self::client(self::json('find-invalid.json'));

        try {
            $client->find('45274649');
        } catch (InvalidResponse $e) {
            self::assertSame('ARES: response is not valid JSON', $e->getMessage());
            self::assertSame(Source::Ares, $e->source);

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    public function testRecordWithoutNameIsInvalidResponse(): void
    {
        $client = self::client(self::json('find-missing-name.json'));

        try {
            $client->find('45274649');
        } catch (InvalidResponse $e) {
            self::assertSame('ARES: missing obchodniJmeno', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidCompanyIds(): iterable
    {
        yield 'bad check digit' => ['12345678'];
        yield 'all zeros' => ['00000000'];
        yield 'letters' => ['abc'];
        yield 'empty' => [''];
        yield 'nine digits' => ['123456789'];
    }

    #[DataProvider('provideInvalidCompanyIds')]
    public function testInvalidCompanyIdIsRejectedWithoutAnyRequest(string $id): void
    {
        $httpClient = new MockHttpClient(self::json('find-cez.json'));
        $client = new AresClient($httpClient);

        try {
            $client->find($id);
            self::fail('Expected InvalidInput was not thrown.');
        } catch (InvalidInput) {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }
}
