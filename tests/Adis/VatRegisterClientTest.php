<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Adis;

use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\UnreliablePayer;
use IdSign\BusinessRegisters\Adis\VatRegister;
use IdSign\BusinessRegisters\Adis\VatRegisterClient;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\Adis\VatSubjects;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\Double\CountingHttpClient;
use IdSign\BusinessRegisters\Tests\FixtureLoader;
use IdSign\BusinessRegisters\VatId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(VatRegisterClient::class)]
final class VatRegisterClientTest extends TestCase
{
    private const string SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const string ADIS_NS = 'http://adis.mfcr.cz/rozhraniCRPDPH/';
    private const string ACTION_PREFIX = 'http://adis.mfcr.cz/rozhraniCRPDPH/';
    private const string EMPTY_ANSWER = '<?xml version="1.0" encoding="utf-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><StatusNespolehlivySubjektRozsirenyResponse xmlns="http://adis.mfcr.cz/rozhraniCRPDPH/"><status odpovedGenerovana="2026-10-03" statusCode="0" statusText="OK"/></StatusNespolehlivySubjektRozsirenyResponse></soapenv:Body></soapenv:Envelope>';

    private static function xml(string $fixture, int $status = 200): MockResponse
    {
        return new MockResponse(FixtureLoader::read('Adis/'.$fixture), ['http_code' => $status]);
    }

    private static function emptyAnswer(): MockResponse
    {
        return new MockResponse(self::EMPTY_ANSWER);
    }

    private static function answerWith(string $subjects): MockResponse
    {
        return new MockResponse(str_replace('</StatusNespolehlivySubjektRozsirenyResponse>', $subjects.'</StatusNespolehlivySubjektRozsirenyResponse>', self::EMPTY_ANSWER));
    }

    private static function sentXml(MockResponse $response): \SimpleXMLElement
    {
        $body = $response->getRequestOptions()['body'];
        self::assertIsString($body);
        $xml = simplexml_load_string($body);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        $xml->registerXPathNamespace('s', self::SOAP_NS);
        $xml->registerXPathNamespace('roz', self::ADIS_NS);

        return $xml;
    }

    /**
     * @return list<string> the numbers sent in the request body, in order
     */
    private static function sentNumbers(MockResponse $response): array
    {
        $nodes = self::sentXml($response)->xpath('/s:Envelope/s:Body/roz:StatusNespolehlivySubjektRozsirenyV2Request/roz:dic');
        self::assertIsArray($nodes);

        return array_values(array_map(static fn (\SimpleXMLElement $node): string => (string) $node, $nodes));
    }

    /**
     * @return list<string> valid Czech VAT ids, all distinct
     */
    private static function vatIds(int $count): array
    {
        $ids = [];
        for ($n = 1; $n <= $count; ++$n) {
            $ids[] = 'CZ'.str_pad((string) $n, 8, '0', \STR_PAD_LEFT);
        }

        return $ids;
    }

