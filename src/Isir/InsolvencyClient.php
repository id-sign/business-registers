<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Isir;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
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

    /**
     * Value of vyhledatPresnouShoduJmen: without it the service matches surname and first name as prefixes.
     */
    private const string EXACT_NAME_MATCH = 'T';

    private const string BIRTH_NUMBER_FORMAT = '~^\d{6}/?\d{3,4}$~D';

    /**
     * Valid UTF-8 with at least one letter and without the characters XML 1.0 forbids.
     */
    private const string NAME_FORMAT = '~^(?=\P{L}*\p{L})[^\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]+$~uD';

    /**
     * Values of maxRelevanceVysledku: the service reports the match kind as relevanceVysledku and answers WS2 instead
     * of falling back to a weaker kind than this.
     */
    private const int RELEVANCE_BIRTH_NUMBER = 1;
    private const int RELEVANCE_NAME_AND_BIRTH_DATE = 4;

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

        return $this->lookup(['ic' => $id->value], null, 'company id '.$id);
    }

    public function findByBirthNumber(#[\SensitiveParameter] string $birthNumber): InsolvencyProceedings
    {
        $birthNumber = trim($birthNumber);
        if (1 !== preg_match(self::BIRTH_NUMBER_FORMAT, $birthNumber)) {
            throw new InvalidInput('Birth number must be six digits, an optional slash and three or four digits.');
        }

        return $this->lookup(['rc' => $birthNumber], self::RELEVANCE_BIRTH_NUMBER, 'a birth number');
    }

    public function findByNameAndBirthDate(
        #[\SensitiveParameter] string $surname,
        #[\SensitiveParameter] string $firstName,
        #[\SensitiveParameter] \DateTimeImmutable $bornOn,
    ): InsolvencyProceedings {
        $surname = self::trimName($surname);
        $firstName = self::trimName($firstName);
        if (1 !== preg_match(self::NAME_FORMAT, $surname) || 1 !== preg_match(self::NAME_FORMAT, $firstName)) {
            throw new InvalidInput('Surname and first name must be UTF-8 text with a letter and without characters XML 1.0 forbids.');
        }
        $year = (int) $bornOn->format('Y');
        if ($year < 1 || $year > 9999) {
            throw new InvalidInput('Birth date must lie in the years 1 to 9999.');
        }

        return $this->lookup(
            ['nazevOsoby' => $surname, 'jmeno' => $firstName, 'datumNarozeni' => $bornOn->format('Y-m-d')],
            self::RELEVANCE_NAME_AND_BIRTH_DATE,
            'a person',
        );
    }

    /**
     * Strips Unicode white space, zero-width spaces and byte order marks at both ends, which trim() leaves on; invalid
     * UTF-8 becomes '' and fails NAME_FORMAT.
     */
    private static function trimName(string $name): string
    {
        return preg_replace('~^[\s\x{200B}\x{FEFF}]+|[\s\x{200B}\x{FEFF}]+$~uD', '', $name) ?? '';
    }

    /**
     * @param non-empty-array<string, string> $criteria     search elements in the order the service requires
     * @param ?int                            $maxRelevance weakest match kind the answer may report, null for any
     * @param string                          $subject      what the request is for in exception messages, never
     *                                                      personal data
     *
     * @throws ServiceUnavailable
     * @throws InvalidResponse
     */
    private function lookup(#[\SensitiveParameter] array $criteria, ?int $maxRelevance, string $subject): InsolvencyProceedings
    {
        [$status, $content] = $this->transport->exchange('POST', $this->endpoint, self::options(self::request($criteria, $maxRelevance)), $subject);

        if (200 !== $status) {
            throw new ServiceUnavailable(\sprintf('ISIR returned HTTP %d for %s', $status, $subject), Source::Isir, ResponseParser::faultCode($content));
        }

        return ResponseParser::parseProceedings($content, $maxRelevance);
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(#[\SensitiveParameter] string $envelope): array
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
     *
     * @param non-empty-array<string, string> $criteria
     */
    private static function request(#[\SensitiveParameter] array $criteria, ?int $maxRelevance): string
    {
        $elements = $criteria + [
            'maxPocetVysledku' => (string) (ResponseParser::MAX_PROCEEDINGS + 1),
            'filtrAktualniRizeni' => self::CURRENT_FILTER_OFF,
        ];
        if (isset($criteria['nazevOsoby'])) {
            $elements['vyhledatPresnouShoduJmen'] = self::EXACT_NAME_MATCH;
        }
        if (null !== $maxRelevance) {
            $elements['maxRelevanceVysledku'] = (string) $maxRelevance;
        }

        $children = '';
        foreach ($elements as $name => $value) {
            $children .= '<'.$name.'>'.htmlspecialchars($value, \ENT_XML1).'</'.$name.'>';
        }

        return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:typ="http://isirws.cca.cz/types/">'
            .'<soapenv:Body><typ:getIsirWsCuzkDataRequest>'
            .$children
            .'</typ:getIsirWsCuzkDataRequest></soapenv:Body>'
            .'</soapenv:Envelope>';
    }
}
