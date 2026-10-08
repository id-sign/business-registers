<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Isir\Internal;

use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Isir\InsolvencyProceeding;
use IdSign\BusinessRegisters\Isir\InsolvencyProceedings;
use IdSign\BusinessRegisters\Isir\Internal\ResponseParser;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\FixtureLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseParser::class)]
final class ResponseParserTest extends TestCase
{
    private const string RESPONSE_PATH = 's:Body/ns2:getIsirWsCuzkDataResponse';

    private static function parse(string $fixture): InsolvencyProceedings
    {
        return ResponseParser::parseProceedings(FixtureLoader::read('Isir/'.$fixture));
    }

    private static function single(string $fixture): InsolvencyProceeding
    {
        $proceedings = self::parse($fixture);

        self::assertCount(1, $proceedings);

        return $proceedings->proceedings[0];
    }

    /**
     * A recorded fixture with one planted change; the search text must be there, or the test would pin nothing.
     */
    private static function changed(string $fixture, string $search, string $replacement): string
    {
        $xml = FixtureLoader::read('Isir/'.$fixture);
        self::assertStringContainsString($search, $xml);

        return str_replace($search, $replacement, $xml);
    }

    /**
     * Removes the element from the second row of a two-row fixture only.
     */
    private static function withoutInSecondRow(string $fixture, string $element): string
    {
        $xml = FixtureLoader::read('Isir/'.$fixture);
        $first = strpos($xml, '<data>');
        self::assertNotFalse($first);
        $second = strpos($xml, '<data>', $first + 1);
        self::assertNotFalse($second);

        $tail = preg_replace('~<'.$element.'>[^<]*</'.$element.'>~', '', substr($xml, $second), 1);
        self::assertIsString($tail);
        self::assertNotSame(substr($xml, $second), $tail);

        return substr($xml, 0, $second).$tail;
    }

