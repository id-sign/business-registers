<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Isir;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\HttpTransport;
use IdSign\BusinessRegisters\Internal\Identifiers;
use IdSign\BusinessRegisters\Isir\Internal\ResponseParser;
use IdSign\BusinessRegisters\Source;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of the ISIR IsirWsCuzkService SOAP service.
 */
final readonly class InsolvencyClient implements InsolvencyRegister
{
    public const string ENDPOINT = 'https://isir.justice.cz:8443/isir_cuzk_ws/IsirWsCuzkService';

    /**
     * Value of the service filter filtrAktualniRizeni ("current proceedings only"): F switches it off, because it
     * hides only some ended states, so every proceeding is requested and isOngoing() decides.
     */
    private const string CURRENT_FILTER_OFF = 'F';

    private HttpTransport $transport;

    /**
     * @param float $timeout seconds, applied as both the idle timeout and the maximum duration
     */
    public function __construct(
        HttpClientInterface $httpClient,
        private string $endpoint = self::ENDPOINT,
        float $timeout = 10.0,
    ) {
        $this->transport = new HttpTransport($httpClient, Source::Isir, $timeout);
    }

    public function find(CompanyId|string $id): InsolvencyProceedings
    {
        $id = Identifiers::companyId($id);

        [$status, $content] = $this->transport->exchange('POST', $this->endpoint, self::options(self::request($id)), 'company id '.$id);

        if (200 !== $status) {
            throw new ServiceUnavailable(\sprintf('ISIR returned HTTP %d for company id %s', $status, $id), Source::Isir, ResponseParser::faultCode($content));
        }

        return ResponseParser::parseProceedings($content);
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(string $envelope): array
    {
        return [
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '""',
            ],
            'body' => $envelope,
        ];
    }

    /**
     * The service rejects any other order of the request elements with a SOAP Fault.
     */
    private static function request(CompanyId $id): string
    {
        return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:typ="http://isirws.cca.cz/types/">'
            .'<soapenv:Body><typ:getIsirWsCuzkDataRequest>'
            .'<ic>'.htmlspecialchars($id->value, \ENT_XML1).'</ic>'
            .'<maxPocetVysledku>'.(ResponseParser::MAX_PROCEEDINGS + 1).'</maxPocetVysledku>'
            .'<filtrAktualniRizeni>'.self::CURRENT_FILTER_OFF.'</filtrAktualniRizeni>'
            .'</typ:getIsirWsCuzkDataRequest></soapenv:Body>'
            .'</soapenv:Envelope>';
    }
}