    /**
     * Answers every request with one subject per requested number.
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
            preg_match_all('~<roz:dic>(\d+)</roz:dic>~', $sent, $matches);
            $subjects = '';
            foreach ($matches[1] as $number) {
                $subjects .= '<statusSubjektu typSubjektu="PLATCE_DPH" dic="'.$number.'" nespolehlivyPlatce="NE" cisloFu="13"/>';
            }

            return [200, str_replace('</StatusNespolehlivySubjektRozsirenyResponse>', $subjects.'</StatusNespolehlivySubjektRozsirenyResponse>', self::EMPTY_ANSWER)];
        });
    }

    public function testClientIsAVatRegister(): void
    {
        $interfaces = class_implements(VatRegisterClient::class);

        self::assertIsArray($interfaces);
        self::assertContains(VatRegister::class, $interfaces);
    }

    // --- request ---

    public function testFindPostsASoapEnvelopeToTheEndpointWithTheSoapActionOfTheStatusOperation(): void
    {
        $response = self::xml('status-not-found.xml');

        new VatRegisterClient(new MockHttpClient($response))->find('CZ45274649');

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://mojedane.gov.cz/dpr/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP', $response->getRequestUrl());
        $headers = $response->getRequestOptions()['headers'];
        self::assertIsArray($headers);
        self::assertContains('Content-Type: text/xml; charset=utf-8', $headers);
        self::assertContains('SOAPAction: '.self::ACTION_PREFIX.'getStatusNespolehlivySubjektRozsirenyV2', $headers);
        self::assertSame(['45274649'], self::sentNumbers($response));
    }

    public function testClientPostsToTheConfiguredEndpoint(): void
    {
        $response = self::xml('status-not-found.xml');

        new VatRegisterClient(new MockHttpClient($response), 'https://adis.example/soap')->find('CZ45274649');

        self::assertSame('https://adis.example/soap', $response->getRequestUrl());
    }

    public function testRequestsSendTheConfiguredTimeoutAsTimeoutAndMaxDuration(): void
    {
        $response = self::xml('status-not-found.xml');

        new VatRegisterClient(new MockHttpClient($response), timeout: 2.5)->find('CZ45274649');

        self::assertSame(2.5, $response->getRequestOptions()['timeout']);
        self::assertSame(2.5, $response->getRequestOptions()['max_duration']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCzechVatIdForms(): iterable
    {
        yield 'canonical' => ['CZ45274649'];
        yield 'without country code' => ['45274649'];
        yield 'lower case with spaces' => ['cz 4527 4649'];
    }

    #[DataProvider('provideCzechVatIdForms')]
    public function testRequestCarriesTheNumberWithoutTheCountryCode(string $vatId): void
    {
        $response = self::xml('status-not-found.xml');

        new VatRegisterClient(new MockHttpClient($response))->find($vatId);

        self::assertSame(['45274649'], self::sentNumbers($response));
    }

    public function testFindAcceptsAVatIdObject(): void
    {
        $response = self::xml('status-not-found.xml');

        new VatRegisterClient(new MockHttpClient($response))->find(VatId::parse('CZ45274649'));

        self::assertSame(['45274649'], self::sentNumbers($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectedVatIds(): iterable
    {
        yield 'foreign country' => ['DE811115368'];
        yield 'too short' => ['CZ123'];
        yield 'empty' => [''];
    }

    #[DataProvider('provideRejectedVatIds')]
    public function testFindRejectsAnUnusableVatIdWithoutAnyRequest(string $vatId): void
    {
        $httpClient = new MockHttpClient(self::xml('status-not-found.xml'));

        try {
            new VatRegisterClient($httpClient)->find($vatId);
            self::fail('Expected InvalidInput was not thrown.');
        } catch (InvalidInput) {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    public function testFindAndFindManyRejectAForeignVatIdGivenAsAnObjectWithoutAnyRequest(): void
    {
        $httpClient = new MockHttpClient(self::emptyAnswer());
        $client = new VatRegisterClient($httpClient);
        $foreign = VatId::parse('DE811115368');

        $rejected = 0;
        foreach ([
            static fn () => $client->find($foreign),
            static fn () => $client->findMany([VatId::parse('CZ45274649'), $foreign]),
        ] as $call) {
            try {
                $call();
            } catch (InvalidInput) {
                ++$rejected;
            }
        }

        self::assertSame(2, $rejected);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    // --- find ---

    public function testFindReturnsNullForASubjectTheRegisterDoesNotKnow(): void
    {
        self::assertNull(new VatRegisterClient(new MockHttpClient(self::xml('status-not-found.xml')))->find('CZ11111111'));
    }

    public function testFindReturnsTheSubject(): void
    {
        $subject = new VatRegisterClient(new MockHttpClient(self::xml('status-identified-person.xml')))->find('CZ00101494');

        self::assertInstanceOf(VatSubject::class, $subject);
        self::assertSame('CZ00101494', (string) $subject->vatId);
        self::assertSame(SubjectType::IdentifiedPerson, $subject->type);
        self::assertSame('KNIHOVNA JIŘÍHO MAHENA', $subject->name);
    }

    // --- findMany ---

    public function testFindManySendsEveryNumberInOneEnvelope(): void
    {
        $response = self::xml('status-mixed.xml');

        new VatRegisterClient(new MockHttpClient($response))->findMany(['CZ45274649', 'CZ45317054', '00121100']);

        self::assertSame(['45274649', '45317054', '00121100'], self::sentNumbers($response));
    }

    public function testFindManyReturnsFoundSubjectsAndOmitsTheOnesTheRegisterDoesNotKnow(): void
    {
        $subjects = new VatRegisterClient(new MockHttpClient(self::xml('status-mixed.xml')))
            ->findMany(['CZ45274649', 'CZ00121100', 'CZ699001182', 'CZ00101494', 'CZ45317054']);

        self::assertCount(4, $subjects);
        self::assertTrue($subjects->has('CZ45274649'));
        self::assertTrue($subjects->has('45274649'));
        self::assertFalse($subjects->has('45317054'));
        self::assertSame(SubjectType::VatGroup, $subjects->get('CZ699001182')?->type);
        self::assertTrue($subjects->get('CZ00121100')?->unreliable);
        self::assertEquals(
            [VatId::parse('CZ45317054')],
            $subjects->missing(['CZ45274649', 'CZ00121100', 'CZ699001182', 'CZ00101494', 'CZ45317054']),
        );
    }

    public function testFindManyWithNoVatIdsReturnsAnEmptyCollectionWithoutAnyRequest(): void
    {
        $httpClient = new MockHttpClient(self::emptyAnswer());

        $subjects = new VatRegisterClient($httpClient)->findMany([]);

        self::assertCount(0, $subjects);
        self::assertSame([], $subjects->all());
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testFindManyRemovesDuplicatesAfterNormalisation(): void
    {
        $response = self::emptyAnswer();

        new VatRegisterClient(new MockHttpClient($response))->findMany(['CZ45274649', '45274649', 'cz 4527 4649']);

        self::assertSame(['45274649'], self::sentNumbers($response));
    }

    public function testFindManyWithExactlyOneHundredVatIdsUsesASingleRequest(): void
    {
        $response = self::emptyAnswer();
        $httpClient = new MockHttpClient($response);

        new VatRegisterClient($httpClient)->findMany(self::vatIds(100));

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertCount(100, self::sentNumbers($response));
    }

    public function testFindManySplitsTwoHundredFiftyVatIdsIntoThreeRequestsOfAtMostOneHundred(): void
    {
        $responses = [self::emptyAnswer(), self::emptyAnswer(), self::emptyAnswer()];
        $httpClient = new MockHttpClient($responses);
        $ids = self::vatIds(250);

        new VatRegisterClient($httpClient)->findMany($ids);

        self::assertSame(3, $httpClient->getRequestsCount());
        $sizes = [];
        $sent = [];
        foreach ($responses as $response) {
            $numbers = self::sentNumbers($response);
            $sizes[] = \count($numbers);
            $sent = [...$sent, ...$numbers];
        }
        self::assertSame([100, 100, 50], $sizes);
        self::assertSame(array_map(static fn (string $id): string => substr($id, 2), $ids), $sent);
    }

    public function testFindManyMergesTheSubjectsOfAllChunks(): void
    {
        $httpClient = new MockHttpClient([
            self::xml('status-mixed.xml'),
            self::answerWith('<statusSubjektu typSubjektu="PLATCE_DPH" dic="00000100" nespolehlivyPlatce="NE" cisloFu="13"/>'),
        ]);

        $subjects = new VatRegisterClient($httpClient)->findMany(self::vatIds(101));

        self::assertCount(5, $subjects);
        self::assertTrue($subjects->has('CZ00000100'));
        self::assertTrue($subjects->has('CZ45274649'));
    }

    #[DataProvider('provideRejectedVatIds')]
    public function testFindManyRejectsAnUnusableVatIdWithoutAnyRequest(string $vatId): void
    {
        $httpClient = new MockHttpClient(self::emptyAnswer());

        try {
            new VatRegisterClient($httpClient)->findMany(['CZ45274649', $vatId]);
            self::fail('Expected InvalidInput was not thrown.');
        } catch (InvalidInput) {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    public function testFindManyRejectsAnUnusableVatIdInALaterChunkBeforeTheFirstRequest(): void
    {
        $httpClient = new MockHttpClient(self::emptyAnswer());

        try {
            new VatRegisterClient($httpClient)->findMany([...self::vatIds(150), 'DE811115368']);
            self::fail('Expected InvalidInput was not thrown.');
        } catch (InvalidInput) {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideNonStringVatIds(): iterable
    {
        yield 'int' => [45274649];
        yield 'float' => [45274649.0];
        yield 'null' => [null];
        yield 'array' => [['45274649']];
    }

    #[DataProvider('provideNonStringVatIds')]
    public function testFindManyRejectsANonStringElementNamingItsIndexAndTheExpectedTypeWithoutAnyRequest(mixed $element): void
    {
        $httpClient = new MockHttpClient(self::emptyAnswer());
        $vatIds = ['CZ45274649', '00121100', $element];

        try {
            // reflection: PHPStan rejects a non-string element in the typed list; the runtime check is what non-analysed callers hit
            $client = new VatRegisterClient($httpClient);
            new \ReflectionMethod($client, 'findMany')->invoke($client, $vatIds);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('2', $e->getMessage());
            self::assertStringContainsString('string', $e->getMessage());
            self::assertSame(0, $httpClient->getRequestsCount());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testFindManyAcceptsAStringKeyedArrayOfValidVatIds(): void
    {
        $response = self::emptyAnswer();
        $client = new VatRegisterClient(new MockHttpClient($response));

        // reflection: PHPStan requires a list; non-analysed callers may pass string keys
        $subjects = new \ReflectionMethod($client, 'findMany')->invoke($client, ['a' => 'CZ45274649', 'b' => 'CZ00121100']);

        self::assertInstanceOf(VatSubjects::class, $subjects);
        self::assertSame(['45274649', '00121100'], self::sentNumbers($response));
    }

    public function testFindManyRejectsAWrongTypedElementUnderAStringKeyNamingThatKeyWithoutAnyRequest(): void
    {
        $httpClient = new MockHttpClient(self::emptyAnswer());
        $client = new VatRegisterClient($httpClient);

        try {
            // reflection: PHPStan requires a list; non-analysed callers may pass string keys
            new \ReflectionMethod($client, 'findMany')->invoke($client, ['a' => 'CZ45274649', 'name' => 45274649]);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('name', $e->getMessage());
            self::assertSame(0, $httpClient->getRequestsCount());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testFindManyResponseRepeatingTheSameSubjectIsInvalidResponseFromAdis(): void
    {
        $subject = '<statusSubjektu typSubjektu="PLATCE_DPH" dic="45274649" nespolehlivyPlatce="NE" cisloFu="13"><nazevSubjektu>SENTINEL-NAME</nazevSubjektu></statusSubjektu>';
        $client = new VatRegisterClient(new MockHttpClient(self::answerWith($subject.$subject)));

        try {
            $client->findMany(['CZ45274649']);
        } catch (InvalidResponse $e) {
            self::assertSame(Source::Adis, $e->source);
            self::assertStringNotContainsString('SENTINEL-NAME', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    // --- concurrency ---

    /**
     * @return list<string>
     */
    private static function vatIdsOf(VatSubjects $subjects): array
    {
        return array_map(static fn (VatSubject $subject): string => (string) $subject->vatId, $subjects->all());
    }

