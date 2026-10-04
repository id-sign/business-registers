<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Adis;

use IdSign\BusinessRegisters\Adis\Internal\ResponseParser;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\ListElement;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\VatId;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of the ADIS rozhraniCRPDPH SOAP service.
 */
final readonly class VatRegisterClient implements VatRegister
{
    public const string ENDPOINT = 'https://mojedane.gov.cz/dpr/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP';

    private const string ACTION_PREFIX = 'http://adis.mfcr.cz/rozhraniCRPDPH/';
    private const int BATCH_SIZE = 100;

    /**
     * @param float $timeout seconds, applied as both the idle timeout and the maximum duration
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $endpoint = self::ENDPOINT,
        private float $timeout = 10.0,
    ) {
    }

    public function find(VatId|string $vatId): ?VatSubject
    {
        $vatId = self::czechVatId($vatId);

        return $this->query([$vatId->number], 'VAT id '.$vatId)[0] ?? null;
    }

    public function findMany(array $vatIds): VatSubjects
    {
        $numbers = [];
        foreach ($vatIds as $index => $vatId) {
            $numbers[] = self::czechVatId(ListElement::idOrString($vatId, $index, VatId::class))->number;
        }

        $subjects = [];
        $seen = [];
        foreach (array_chunk(array_values(array_unique($numbers)), self::BATCH_SIZE) as $batch) {
            foreach ($this->query($batch, \sprintf('a batch of %d VAT ids', \count($batch))) as $subject) {
                $key = (string) $subject->vatId;
                if (isset($seen[$key])) {
                    throw new InvalidResponse('ADIS: expected each VAT id at most once in the response', Source::Adis);
                }
                $seen[$key] = true;
                $subjects[] = $subject;
            }
        }

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
        $content = $this->send('getStatusNespolehlivySubjektRozsirenyV2', self::statusRequest($numbers), $subject);

        return ResponseParser::parseSubjects($content);
    }

    /**
     * @return string body of a response with HTTP status 200
     *
     * @throws ServiceUnavailable
     */
    private function send(string $operation, string $envelope, string $subject): string
    {
        try {
            $response = $this->httpClient->request('POST', $this->endpoint, [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => self::ACTION_PREFIX.$operation,
                ],
                'body' => $envelope,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new ServiceUnavailable(\sprintf('ADIS request for %s failed: %s', $subject, $e->getMessage()), Source::Adis, previous: $e);
        }

        if (200 !== $status) {
            throw new ServiceUnavailable(\sprintf('ADIS returned HTTP %d for %s', $status, $subject), Source::Adis);
        }

        return $content;
    }

    /**
     * @throws InvalidInput
     */
    private static function czechVatId(VatId|string $vatId): VatId
    {
        $vatId = $vatId instanceof VatId ? $vatId : VatId::parse($vatId, 'CZ');
        if (!$vatId->isCzech()) {
            throw new InvalidInput(\sprintf('VAT id %s is not Czech; the VAT register holds Czech VAT ids only.', $vatId));
        }

        return $vatId;
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