    private static function invalidResponseFor(string $xml): InvalidResponse
    {
        try {
            ResponseParser::parseProceedings($xml);
        } catch (InvalidResponse $e) {
            return $e;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    private static function unavailableFor(string $xml): ServiceUnavailable
    {
        try {
            ResponseParser::parseProceedings($xml);
        } catch (ServiceUnavailable $e) {
            return $e;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    private static function prague(?\DateTimeImmutable $date): ?string
    {
        if (null === $date) {
            return null;
        }

        self::assertSame('Europe/Prague', $date->getTimezone()->getName());

        return $date->format('c');
    }

    // --- recorded responses ---

    public function testOngoingBankruptcyOfSberbankIsMappedFieldByField(): void
    {
        $proceedings = self::parse('sberbank-25083325-konkurs-ongoing.xml');

        self::assertCount(1, $proceedings);
        $proceeding = $proceedings->proceedings[0];
        self::assertSame('95 INS 12575/2022', $proceeding->reference());
        self::assertTrue($proceeding->isOngoing());
        self::assertSame('25083325', $proceeding->companyId?->value);
        self::assertNull($proceeding->birthNumber);
        self::assertSame(95, $proceeding->senate);
        self::assertSame('INS', $proceeding->caseType);
        self::assertSame(12575, $proceeding->caseNumber);
        self::assertSame(2022, $proceeding->year);
        self::assertSame('Městský soud v Praze', $proceeding->court);
        self::assertNull($proceeding->bornOn);
        self::assertNull($proceeding->titleBefore);
        self::assertNull($proceeding->titleAfter);
        self::assertNull($proceeding->firstName);
        self::assertSame('Sberbank CZ, a.s. v likvidaci', $proceeding->name);
        self::assertSame('SÍDLO FY', $proceeding->addressKind);
        self::assertSame('KONKURS', $proceeding->stateCode);
        self::assertSame('https://isir.justice.cz/isir/ueu/evidence_upadcu_detail.do?id=E45E37BF17DBE631E05333F21FAC736E', $proceeding->detailUrl);
        self::assertFalse($proceeding->otherDebtorInProceeding);
        self::assertSame('2022-09-13T00:00:00+02:00', self::prague($proceeding->insolvencyDeclaredOn));
        self::assertNull($proceeding->endedOn);
        self::assertSame('2026-10-08T09:26:35+02:00', self::prague($proceedings->synchronisedAt));
    }

    public function testAddressOfTheRecordedRowUsesTheCommonAddressFieldsAndHasNoSpaceInThePostalCode(): void
    {
        $address = self::single('sberbank-25083325-konkurs-ongoing.xml')->address;

        self::assertNotNull($address);
        self::assertSame('U Trezorky', $address->streetName);
        self::assertSame('921/2', $address->houseNumber);
        self::assertSame('Praha 5', $address->city);
        self::assertSame('15800', $address->postalCode);
        self::assertNull($address->county);
        self::assertNull($address->countryName);
    }

    public function testEndedProceedingOfCeskeAerolinieIsNotOngoing(): void
    {
        $proceedings = self::parse('aerolinie-45795908-ended.xml');
        $proceeding = $proceedings->proceedings[0];

        self::assertCount(1, $proceedings);
        self::assertSame('92 INS 3628/2021', $proceeding->reference());
        self::assertSame('ÚPADEK', $proceeding->stateCode);
        self::assertSame('2021-03-10T00:00:00+01:00', self::prague($proceeding->insolvencyDeclaredOn));
        self::assertSame('2022-07-01T00:00:00+02:00', self::prague($proceeding->endedOn));
        self::assertFalse($proceeding->isOngoing());
        self::assertFalse($proceedings->hasOngoing());
        self::assertNotNull($proceeding->address);
        self::assertSame('Česká republika', $proceeding->address->countryName);
        self::assertSame('16100', $proceeding->address->postalCode);
    }

    public function testProceedingWithoutAnyDateIsMapped(): void
    {
        $proceeding = self::single('lidru-00121100-konkurs-no-dates.xml');

        self::assertSame('15 INS 27377/2013', $proceeding->reference());
        self::assertSame('00121100', $proceeding->companyId?->value);
        self::assertSame('čp.153', $proceeding->address?->houseNumber);
        self::assertNull($proceeding->insolvencyDeclaredOn);
        self::assertNull($proceeding->endedOn);
        self::assertTrue($proceeding->isOngoing());
    }

    public function testReorganisationIsOngoing(): void
    {
        $proceeding = self::single('okd-26863154-reorganiz.xml');

        self::assertSame('REORGANIZ', $proceeding->stateCode);
        self::assertSame('26863154', $proceeding->companyId?->value);
        self::assertTrue($proceeding->isOngoing());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnswersWithData(): iterable
    {
        yield 'bankruptcy' => ['sberbank-25083325-konkurs-ongoing.xml'];
        yield 'ended' => ['aerolinie-45795908-ended.xml'];
        yield 'no dates' => ['lidru-00121100-konkurs-no-dates.xml'];
        yield 'reorganisation' => ['okd-26863154-reorganiz.xml'];
        yield 'natural person' => ['natural-person-oddluzeni.xml'];
        yield 'petition' => ['nevyrizena-petition-pending.xml'];
        yield 'struck out' => ['odskrtnuta-no-dates.xml'];
        yield 'spouses' => ['spouses-two-rows-odskrtnuta.xml'];
        yield 'titles' => ['titles-county-country.xml'];
    }

    #[DataProvider('provideAnswersWithData')]
    public function testSynchronisationTimeIsPragueLocalTimeWhateverTheZoneSuffix(string $fixture): void
    {
        $proceedings = self::parse($fixture);

        self::assertSame('2026-10-08T09:26:35+02:00', self::prague($proceedings->synchronisedAt));
    }

    public function testEmptyResultHasNoProceedingsAndNoSynchronisationTime(): void
    {
        $proceedings = self::parse('cez-45274649-ws2-empty.xml');

        self::assertCount(0, $proceedings);
        self::assertFalse($proceedings->hasOngoing());
        self::assertNull($proceedings->synchronisedAt);
    }

    public function testNaturalPersonKeepsTheBirthNumberAsReceivedAndReadsTheBirthDate(): void
    {
        $proceeding = self::single('natural-person-oddluzeni.xml');

        self::assertSame('XXXXXX/XXXX', $proceeding->birthNumber);
        self::assertSame('1980-01-01T00:00:00+01:00', self::prague($proceeding->bornOn));
        self::assertSame('Jana', $proceeding->firstName);
        self::assertSame('Nováková', $proceeding->name);
        self::assertSame('00000000', $proceeding->companyId?->value);
        self::assertSame('ODDLUŽENÍ', $proceeding->stateCode);
        self::assertSame('2026-06-29T00:00:00+02:00', self::prague($proceeding->insolvencyDeclaredOn));
        self::assertTrue($proceeding->isOngoing());
        self::assertSame('Vzorov', $proceeding->address?->city);
    }

    public function testPetitionOfAPersonWithoutCompanyIdIsOngoing(): void
    {
        $proceeding = self::single('nevyrizena-petition-pending.xml');

        self::assertNull($proceeding->companyId);
        self::assertSame('NEVYRIZENA', $proceeding->stateCode);
        self::assertSame('TRVALÁ', $proceeding->addressKind);
        self::assertTrue($proceeding->isOngoing());
    }

    public function testStruckOutProceedingWithoutDatesIsNotOngoingAndTheCountyIsMapped(): void
    {
        $proceeding = self::single('odskrtnuta-no-dates.xml');

        self::assertSame('ODSKRTNUTA', $proceeding->stateCode);
        self::assertNull($proceeding->endedOn);
        self::assertFalse($proceeding->isOngoing());
        self::assertSame('Kladno', $proceeding->address?->county);
    }

    public function testTitlesCountyAndCountryAreMapped(): void
    {
        $proceeding = self::single('titles-county-country.xml');

        self::assertSame('Ing.', $proceeding->titleBefore);
        self::assertSame('Ph.D.', $proceeding->titleAfter);
        self::assertSame('Jan', $proceeding->firstName);
        self::assertSame('Vzorový', $proceeding->name);
        self::assertNotNull($proceeding->address);
        self::assertSame('Praha-západ', $proceeding->address->county);
        self::assertSame('Česká republika', $proceeding->address->countryName);
    }

    public function testTwoDataElementsYieldTwoProceedingsInResponseOrder(): void
    {
        $xml = preg_replace('~<bcVec>99998</bcVec>~', '<bcVec>1</bcVec>', FixtureLoader::read('Isir/spouses-two-rows-odskrtnuta.xml'), 1);
        self::assertIsString($xml);

        $proceedings = ResponseParser::parseProceedings($xml);

        self::assertCount(2, $proceedings);
        self::assertSame([1, 99998], array_map(static fn (InsolvencyProceeding $p): int => $p->caseNumber, $proceedings->proceedings));
        self::assertTrue($proceedings->proceedings[0]->otherDebtorInProceeding);
        self::assertTrue($proceedings->proceedings[1]->otherDebtorInProceeding);
        self::assertSame('2022-05-02T00:00:00+02:00', self::prague($proceedings->proceedings[1]->endedOn));
        self::assertFalse($proceedings->hasOngoing());
    }

    public function testPrefixesOfTheDocumentDoNotMatter(): void
    {
        $xml = strtr(FixtureLoader::read('Isir/sberbank-25083325-konkurs-ongoing.xml'), [
            'xmlns:soap' => 'xmlns:env',
            '<soap:' => '<env:',
            '</soap:' => '</env:',
            'xmlns:ns2' => 'xmlns:tns',
            '<ns2:' => '<tns:',
            '</ns2:' => '</tns:',
        ]);

        $proceedings = ResponseParser::parseProceedings($xml);

        self::assertSame('95 INS 12575/2022', $proceedings->proceedings[0]->reference());
        self::assertSame('2026-10-08T09:26:35+02:00', self::prague($proceedings->synchronisedAt));
    }

    // --- fields ---

    public function testAddressIsNullWhenNoAddressFieldIsPresentButTheKindIsKept(): void
    {
        $xml = FixtureLoader::read('Isir/nevyrizena-petition-pending.xml');
        foreach (['mesto', 'ulice', 'cisloPopisne', 'okres', 'zeme', 'psc'] as $element) {
            $xml = preg_replace('~<'.$element.'>[^<]*</'.$element.'>~', '', $xml);
            self::assertIsString($xml);
        }

        $proceeding = ResponseParser::parseProceedings($xml)->proceedings[0];

        self::assertNull($proceeding->address);
        self::assertSame('TRVALÁ', $proceeding->addressKind);
    }

    public function testAddressWithOnlyACityIsStillAnAddress(): void
    {
        $xml = FixtureLoader::read('Isir/nevyrizena-petition-pending.xml');
        foreach (['ulice', 'cisloPopisne', 'psc'] as $element) {
            $xml = preg_replace('~<'.$element.'>[^<]*</'.$element.'>~', '', $xml);
            self::assertIsString($xml);
        }

        $address = ResponseParser::parseProceedings($xml)->proceedings[0]->address;

        self::assertNotNull($address);
        self::assertSame('Vzorov', $address->city);
        self::assertNull($address->postalCode);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideOtherDebtorFlags(): iterable
    {
        yield 'T is true' => ['T', true];
        yield 'F is false' => ['F', false];
    }

    #[DataProvider('provideOtherDebtorFlags')]
    public function testOtherDebtorFlagIsReadFromTheFlagLetter(string $letter, bool $expected): void
    {
        $xml = self::changed('sberbank-25083325-konkurs-ongoing.xml', '<dalsiDluznikVRizeni>F<', '<dalsiDluznikVRizeni>'.$letter.'<');

        self::assertSame($expected, ResponseParser::parseProceedings($xml)->proceedings[0]->otherDebtorInProceeding);
    }

    public function testAbsentOtherDebtorFlagIsFalse(): void
    {
        $xml = self::changed('sberbank-25083325-konkurs-ongoing.xml', '<dalsiDluznikVRizeni>F</dalsiDluznikVRizeni>', '');

        self::assertFalse(ResponseParser::parseProceedings($xml)->proceedings[0]->otherDebtorInProceeding);
    }

    public function testOtherDebtorFlagOutsideTAndFIsInvalidResponseWithItsPath(): void
    {
        $e = self::invalidResponseFor(FixtureLoader::read('Isir/other-debtor-x.xml'));

        self::assertSame(Source::Isir, $e->source);
        self::assertStringEndsWith(self::RESPONSE_PATH.'/data[1]/dalsiDluznikVRizeni', $e->getMessage());
    }

    public function testLowerCaseFlagLetterIsInvalidResponse(): void
    {
        $xml = self::changed('sberbank-25083325-konkurs-ongoing.xml', '<dalsiDluznikVRizeni>F<', '<dalsiDluznikVRizeni>t<');

        $e = self::invalidResponseFor($xml);

        self::assertStringEndsWith('/data[1]/dalsiDluznikVRizeni', $e->getMessage());
    }

    // --- mandatory elements and unreadable values (key paths) ---

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMandatoryRowElements(): iterable
    {
        yield 'senate' => ['cisloSenatu'];
        yield 'case type' => ['druhVec'];
        yield 'case number' => ['bcVec'];
        yield 'year' => ['rocnik'];
    }

    #[DataProvider('provideMandatoryRowElements')]
    public function testMissingMandatoryRowElementIsInvalidResponseNamingTheRowAndTheElement(string $element): void
    {
        $e = self::invalidResponseFor(self::withoutInSecondRow('spouses-two-rows-odskrtnuta.xml', $element));

        self::assertSame('ISIR: missing '.self::RESPONSE_PATH.'/data[2]/'.$element, $e->getMessage());
        self::assertSame(Source::Isir, $e->source);
    }

    public function testNonNumericSenateIsInvalidResponseWithItsPath(): void
    {
        $xml = self::changed('sberbank-25083325-konkurs-ongoing.xml', '<cisloSenatu>95<', '<cisloSenatu>SENTINEL<');

        $e = self::invalidResponseFor($xml);

        self::assertSame('ISIR: expected integer at '.self::RESPONSE_PATH.'/data[1]/cisloSenatu', $e->getMessage());
    }

    public function testUnreadableDateIsInvalidResponseWithItsPath(): void
    {
        $e = self::invalidResponseFor(FixtureLoader::read('Isir/invalid-date.xml'));

        self::assertSame('ISIR: expected date (Y-m-d) at '.self::RESPONSE_PATH.'/data[1]/datumPmZahajeniUpadku', $e->getMessage());
        self::assertSame(Source::Isir, $e->source);
    }

    public function testUnreadableBirthDateIsInvalidResponseWithItsPath(): void
    {
        $xml = self::changed('natural-person-oddluzeni.xml', '<datumNarozeni>1980-01-01Z<', '<datumNarozeni>1980-02-30Z<');

        $e = self::invalidResponseFor($xml);

        self::assertSame('ISIR: expected date (Y-m-d) at '.self::RESPONSE_PATH.'/data[1]/datumNarozeni', $e->getMessage());
    }

    public function testUnreadableSynchronisationTimeIsInvalidResponseWithItsPath(): void
    {
        $xml = self::changed('sberbank-25083325-konkurs-ongoing.xml', '2026-10-08T09:26:35.000Z', 'yesterday');

        $e = self::invalidResponseFor($xml);

        self::assertSame('ISIR: expected date-time at '.self::RESPONSE_PATH.'/stav/casSynchronizace', $e->getMessage());
    }

    public function testCompanyIdTheLibraryCannotReadIsInvalidResponseWithItsPath(): void
    {
        $xml = self::changed('sberbank-25083325-konkurs-ongoing.xml', '<ic>25083325</ic>', '<ic>SENTINEL</ic>');

        $e = self::invalidResponseFor($xml);

        self::assertStringEndsWith(self::RESPONSE_PATH.'/data[1]/ic', $e->getMessage());
        self::assertStringNotContainsString('SENTINEL', $e->getMessage());
    }

    public function testCompanyIdWithAnInvalidCheckDigitIsAccepted(): void
    {
        $xml = self::changed('sberbank-25083325-konkurs-ongoing.xml', '<ic>25083325</ic>', '<ic>12345678</ic>');

        self::assertSame('12345678', ResponseParser::parseProceedings($xml)->proceedings[0]->companyId?->value);
    }

    // --- status ---

    /**
     * @return iterable<string, array{string, string, string}> fixture, error code, message
     */
    public static function provideOutageStates(): iterable
    {
        yield 'data not current' => ['status-ws4.xml', 'WS4', 'ISIR data are not current (error code WS4)'];
        yield 'database error' => ['status-sql1.xml', 'SQL1', 'ISIR database error (error code SQL1)'];
        yield 'application error' => ['status-server1.xml', 'SERVER1', 'ISIR application error (error code SERVER1)'];
    }

    #[DataProvider('provideOutageStates')]
    public function testOutageStateIsServiceUnavailableCarryingTheCode(string $fixture, string $code, string $message): void
    {
        $e = self::unavailableFor(FixtureLoader::read('Isir/'.$fixture));

        self::assertSame(Source::Isir, $e->source);
        self::assertSame($code, $e->errorCode);
        self::assertSame($message, $e->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideImpossibleStates(): iterable
    {
        yield 'WS1 wrong parameter combination' => [FixtureLoader::read('Isir/ws1-empty-request.xml')];
        yield 'WS3 short name' => [str_replace('WS2', 'WS3', FixtureLoader::read('Isir/cez-45274649-ws2-empty.xml'))];
        yield 'code the service does not define' => [FixtureLoader::read('Isir/status-unknown-code.xml')];
    }

    #[DataProvider('provideImpossibleStates')]
    public function testStateThatCannotHappenForAValidCompanyIdIsInvalidResponseNamingTheCodeElement(string $xml): void
    {
        $e = self::invalidResponseFor($xml);

        self::assertSame(Source::Isir, $e->source);
        self::assertStringEndsWith(self::RESPONSE_PATH.'/stav/kodChyby', $e->getMessage());
    }

    public function testErrorTextsAndTheUnknownCodeNeverReachAMessage(): void
    {
        $unknownCode = self::changed('status-unknown-code.xml', 'WS9', 'SENTINEL-CODE');
        $text = self::changed('ws1-empty-request.xml', 'Nesprávná kombinace parametrů', 'SENTINEL-TEXT');

        self::assertStringNotContainsString('SENTINEL', self::invalidResponseFor($unknownCode)->getMessage());
        self::assertStringNotContainsString('SENTINEL', self::invalidResponseFor($text)->getMessage());
        self::assertStringNotContainsString('SENTINEL', self::unavailableFor(self::changed('status-ws4.xml', 'Neaktuální data', 'SENTINEL-TEXT'))->getMessage());
    }

    public function testSoapFaultWithHttpOkIsServiceUnavailableCarryingTheFaultCode(): void
    {
        $e = self::unavailableFor(FixtureLoader::read('Isir/fault-http500-element-order.xml'));

        self::assertSame(Source::Isir, $e->source);
        self::assertSame('soap:Client', $e->errorCode);
        self::assertSame('ISIR returned a SOAP Fault (error code soap:Client)', $e->getMessage());
    }

    public function testMissingStatusElementIsInvalidResponse(): void
    {
        $e = self::invalidResponseFor(FixtureLoader::read('Isir/missing-stav.xml'));

        self::assertSame('ISIR: missing '.self::RESPONSE_PATH.'/stav', $e->getMessage());
    }

    public function testMissingResponseElementIsInvalidResponse(): void
    {
        $xml = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body/></soap:Envelope>';

        $e = self::invalidResponseFor($xml);

        self::assertSame('ISIR: missing '.self::RESPONSE_PATH, $e->getMessage());
    }

    public function testResponseThatIsNotWellFormedIsInvalidResponse(): void
    {
        $e = self::invalidResponseFor(FixtureLoader::read('Isir/invalid.xml'));

        self::assertSame(Source::Isir, $e->source);
        self::assertSame('ISIR: response is not well-formed XML', $e->getMessage());
    }

    public function testCountAboveTheNumberOfDataElementsIsInvalidResponseAsATruncatedAnswer(): void
    {
        $e = self::invalidResponseFor(FixtureLoader::read('Isir/truncated-count.xml'));

        self::assertStringEndsWith(self::RESPONSE_PATH.'/stav/pocetVysledku', $e->getMessage());
    }

    public function testCountBelowTheNumberOfDataElementsIsAccepted(): void
    {
        $xml = self::changed('spouses-two-rows-odskrtnuta.xml', '<pocetVysledku>2<', '<pocetVysledku>1<');

        self::assertCount(2, ResponseParser::parseProceedings($xml));
    }

    private static function answerWithRows(int $rows): string
    {
        $xml = FixtureLoader::read('Isir/sberbank-25083325-konkurs-ongoing.xml');
        self::assertSame(1, preg_match('~<data>.*</data>~s', $xml, $row));

        return str_replace(
            [$row[0], '<pocetVysledku>1<'],
            [str_repeat($row[0], $rows), '<pocetVysledku>'.$rows.'<'],
            $xml,
        );
    }

    public function testAnswerOfOneHundredRowsIsAccepted(): void
    {
        self::assertCount(100, ResponseParser::parseProceedings(self::answerWithRows(100)));
    }

    public function testAnswerAboveOneHundredRowsIsInvalidResponseAsAnIncompleteList(): void
    {
        $e = self::invalidResponseFor(self::answerWithRows(101));

        self::assertSame(Source::Isir, $e->source);
    }

    public function testZeroCountWithoutDataIsAnEmptyCollectionWithTheSynchronisationTime(): void
    {
        $proceedings = self::parse('no-data-zero-count.xml');

        self::assertCount(0, $proceedings);
        self::assertSame('2026-10-08T09:26:35+02:00', self::prague($proceedings->synchronisedAt));
    }

    public function testMissingCountWithoutAnErrorCodeIsInvalidResponseNotAnEmptyCollection(): void
    {
        $e = self::invalidResponseFor(FixtureLoader::read('Isir/no-data-missing-count.xml'));

        self::assertSame('ISIR: missing '.self::RESPONSE_PATH.'/stav/pocetVysledku', $e->getMessage());
    }

    // --- fault code ---

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideFaultCodeBodies(): iterable
    {
        yield 'element order fault' => [FixtureLoader::read('Isir/fault-http500-element-order.xml'), 'soap:Client'];
        yield 'enumeration fault' => [FixtureLoader::read('Isir/fault-http500-filter-value.xml'), 'soap:Client'];
        yield 'fault without a fault code' => ['<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultstring>x</faultstring></soap:Fault></soap:Body></soap:Envelope>', null];
        yield 'answer without a fault' => [FixtureLoader::read('Isir/cez-45274649-ws2-empty.xml'), null];
        yield 'envelope without a body' => ['<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"/>', null];
        yield 'plain text' => ['SENTINEL-BODY', null];
        yield 'html page' => ['<html><body>SENTINEL-BODY</body></html>', null];
        yield 'empty' => ['', null];
        yield 'truncated fault' => ['<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Cli', null];
    }

    #[DataProvider('provideFaultCodeBodies')]
    public function testFaultCodeIsReadLeniently(string $body, ?string $expected): void
    {
        self::assertSame($expected, ResponseParser::faultCode($body));
    }
}