    public function testFindManyHasAtMostFourBatchesOpenByDefault(): void
    {
        $http = self::countingClient();
        $ids = self::vatIds(550);

        $subjects = new VatRegisterClient($http)->findMany($ids);

        self::assertSame(4, $http->maxOpen);
        self::assertSame(6, $http->issued);
        self::assertSame($ids, self::vatIdsOf($subjects));
    }

    /**
     * @return iterable<string, array{int, int, int}> concurrency, number of VAT ids, expected peak of open requests
     */
    public static function provideConcurrencyShapes(): iterable
    {
        yield 'one at a time' => [1, 250, 1];
        yield 'waves of two' => [2, 250, 2];
        yield 'waves of three' => [3, 450, 3];
        yield 'fewer batches than the concurrency' => [4, 250, 3];
        yield 'waves of four' => [4, 550, 4];
        yield 'a single batch' => [4, 100, 1];
    }

    #[DataProvider('provideConcurrencyShapes')]
    public function testFindManyNeverHasMoreBatchesOpenThanMaxConcurrencyAndKeepsTheSequentialResult(int $concurrency, int $count, int $expectedPeak): void
    {
        $http = self::countingClient();
        $ids = self::vatIds($count);

        $subjects = new VatRegisterClient($http, maxConcurrency: $concurrency)->findMany($ids);

        self::assertSame($expectedPeak, $http->maxOpen);
        self::assertSame((int) ceil($count / 100), $http->issued);
        self::assertSame(0, $http->open);
        self::assertSame($ids, self::vatIdsOf($subjects));
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
        yield 'waves of three, first batch of the second wave' => [3, 3, 6];
        yield 'waves of four, second batch' => [4, 1, 4];
        yield 'waves of four, last batch of the second wave' => [4, 5, 6];
    }

