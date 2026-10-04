<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Vies;

use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\FixtureLoader;
use IdSign\BusinessRegisters\VatId;
use IdSign\BusinessRegisters\Vies\Vies;
use IdSign\BusinessRegisters\Vies\ViesClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ViesClient::class)]
final class ViesClientTest extends TestCase
{
    private static function client(MockResponse $response, float $timeout = 10.0): ViesClient
    {
        return new ViesClient(new MockHttpClient($response), timeout: $timeout);
    }

    private static function json(string $fixture, int $status = 200): MockResponse
    {
        return new MockResponse(FixtureLoader::read('Vies/'.$fixture), ['http_code' => $status]);
    }

    private static function errorBody(string $code): MockResponse
    {
        return new MockResponse(json_encode(['actionSucceed' => false, 'errorWrappers' => [['error' => $code]]], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private static function sentBody(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'];
        self::assertIsString($body);
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<mixed> the recorded valid response with the given keys replaced (null removes a key)
     */
    private static function validBodyWith(string $key, mixed $value): array
    {
        $data = json_decode(FixtureLoader::read('Vies/valid-cez.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        if (null === $value) {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private static function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    private static function asVies(Vies $vies): Vies
    {
        return $vies;
    }

    public function testClientCanBeUsedThroughTheViesInterface(): void
    {
        $result = self::asVies(self::client(self::json('valid-cez.json')))->check('CZ45274649');

        self::assertTrue($result->valid);
    }

    // --- request ---

    public function testCheckPostsJsonToTheViesEndpoint(): void
    {
        $response = self::json('valid-cez.json');

        self::client($response)->check('CZ45274649');

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number', $response->getRequestUrl());
        $headers = $response->getRequestOptions()['headers'];
        self::assertIsArray($headers);
        self::assertContains('Content-Type: application/json', $headers);
        self::assertContains('Accept: application/json', $headers);
    }

    public function testCheckPostsToTheConfiguredEndpoint(): void
    {
        $response = self::json('valid-cez.json');

        new ViesClient(new MockHttpClient($response), 'https://vies.example/check')->check('CZ45274649');

        self::assertSame('https://vies.example/check', $response->getRequestUrl());
    }

    public function testRequestsSendTheConfiguredTimeoutAsTimeoutAndMaxDuration(): void
    {
        $response = self::json('valid-cez.json');

        self::client($response, 2.5)->check('CZ45274649');

        self::assertSame(2.5, $response->getRequestOptions()['timeout']);
        self::assertSame(2.5, $response->getRequestOptions()['max_duration']);
    }

    public function testBodyCarriesTheCountryCodeAndTheNumberWithoutTheCountryPrefix(): void
    {
        $response = self::json('valid-cez.json');

        self::client($response)->check('CZ45274649');

        self::assertSame(['countryCode' => 'CZ', 'vatNumber' => '45274649'], self::sentBody($response));
    }

    public function testBodyHasNoRequesterKeysWithoutARequester(): void
    {
        $response = self::json('valid-cez.json');

        self::client($response)->check('CZ45274649');

        $body = self::sentBody($response);
        self::assertArrayNotHasKey('requesterMemberStateCode', $body);
        self::assertArrayNotHasKey('requesterNumber', $body);
    }

    public function testBodyCarriesTheRequesterWithoutItsCountryPrefix(): void
    {
        $response = self::json('valid-with-requester.json');

        self::client($response)->check('CZ45274649', 'CZ45274649');

        self::assertSame(
            ['countryCode' => 'CZ', 'vatNumber' => '45274649', 'requesterMemberStateCode' => 'CZ', 'requesterNumber' => '45274649'],
            self::sentBody($response),
        );
    }

    public function testCheckAcceptsVatIdObjectsForTheVatIdAndTheRequester(): void
    {
        $response = self::json('valid-with-requester.json');

        self::client($response)->check(VatId::parse('DE811115368'), VatId::parse('CZ45274649'));

        self::assertSame(
            ['countryCode' => 'DE', 'vatNumber' => '811115368', 'requesterMemberStateCode' => 'CZ', 'requesterNumber' => '45274649'],
            self::sentBody($response),
        );
    }

    public function testCheckNormalisesTheGivenVatId(): void
    {
        $response = self::json('valid-cez.json');

        self::client($response)->check('cz 4527 4649');

        self::assertSame(['countryCode' => 'CZ', 'vatNumber' => '45274649'], self::sentBody($response));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideInvalidInputs(): iterable
    {
        yield 'no country code and no default' => ['45274649', null];
        yield 'Czech number too short' => ['CZ123', null];
        yield 'empty' => ['', null];
        yield 'empty requester' => ['CZ45274649', ''];
        yield 'invalid requester' => ['CZ45274649', 'abc'];
        yield 'requester without country code' => ['CZ45274649', '45274649'];
    }

    #[DataProvider('provideInvalidInputs')]
    public function testInvalidInputIsRejectedWithoutAnyRequest(string $vatId, ?string $requester): void
    {
        $http = new MockHttpClient(self::json('valid-cez.json'));

        try {
            new ViesClient($http)->check($vatId, $requester);
        } catch (InvalidInput) {
            self::assertSame(0, $http->getRequestsCount());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    // --- result ---

    public function testValidVatIdIsMappedWithNameAndAddress(): void
    {
        $result = self::client(self::json('valid-cez.json'))->check('CZ45274649');

        self::assertSame('CZ45274649', (string) $result->vatId);
        self::assertTrue($result->valid);
        self::assertSame('ČEZ, a. s.', $result->name);
        self::assertSame("Duhová 1444/2\nPRAHA 4 - MICHLE\n140 00  PRAHA 4", $result->address);
    }

    public function testCheckedAtIsTheRequestDateInUtc(): void
    {
        $result = self::client(self::json('valid-cez.json'))->check('CZ45274649');

        self::assertSame('2026-10-03T21:02:05', $result->checkedAt->format('Y-m-d\TH:i:s'));
        self::assertSame(0, $result->checkedAt->getOffset());
    }

    public function testConsultationNumberIsNullWhenViesSendsAnEmptyIdentifier(): void
    {
        $result = self::client(self::json('valid-cez.json'))->check('CZ45274649');

        self::assertNull($result->consultationNumber);
    }

    public function testConsultationNumberIsTheRequestIdentifierWhenARequesterWasGiven(): void
    {
        $result = self::client(self::json('valid-with-requester.json'))->check('CZ45274649', 'CZ45274649');

        self::assertSame('WAPIAAAAaEDkvhHx', $result->consultationNumber);
    }

    public function testDashesAreNullNameAndAddress(): void
    {
        $result = self::client(self::json('valid-de-no-name.json'))->check('DE811115368');

        self::assertTrue($result->valid);
        self::assertSame('DE811115368', (string) $result->vatId);
        self::assertNull($result->name);
        self::assertNull($result->address);
    }

    public function testInvalidVatIdIsAResultWithValidFalse(): void
    {
        $result = self::client(self::json('invalid.json'))->check('CZ11111111');

        self::assertFalse($result->valid);
        self::assertNull($result->name);
        self::assertNull($result->address);
        self::assertNull($result->consultationNumber);
    }

    // --- errors reported with HTTP 200 ---

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInputErrorCodes(): iterable
    {
        yield 'invalid input' => ['INVALID_INPUT'];
        yield 'invalid requester info' => ['INVALID_REQUESTER_INFO'];
    }

    #[DataProvider('provideInputErrorCodes')]
    public function testInputErrorCodesAreInvalidInputCarryingTheCode(string $code): void
    {
        $client = self::client(self::errorBody($code));

        try {
            $client->check('CZ45274649');
        } catch (InvalidInput $e) {
            self::assertSame($code, $e->errorCode);
            self::assertStringEndsWith(' (error code '.$code.')', $e->getMessage());
            self::assertSame(1, substr_count($e->getMessage(), $code));

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideServiceErrorCodes(): iterable
    {
        yield 'service unavailable' => ['SERVICE_UNAVAILABLE'];
        yield 'member state unavailable' => ['MS_UNAVAILABLE'];
        yield 'timeout' => ['TIMEOUT'];
        yield 'global max concurrent' => ['GLOBAL_MAX_CONCURRENT_REQ'];
        yield 'global max concurrent time' => ['GLOBAL_MAX_CONCURRENT_REQ_TIME'];
        yield 'member state max concurrent' => ['MS_MAX_CONCURRENT_REQ'];
        yield 'member state max concurrent time' => ['MS_MAX_CONCURRENT_REQ_TIME'];
        yield 'vat blocked' => ['VAT_BLOCKED'];
        yield 'ip blocked' => ['IP_BLOCKED'];
        yield 'unknown code' => ['SOMETHING_NEW'];
    }

    #[DataProvider('provideServiceErrorCodes')]
    public function testOtherErrorCodesAreServiceUnavailableCarryingTheCode(string $code): void
    {
        $client = self::client(self::errorBody($code));

        try {
            $client->check('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Vies, $e->source);
            self::assertSame($code, $e->errorCode);
            self::assertStringEndsWith(' (error code '.$code.')', $e->getMessage());
            self::assertSame(1, substr_count($e->getMessage(), $code));

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testErrorMessageDoesNotLeakTheResponseText(): void
    {
        $body = '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE","message":"SENTINEL-VIES-TEXT"}]}';
        $client = self::client(new MockResponse($body));

        try {
            $client->check('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertStringNotContainsString('SENTINEL-VIES-TEXT', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testFailedActionNeverYieldsAResultEvenWhenTheBodyAlsoSaysValidFalse(): void
    {
        $body = '{"actionSucceed":false,"valid":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}';
        $client = self::client(new MockResponse($body));

        $this->expectException(ServiceUnavailable::class);

        $client->check('CZ45274649');
    }

    public function testErrorWrappersWithoutActionSucceedAreTreatedAsAnError(): void
    {
        $client = self::client(self::jsonResponse(['valid' => true, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE']]]));

        try {
            $client->check('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame('MS_UNAVAILABLE', $e->errorCode);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testFailedActionWithoutErrorsIsInvalidResponse(): void
    {
        $client = self::client(self::jsonResponse(['actionSucceed' => false, 'errorWrappers' => []]));

        try {
            $client->check('CZ45274649');
        } catch (InvalidResponse $e) {
            self::assertSame('VIES: expected non-empty list of errors at errorWrappers', $e->getMessage());
            self::assertSame(Source::Vies, $e->source);

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    // --- HTTP statuses and transport ---

    public function testBadRequestIsInvalidInputCarryingTheFirstErrorCode(): void
    {
        $client = self::client(self::json('http-400.json', 400));

        try {
            $client->check('CZ45274649');
        } catch (InvalidInput $e) {
            self::assertSame('VOW-ERR-11', $e->errorCode);
            self::assertStringEndsWith(' (error code VOW-ERR-11)', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL-VIES-TEXT', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testBadRequestWithoutJsonBodyIsStillInvalidInput(): void
    {
        $client = self::client(new MockResponse('nonsense', ['http_code' => 400]));

        try {
            $client->check('CZ45274649');
        } catch (InvalidInput $e) {
            self::assertNull($e->errorCode);

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideUnexpectedStatuses(): iterable
    {
        yield '500' => [500];
        yield '503' => [503];
        yield '429' => [429];
        yield '404' => [404];
    }

    #[DataProvider('provideUnexpectedStatuses')]
    public function testUnexpectedHttpStatusIsServiceUnavailableFromVies(int $status): void
    {
        $client = self::client(new MockResponse('{"actionSucceed":false,"errorWrappers":[{"error":"VOW-ERR-1"}]}', ['http_code' => $status]));

        try {
            $client->check('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Vies, $e->source);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testServerErrorWithAViesErrorBodyCarriesTheFirstErrorCode(): void
    {
        $body = '{"actionSucceed":false,"errorWrappers":[{"error":"VOW-ERR-1","message":"SENTINEL-VIES-TEXT"}]}';
        $client = self::client(new MockResponse($body, ['http_code' => 500]));

        try {
            $client->check('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame('VOW-ERR-1', $e->errorCode);
            self::assertStringEndsWith(' (error code VOW-ERR-1)', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL-VIES-TEXT', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnreadableServerErrorBodies(): iterable
    {
        yield 'html' => ['<html>Internal Server Error</html>'];
        yield 'malformed json' => ['{"errorWrappers":[{"error":'];
        yield 'empty' => [''];
    }

    #[DataProvider('provideUnreadableServerErrorBodies')]
    public function testServerErrorWithAnUnreadableBodyIsServiceUnavailableWithoutCode(string $body): void
    {
        $client = self::client(new MockResponse($body, ['http_code' => 500]));

        try {
            $client->check('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertNull($e->errorCode);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testTransportErrorIsServiceUnavailable(): void
    {
        $client = self::client(new MockResponse(info: ['error' => 'host unreachable']));

        try {
            $client->check('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Vies, $e->source);
            self::assertNull($e->errorCode);

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

        self::client($silent, 0.1)->check('CZ45274649');
    }

    // --- malformed responses ---

    public function testBodyThatIsNotJsonIsInvalidResponse(): void
    {
        $client = self::client(new MockResponse('<html>gateway</html>'));

        try {
            $client->check('CZ45274649');
        } catch (InvalidResponse $e) {
            self::assertSame('VIES: response is not valid JSON', $e->getMessage());
            self::assertSame(Source::Vies, $e->source);

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    public function testResponseWithoutValidIsInvalidResponse(): void
    {
        $client = self::client(self::json('missing-valid.json'));

        try {
            $client->check('CZ45274649');
        } catch (InvalidResponse $e) {
            self::assertSame('VIES: missing valid', $e->getMessage());
            self::assertSame(Source::Vies, $e->source);

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    public function testUnparsableRequestDateIsInvalidResponse(): void
    {
        $client = self::client(self::jsonResponse(self::validBodyWith('requestDate', 'yesterday-ish')));

        try {
            $client->check('CZ45274649');
        } catch (InvalidResponse $e) {
            self::assertSame('VIES: expected datetime at requestDate', $e->getMessage());
            self::assertStringNotContainsString('yesterday-ish', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    public function testResponseWithoutRequestDateIsInvalidResponse(): void
    {
        $client = self::client(self::jsonResponse(self::validBodyWith('requestDate', null)));

        try {
            $client->check('CZ45274649');
        } catch (InvalidResponse $e) {
            self::assertSame('VIES: missing requestDate', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    public function testUnparsableVatNumberInTheResponseIsInvalidResponseWithoutTheValue(): void
    {
        $client = self::client(self::jsonResponse(self::validBodyWith('vatNumber', '!SENTINEL!')));

        try {
            $client->check('CZ45274649');
        } catch (InvalidResponse $e) {
            self::assertSame('VIES: expected VAT number at vatNumber', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }
}
