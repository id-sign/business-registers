<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Isir;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Isir\InsolvencyClient;
use IdSign\BusinessRegisters\Isir\InsolvencyRegister;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\FixtureLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(InsolvencyClient::class)]
final class InsolvencyClientTest extends TestCase
{
    private const string SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const string TYPES_NS = 'http://isirws.cca.cz/types/';

    private static function xml(string $fixture, int $status = 200): MockResponse
    {
        return new MockResponse(FixtureLoader::read('Isir/'.$fixture), ['http_code' => $status]);
    }

    private static function emptyAnswer(): MockResponse
    {
        return self::xml('cez-45274649-ws2-empty.xml');
    }

    private static function sentXml(MockResponse $response): \SimpleXMLElement
    {
        $body = $response->getRequestOptions()['body'];
        self::assertIsString($body);
        $xml = simplexml_load_string($body);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        $xml->registerXPathNamespace('s', self::SOAP_NS);
        $xml->registerXPathNamespace('t', self::TYPES_NS);

        return $xml;
    }

    /**
     * @return list<\SimpleXMLElement> the children of the request element
     */
    private static function requestChildren(MockResponse $response, string $predicate = ''): array
    {
        $nodes = self::sentXml($response)->xpath('/s:Envelope/s:Body/t:getIsirWsCuzkDataRequest/*'.$predicate);
        self::assertIsArray($nodes);

        return array_values($nodes);
    }

    // --- request ---

    public function testClientIsAnInsolvencyRegister(): void
    {
        $interfaces = class_implements(InsolvencyClient::class);

        self::assertIsArray($interfaces);
        self::assertContains(InsolvencyRegister::class, $interfaces);
    }

    public function testFindPostsToTheIsirEndpoint(): void
    {
        $response = self::emptyAnswer();

        new InsolvencyClient(new MockHttpClient($response))->find('25083325');

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://isir.justice.cz:8443/isir_cuzk_ws/IsirWsCuzkService', $response->getRequestUrl());
    }

    public function testClientPostsToTheConfiguredEndpoint(): void
    {
        $response = self::emptyAnswer();

        new InsolvencyClient(new MockHttpClient($response), 'https://isir.example/soap')->find('25083325');

        self::assertSame('https://isir.example/soap', $response->getRequestUrl());
    }

    public function testRequestCarriesTheContentTypeAndAnEmptySoapAction(): void
    {
        $response = self::emptyAnswer();

        new InsolvencyClient(new MockHttpClient($response))->find('25083325');

        $headers = $response->getRequestOptions()['headers'];
        self::assertIsArray($headers);
        self::assertContains('Content-Type: text/xml; charset=utf-8', $headers);
        self::assertContains('SOAPAction: ""', $headers);
    }

    public function testRequestEnvelopeHasTheTypedRootAndUnqualifiedChildrenInTheOrderTheServiceRequires(): void
    {
        $response = self::emptyAnswer();

        new InsolvencyClient(new MockHttpClient($response))->find('25083325');

        $children = self::requestChildren($response);
        self::assertSame(
            ['ic', 'maxPocetVysledku', 'filtrAktualniRizeni'],
            array_map(static fn (\SimpleXMLElement $child): string => $child->getName(), $children),
        );
        self::assertSame(['25083325', '101', 'F'], array_map(static fn (\SimpleXMLElement $child): string => (string) $child, $children));
        self::assertCount(3, self::requestChildren($response, "[namespace-uri() = '']"));
    }

    public function testRequestsSendTheConfiguredTimeoutAsTimeoutAndMaxDuration(): void
    {
        $response = self::emptyAnswer();

        new InsolvencyClient(new MockHttpClient($response), timeout: 2.5)->find('25083325');

        self::assertSame(2.5, $response->getRequestOptions()['timeout']);
        self::assertSame(2.5, $response->getRequestOptions()['max_duration']);
    }

