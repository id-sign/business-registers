<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Adis;

use IdSign\BusinessRegisters\Adis\Internal\ResponseParser;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\HttpTransport;
use IdSign\BusinessRegisters\Internal\Identifiers;
use IdSign\BusinessRegisters\Internal\ListElement;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\VatId;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Client of the ADIS rozhraniCRPDPH SOAP service.
 */
final readonly class VatRegisterClient implements VatRegister
{
    /**
     * Equals the `soap:address` of the service WSDL; the textual documentation still lists the older
     * `adisrws.mfcr.cz` host.
     */
    public const string ENDPOINT = 'https://mojedane.gov.cz/dpr/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP';

    private const string ACTION_PREFIX = 'http://adis.mfcr.cz/rozhraniCRPDPH/';
    private const int BATCH_SIZE = 100;

    private const string STATUS_OPERATION = 'getStatusNespolehlivySubjektRozsirenyV2';
    private const int MAX_CONCURRENCY = 4;

    private HttpTransport $transport;

    /** @var int<1, max> */
    private int $maxConcurrency;

    /**
     * @param float $timeout        seconds, applied as both the idle timeout and the maximum duration
     * @param int   $maxConcurrency batches of one findMany() call sent at the same time, 1 to 4; the operator
     *                              allows at most 4 parallel requests per source IP
     *
     * @throws \InvalidArgumentException when `$maxConcurrency` is outside 1 to 4
     */
    public function __construct(
        HttpClientInterface $httpClient,
        private string $endpoint = self::ENDPOINT,
        float $timeout = 10.0,
        int $maxConcurrency = 4,
    ) {
        $this->transport = new HttpTransport($httpClient, Source::Adis, $timeout);
        $this->maxConcurrency = HttpTransport::concurrency($maxConcurrency, self::MAX_CONCURRENCY);
    }

    public function find(VatId|string $vatId): ?VatSubject
    {
        $vatId = Identifiers::czechVatId($vatId);

        return $this->query([$vatId->number], 'VAT id '.$vatId)[0] ?? null;
    }

    public function findMany(array $vatIds): VatSubjects
    {
        $numbers = [];
        foreach ($vatIds as $index => $vatId) {
            $numbers[] = Identifiers::czechVatId(ListElement::idOrString($vatId, $index, VatId::class))->number;
        }

        $request = 'a batch of VAT ids';
        $senders = [];
        foreach (array_chunk(array_values(array_unique($numbers)), self::BATCH_SIZE) as $batch) {
            $envelope = self::statusRequest($batch);
            $senders[] = fn (): ResponseInterface => $this->transport->send('POST', $this->endpoint, self::options(self::STATUS_OPERATION, $envelope), $request);
        }

        $subjects = [];
        $seen = [];
        $this->transport->sendInWaves($senders, $this->maxConcurrency, static function (int $status, string $content) use ($request, &$subjects, &$seen): void {
            foreach (ResponseParser::parseSubjects(self::answer($status, $content, $request)) as $subject) {
                $key = (string) $subject->vatId;
                if (isset($seen[$key])) {
                    throw new InvalidResponse('ADIS: expected each VAT id at most once in the response', Source::Adis);
                }
                $seen[$key] = true;
                $subjects[] = $subject;
            }
        });

        return new VatSubjects($subjects);
    }

    public function unreliablePayers(): array
    {
        $content = $this->send('getSeznamNespolehlivyPlatce', self::unreliablePayersRequest(), 'the list of unreliable payers');

        return ResponseParser::parseUnreliablePayers($content);
    }

    /**
     * @param list<string> $numbers VAT numbers without the country code
     *
     * @return list<VatSubject>
     *
     * @throws ServiceUnavailable
     * @throws InvalidResponse
     */
    private function query(array $numbers, string $subject): array
    {
        $content = $this->send(self::STATUS_OPERATION, self::statusRequest($numbers), $subject);

        return ResponseParser::parseSubjects($content);
    }

    /**
     * @return string body of a response with HTTP status 200
     *
     * @throws ServiceUnavailable
     */
    private function send(string $operation, string $envelope, string $subject): string
    {
        [$status, $content] = $this->transport->exchange('POST', $this->endpoint, self::options($operation, $envelope), $subject);

        return self::answer($status, $content, $subject);
    }

    /**
     * @return string the body when the status is 200
     *
     * @throws ServiceUnavailable
     */
    private static function answer(int $status, string $content, string $subject): string
    {
        if (200 !== $status) {
            throw new ServiceUnavailable(\sprintf('ADIS returned HTTP %d for %s', $status, $subject), Source::Adis, ResponseParser::faultCode($content));
        }

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(string $operation, string $envelope): array
    {
        return [
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => self::ACTION_PREFIX.$operation,
            ],
            'body' => $envelope,
        ];
    }

    /**
     * @param list<string> $numbers
     */
    private static function statusRequest(array $numbers): string
    {
        $dic = '';
        foreach ($numbers as $number) {
            $dic .= '<roz:dic>'.htmlspecialchars($number, \ENT_XML1).'</roz:dic>';
        }

        return self::envelope('<roz:StatusNespolehlivySubjektRozsirenyV2Request>'.$dic.'</roz:StatusNespolehlivySubjektRozsirenyV2Request>');
    }

    private static function unreliablePayersRequest(): string
    {
        return self::envelope('<roz:SeznamNespolehlivyPlatceRequest/>');
    }

    private static function envelope(string $body): string
    {
        return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:roz="http://adis.mfcr.cz/rozhraniCRPDPH/">'
            .'<soapenv:Body>'.$body.'</soapenv:Body>'
            .'</soapenv:Envelope>';
    }
}