    #[DataProvider('provideFailureWaves')]
    public function testFindManyStopsAfterTheWaveWithTheFailingBatchAndThrowsTheFirstFailureInSendingOrder(int $concurrency, int $failingBatch, int $expectedRequests): void
    {
        $failures = [];
        for ($ordinal = $failingBatch + 1; $ordinal < 6; ++$ordinal) {
            $failures[$ordinal] = [500, FixtureLoader::read('Adis/soap-fault.xml')];
        }
        $failures[$failingBatch] = [200, FixtureLoader::read('Adis/invalid.xml')];
        $http = self::countingClient($failures);
        $client = new VatRegisterClient($http, maxConcurrency: $concurrency);

        try {
            $client->findMany(self::vatIds(550));
            self::fail('Expected InvalidResponse was not thrown.');
        } catch (InvalidResponse $e) {
            self::assertSame(Source::Adis, $e->source);
        }

        self::assertSame($expectedRequests, $http->issued);
    }

    /**
     * @return iterable<string, array{int, string|\Throwable, class-string<\Throwable>, string|null}> status, body, exception, error code
     */
    public static function provideBatchFailures(): iterable
    {
        yield 'soap fault' => [500, FixtureLoader::read('Adis/soap-fault.xml'), ServiceUnavailable::class, 'soapenv:Server'];
        yield 'http error without a fault' => [503, 'Service Unavailable', ServiceUnavailable::class, null];
        yield 'answer that is not xml' => [200, FixtureLoader::read('Adis/invalid.xml'), InvalidResponse::class, null];
        yield 'transport error' => [200, new TransportException('connection reset'), ServiceUnavailable::class, null];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('provideBatchFailures')]
    public function testFindManyFailureOfOneBatchInAWaveThrowsTheSameExceptionAsTheSequentialPath(int $status, string|\Throwable $body, string $exception, ?string $errorCode): void
    {
        $failures = [1 => [$status, $body], 2 => [500, 'Internal Server Error'], 3 => [500, 'Internal Server Error']];
        $http = self::countingClient($failures);
        $client = new VatRegisterClient($http, maxConcurrency: 4);

        try {
            $client->findMany(self::vatIds(550));
            self::fail('Expected an exception was not thrown.');
        } catch (ServiceUnavailable|InvalidResponse $e) {
            self::assertInstanceOf($exception, $e);
            self::assertSame(Source::Adis, $e->source);
            if ($e instanceof ServiceUnavailable) {
                self::assertSame($errorCode, $e->errorCode);
            }
        }

        self::assertSame(4, $http->issued);
    }