    public function testDefaultTimeoutIsTenSeconds(): void
    {
        $response = self::emptyAnswer();

        new InsolvencyClient(new MockHttpClient($response))->find('25083325');

        self::assertSame(10.0, $response->getRequestOptions()['timeout']);
        self::assertSame(10.0, $response->getRequestOptions()['max_duration']);
    }

    /**
     * @return iterable<string, array{string|CompanyId, string}>
     */
    public static function provideCompanyIdForms(): iterable
    {
        yield 'canonical' => ['25083325', '25083325'];
        yield 'without leading zeros' => ['121100', '00121100'];
        yield 'with spaces' => [' 250 833 25 ', '25083325'];
        yield 'parsed id' => [CompanyId::parse('45274649'), '45274649'];
        yield 'register id that fails the check digit is taken as it is' => [CompanyId::fromRegister('12345678'), '12345678'];
        yield 'short register id is padded' => [CompanyId::fromRegister('121100'), '00121100'];
    }

    #[DataProvider('provideCompanyIdForms')]
    public function testRequestCarriesTheCompanyIdAsEightDigits(string|CompanyId $id, string $expected): void
    {
        $response = self::emptyAnswer();

        new InsolvencyClient(new MockHttpClient($response))->find($id);

        self::assertSame($expected, (string) self::requestChildren($response)[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectedCompanyIds(): iterable
    {
        yield 'check digit does not match' => ['12345678'];
        yield 'letters' => ['abc'];
        yield 'empty' => [''];
        yield 'too long' => ['123456789'];
        yield 'markup' => ['</ic><x/>'];
    }

    #[DataProvider('provideRejectedCompanyIds')]
    public function testFindRejectsAnUnusableCompanyIdWithoutAnyRequest(string $id): void
    {
        $httpClient = new MockHttpClient(self::emptyAnswer());

        try {
            new InsolvencyClient($httpClient)->find($id);
            self::fail('Expected InvalidInput was not thrown.');
        } catch (InvalidInput) {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    // --- answers ---

    public function testFindReturnsTheOngoingProceedingOfSberbank(): void
    {
        $proceedings = new InsolvencyClient(new MockHttpClient(self::xml('sberbank-25083325-konkurs-ongoing.xml')))->find('25083325');

        self::assertCount(1, $proceedings);
        self::assertSame('95 INS 12575/2022', $proceedings->proceedings[0]->reference());
        self::assertTrue($proceedings->proceedings[0]->isOngoing());
        self::assertTrue($proceedings->hasOngoing());
        self::assertSame('2026-10-08T09:26:35+02:00', $proceedings->synchronisedAt?->format('c'));
    }

    public function testFindReturnsTheEndedProceedingOfCeskeAerolinie(): void
    {
        $proceedings = new InsolvencyClient(new MockHttpClient(self::xml('aerolinie-45795908-ended.xml')))->find('45795908');

        self::assertCount(1, $proceedings);
        self::assertFalse($proceedings->proceedings[0]->isOngoing());
        self::assertSame('2022-07-01', $proceedings->proceedings[0]->endedOn?->format('Y-m-d'));
        self::assertFalse($proceedings->hasOngoing());
    }

    public function testFindReturnsAnEmptyCollectionWhenTheRegisterListsNothing(): void
    {
        $proceedings = new InsolvencyClient(new MockHttpClient(self::emptyAnswer()))->find('45274649');

        self::assertCount(0, $proceedings);
        self::assertFalse($proceedings->hasOngoing());
    }

    public function testFindReturnsBothProceedingsOfSpouses(): void
    {
        $proceedings = new InsolvencyClient(new MockHttpClient(self::xml('spouses-two-rows-odskrtnuta.xml')))->find('25083325');

        self::assertCount(2, $proceedings);
    }

    public function testFindRejectsAnAnswerFilledBeyondTheRequestedMaximumAsIncomplete(): void
    {
        $xml = FixtureLoader::read('Isir/sberbank-25083325-konkurs-ongoing.xml');
        self::assertSame(1, preg_match('~<data>.*</data>~s', $xml, $row));
        $xml = str_replace([$row[0], '<pocetVysledku>1<'], [str_repeat($row[0], 101), '<pocetVysledku>101<'], $xml);

        $this->expectException(InvalidResponse::class);

        new InsolvencyClient(new MockHttpClient(new MockResponse($xml)))->find('25083325');
    }

    // --- failures ---

    public function testHttpErrorIsServiceUnavailableFromIsirMentioningTheStatusAndTheCompanyIdButNotTheBody(): void
    {
        $client = new InsolvencyClient(new MockHttpClient(new MockResponse('SENTINEL-BODY', ['http_code' => 500])));

        try {
            $client->find('25083325');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Isir, $e->source);
            self::assertNull($e->errorCode);
            self::assertStringContainsString('500', $e->getMessage());
            self::assertStringContainsString('25083325', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL-BODY', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRecordedFaults(): iterable
    {
        yield 'element out of order' => ['fault-http500-element-order.xml'];
        yield 'value outside the enumeration' => ['fault-http500-filter-value.xml'];
    }

    #[DataProvider('provideRecordedFaults')]
    public function testSoapFaultWithHttpErrorIsServiceUnavailableCarryingTheFaultCode(string $fixture): void
    {
        $client = new InsolvencyClient(new MockHttpClient(self::xml($fixture, 500)));

        try {
            $client->find('25083325');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Isir, $e->source);
            self::assertSame('soap:Client', $e->errorCode);
            self::assertStringContainsString('500', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testSoapFaultWithAnotherHttpErrorStatusKeepsTheFaultCode(): void
    {
        $client = new InsolvencyClient(new MockHttpClient(self::xml('fault-http500-element-order.xml', 503)));

        try {
            $client->find('25083325');
        } catch (ServiceUnavailable $e) {
            self::assertSame('soap:Client', $e->errorCode);
            self::assertStringContainsString('503', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testSoapFaultWithHttpOkIsServiceUnavailableCarryingTheFaultCode(): void
    {
        $client = new InsolvencyClient(new MockHttpClient(self::xml('fault-http500-element-order.xml')));

        try {
            $client->find('25083325');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Isir, $e->source);
            self::assertSame('soap:Client', $e->errorCode);

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
        yield 'truncated envelope' => ['<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Cli'];
        yield 'fault without a fault code' => ['<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultstring>SENTINEL-BODY</faultstring></soap:Fault></soap:Body></soap:Envelope>'];
    }

    #[DataProvider('provideHttpErrorBodiesWithoutAFault')]
    public function testHttpErrorWhoseBodyCarriesNoFaultCodeIsServiceUnavailableWithoutAnErrorCode(string $body): void
    {
        $client = new InsolvencyClient(new MockHttpClient(new MockResponse($body, ['http_code' => 500])));

        try {
            $client->find('25083325');
        } catch (ServiceUnavailable $e) {
            self::assertNull($e->errorCode);
            self::assertStringContainsString('500', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testHttpErrorWithAnAnswerBodyIsServiceUnavailableWithoutAnErrorCode(): void
    {
        $client = new InsolvencyClient(new MockHttpClient(self::xml('sberbank-25083325-konkurs-ongoing.xml', 500)));

        try {
            $client->find('25083325');
        } catch (ServiceUnavailable $e) {
            self::assertNull($e->errorCode);
            self::assertStringContainsString('500', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testTransportErrorIsServiceUnavailableFromIsir(): void
    {
        $client = new InsolvencyClient(new MockHttpClient(new MockResponse(info: ['error' => 'host unreachable'])));

        try {
            $client->find('25083325');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Isir, $e->source);
            self::assertNotNull($e->getPrevious());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testIdleTimeoutIsServiceUnavailableFromIsir(): void
    {
        $silent = new MockResponse((static function (): \Generator {
            yield '';
        })(), ['http_code' => 200]);
        $client = new InsolvencyClient(new MockHttpClient($silent), timeout: 0.1);

        try {
            $client->find('25083325');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Isir, $e->source);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testOutageStateOfTheRegisterIsServiceUnavailableFromIsirCarryingTheCode(): void
    {
        $client = new InsolvencyClient(new MockHttpClient(self::xml('status-ws4.xml')));

        try {
            $client->find('25083325');
        } catch (ServiceUnavailable $e) {
            self::assertSame(Source::Isir, $e->source);
            self::assertSame('WS4', $e->errorCode);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testStateThatCannotHappenForAValidCompanyIdIsInvalidResponseFromIsir(): void
    {
        $client = new InsolvencyClient(new MockHttpClient(self::xml('ws1-empty-request.xml')));

        try {
            $client->find('25083325');
        } catch (InvalidResponse $e) {
            self::assertSame(Source::Isir, $e->source);

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    public function testResponseThatIsNotXmlIsInvalidResponseFromIsir(): void
    {
        $client = new InsolvencyClient(new MockHttpClient(self::xml('invalid.xml')));

        try {
            $client->find('25083325');
        } catch (InvalidResponse $e) {
            self::assertSame(Source::Isir, $e->source);

            return;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    // --- no response text in messages ---

    /**
     * @return iterable<string, array{string, int, class-string<\Throwable>}> body, HTTP status, expected exception
     */
    public static function provideResponsesCarryingSentinels(): iterable
    {
        $empty = FixtureLoader::read('Isir/cez-45274649-ws2-empty.xml');
        $planted = static fn (string $code): string => str_replace(
            ['WS2', 'Prázdný výsledek', 'Zadaným kritériím neodpovídají žádné záznamy'],
            [$code, 'SENTINEL-TEXT', 'SENTINEL-DESCRIPTION'],
            $empty,
        );
        $fault = str_replace('Data nejsou validní', 'SENTINEL-FAULTSTRING', FixtureLoader::read('Isir/fault-http500-element-order.xml'));
        $row = FixtureLoader::read('Isir/sberbank-25083325-konkurs-ongoing.xml');
        $namedBySentinel = str_replace('Sberbank CZ, a.s. v likvidaci', 'SENTINEL-NAME', $row);

        yield 'error text of an outage state' => [$planted('WS4'), 200, ServiceUnavailable::class];
        yield 'error text of an impossible state' => [$planted('WS1'), 200, InvalidResponse::class];
        yield 'fault string with HTTP error' => [$fault, 500, ServiceUnavailable::class];
        yield 'fault string with HTTP ok' => [$fault, 200, ServiceUnavailable::class];
        yield 'debtor name beside a missing mandatory element' => [str_replace('<rocnik>2022</rocnik>', '', $namedBySentinel), 200, InvalidResponse::class];
        yield 'debtor name beside an unreadable date' => [str_replace('2022-09-13Z', '2022-13-45Z', $namedBySentinel), 200, InvalidResponse::class];
        yield 'debtor name beside an unreadable flag' => [str_replace('<dalsiDluznikVRizeni>F<', '<dalsiDluznikVRizeni>Q<', $namedBySentinel), 200, InvalidResponse::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('provideResponsesCarryingSentinels')]
    public function testResponseTextNeverReachesAnExceptionMessage(string $body, int $status, string $expected): void
    {
        self::assertStringContainsString('SENTINEL', $body);
        $client = new InsolvencyClient(new MockHttpClient(new MockResponse($body, ['http_code' => $status])));

        try {
            $client->find('25083325');
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e);
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());

            return;
        }

        self::fail('Expected '.$expected.' was not thrown.');
    }

    public function testFaultCodeThatIsFreeTextNeverReachesTheMessage(): void
    {
        $body = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>SENTINEL FAULT TEXT</faultcode><faultstring>x</faultstring></soap:Fault></soap:Body></soap:Envelope>';
        $client = new InsolvencyClient(new MockHttpClient(new MockResponse($body, ['http_code' => 500])));

        try {
            $client->find('25083325');
        } catch (ServiceUnavailable $e) {
            self::assertStringContainsString('500', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }
}
