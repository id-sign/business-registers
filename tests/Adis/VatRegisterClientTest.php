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
use IdSign\BusinessRegisters\Tests\FixtureLoader;
use IdSign\BusinessRegisters\VatId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

    public function testSoapFaultWithHttpErrorIsServiceUnavailable(): void
    {
        $client = new VatRegisterClient(new MockHttpClient(self::xml('soap-fault.xml', 500)));

        $this->expectException(ServiceUnavailable::class);

        $client->findMany(['CZ45274649']);
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