    public function testFindManySubjectRepeatedInAnotherBatchIsInvalidResponseFromAdis(): void
    {
        $subject = '<statusSubjektu typSubjektu="PLATCE_DPH" dic="45274649" nespolehlivyPlatce="NE" cisloFu="13"/>';
        $client = new VatRegisterClient(new MockHttpClient([self::answerWith($subject), self::answerWith($subject)]), maxConcurrency: 2);

        try {
            $client->findMany(self::vatIds(101));
        } catch (InvalidResponse $e) {
            self::assertSame(Source::Adis, $e->source);

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

        $client = new VatRegisterClient(new MockHttpClient(), maxConcurrency: $maxConcurrency);

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
        $subjects = new VatRegisterClient(new MockHttpClient(self::xml('status-mixed.xml')), maxConcurrency: $maxConcurrency)
            ->findMany(['CZ45274649']);

        self::assertCount(4, $subjects);
    }

    // --- failures ---

    public function testHttpErrorIsServiceUnavailableFromAdisMentioningTheStatusButNotTheBody(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(new MockResponse('SENTINEL-BODY', ['http_code' => 500])));

        try {
            $client->find('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Adis, $e->source);
            self::assertStringContainsString('500', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL-BODY', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    /**
     * The fixture is a hand-built copy of the real ADIS answer to a request with an unknown element (HTTP 500, empty
     * SOAP header, faultcode soapenv:Server); only the faultstring, an internal Java stack in reality, is replaced.
     */
    public function testSoapFaultWithHttpErrorIsServiceUnavailableCarryingTheFaultCode(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(self::xml('soap-fault.xml', 500)));

        try {
            $client->findMany(['CZ45274649']);
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Adis, $e->source);
            self::assertSame('soapenv:Server', $e->errorCode);
            self::assertStringContainsString('500', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL-FAULT-STRING', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testSoapFaultWithAnotherHttpErrorStatusKeepsTheFaultCode(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(self::xml('soap-fault.xml', 503)));

        try {
            $client->find('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame('soapenv:Server', $e->errorCode);
            self::assertStringContainsString('503', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testUnreliablePayersSoapFaultWithHttpErrorKeepsTheFaultCode(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(self::xml('soap-fault.xml', 500)));

        try {
            $client->unreliablePayers();
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Adis, $e->source);
            self::assertSame('soapenv:Server', $e->errorCode);
            self::assertStringNotContainsString('SENTINEL-FAULT-STRING', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideHttpErrorBodiesWithoutAFault(): iterable
    {
        yield 'plain text' => ['SENTINEL-BODY'];
        yield 'html page' => ['<html><body>SENTINEL-BODY</body></html>'];
        yield 'empty' => [''];
        yield 'truncated envelope' => ['<?xml version="1.0" encoding="utf-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><soapenv:Fault><faultcode>soapenv:Ser'];
        yield 'soap envelope without a fault' => ['<?xml version="1.0" encoding="utf-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><note>SENTINEL-BODY</note></soapenv:Body></soapenv:Envelope>'];
        yield 'fault without a fault code' => ['<?xml version="1.0" encoding="utf-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><soapenv:Fault><faultstring>SENTINEL-BODY</faultstring></soapenv:Fault></soapenv:Body></soapenv:Envelope>'];
    }

    #[DataProvider('provideHttpErrorBodiesWithoutAFault')]
    public function testHttpErrorWhoseBodyCarriesNoFaultCodeIsServiceUnavailableWithoutAnErrorCode(string $body): void
    {
        $client = new VatRegisterClient(new MockHttpClient(new MockResponse($body, ['http_code' => 500])));

        try {
            $client->find('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Adis, $e->source);
            self::assertNull($e->errorCode);
            self::assertStringContainsString('500', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL-BODY', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testHttpErrorWithAnAnswerBodyIsServiceUnavailableWithoutAnErrorCode(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(self::xml('status-not-found.xml', 500)));

        try {
            $client->find('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertNull($e->errorCode);
            self::assertStringContainsString('500', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testFaultCodeThatIsFreeTextNeverReachesTheMessage(): void
    {
        $body = '<?xml version="1.0" encoding="utf-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><soapenv:Fault><faultcode>SENTINEL FAULT TEXT</faultcode><faultstring>x</faultstring></soapenv:Fault></soapenv:Body></soapenv:Envelope>';
        $client = new VatRegisterClient(new MockHttpClient(new MockResponse($body, ['http_code' => 500])));

        try {
            $client->find('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertStringContainsString('500', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testSoapFaultWithHttpOkIsServiceUnavailableCarryingTheFaultCode(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(self::xml('soap-fault.xml')));

        try {
            $client->find('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame('soapenv:Server', $e->errorCode);
            self::assertStringNotContainsString('SENTINEL-FAULT-STRING', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testTransportErrorIsServiceUnavailableFromAdis(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(new MockResponse(info: ['error' => 'host unreachable'])));

        try {
            $client->find('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Adis, $e->source);
            self::assertNotNull($e->getPrevious());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testIdleTimeoutIsServiceUnavailableFromAdis(): void
    {
        $silent = new MockResponse((static function (): \Generator {
            yield '';
        })(), ['http_code' => 200]);
        $client = new VatRegisterClient(new MockHttpClient($silent), timeout: 0.1);

        try {
            $client->find('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Adis, $e->source);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnavailableStatusFixtures(): iterable
    {
        yield 'maintenance' => ['status-code-2.xml', '2'];
        yield 'unavailable' => ['status-code-3.xml', '3'];
    }

    #[DataProvider('provideUnavailableStatusFixtures')]
    public function testStatusCodeOfAnOutageIsServiceUnavailableCarryingTheCode(string $fixture, string $code): void
    {
        $client = new VatRegisterClient(new MockHttpClient(self::xml($fixture)));

        try {
            $client->find('CZ45274649');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Adis, $e->source);
            self::assertSame($code, $e->errorCode);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testStatusCodeOneIsAnInvalidResponseFromAdis(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(self::xml('status-code-1.xml')));

        try {
            $client->findMany(['CZ45274649']);
        } catch (InvalidResponse $e) {
            self::assertSame(Source::Adis, $e->source);

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    public function testResponseThatIsNotXmlIsInvalidResponse(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(self::xml('invalid.xml')));

        $this->expectException(InvalidResponse::class);

        $client->find('CZ45274649');
    }

    // --- unreliablePayers ---

    public function testUnreliablePayersPostsAnEmptyRequestElementWithTheSoapActionOfTheListOperation(): void
    {
        $response = self::xml('unreliable-payers.xml');

        new VatRegisterClient(new MockHttpClient($response), timeout: 30.0)->unreliablePayers();

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://mojedane.gov.cz/dpr/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP', $response->getRequestUrl());
        $headers = $response->getRequestOptions()['headers'];
        self::assertIsArray($headers);
        self::assertContains('Content-Type: text/xml; charset=utf-8', $headers);
        self::assertContains('SOAPAction: '.self::ACTION_PREFIX.'getSeznamNespolehlivyPlatce', $headers);
        $request = self::sentXml($response)->xpath('/s:Envelope/s:Body/roz:SeznamNespolehlivyPlatceRequest');
        self::assertIsArray($request);
        self::assertCount(1, $request);
        self::assertCount(0, $request[0]->children(self::ADIS_NS));
        self::assertSame(30.0, $response->getRequestOptions()['timeout']);
        self::assertSame(30.0, $response->getRequestOptions()['max_duration']);
    }

    public function testUnreliablePayersReturnsTheListWithDatesAndPaddedTaxOffices(): void
    {
        $payers = new VatRegisterClient(new MockHttpClient(self::xml('unreliable-payers.xml')))->unreliablePayers();

        self::assertSame(
            ['CZ00121100', 'CZ00559709', 'CZ27205746'],
            array_map(static fn (UnreliablePayer $payer): string => (string) $payer->vatId, $payers),
        );
        self::assertSame('456', $payers[0]->taxOfficeCode);
        self::assertSame('013', $payers[2]->taxOfficeCode);
        self::assertSame('2017-03-16', $payers[0]->since?->format('Y-m-d'));
    }

    public function testUnreliablePayersHttpErrorIsServiceUnavailable(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(new MockResponse('', ['http_code' => 503])));

        $this->expectException(ServiceUnavailable::class);

        $client->unreliablePayers();
    }

    public function testUnreliablePayersTransportErrorIsServiceUnavailable(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(new MockResponse(info: ['error' => 'timeout'])));

        $this->expectException(ServiceUnavailable::class);

        $client->unreliablePayers();
    }
}
