<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Adis\Internal;

use IdSign\BusinessRegisters\Adis\Internal\ResponseParser;
use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\UnreliablePayer;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\FixtureLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseParser::class)]
final class ResponseParserTest extends TestCase
{
    private const string RESPONSE_PATH = 's:Body/r:StatusNespolehlivySubjektRozsirenyResponse';

    /**
     * @return list<VatSubject>
     */
    private static function parseFixture(string $name): array
    {
        return ResponseParser::parseSubjects(FixtureLoader::read('Adis/'.$name));
    }

    private static function subjectOf(string $fixture): VatSubject
    {
        $subjects = self::parseFixture($fixture);

        self::assertCount(1, $subjects);

        return $subjects[0];
    }

    private static function envelope(string $statusAndSubjects, string $root = 'StatusNespolehlivySubjektRozsirenyResponse'): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
            .'<'.$root.' xmlns="http://adis.mfcr.cz/rozhraniCRPDPH/">'.$statusAndSubjects.'</'.$root.'>'
            .'</soapenv:Body></soapenv:Envelope>';
    }

    private static function okStatus(): string
    {
        return '<status odpovedGenerovana="2026-10-03" statusCode="0" statusText="OK"/>';
    }

    private static function invalidResponseFor(string $xml): InvalidResponse
    {
        try {
            ResponseParser::parseSubjects($xml);
        } catch (InvalidResponse $e) {
            return $e;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    private static function unavailableFor(string $xml): ServiceUnavailable
    {
        try {
            ResponseParser::parseSubjects($xml);
        } catch (ServiceUnavailable $e) {
            return $e;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    // --- recorded response with mixed subjects ---

    public function testNotFoundSubjectsAreSkippedAndTheOthersKeepResponseOrder(): void
    {
        $subjects = self::parseFixture('status-mixed.xml');

        self::assertSame(
            ['CZ45274649', 'CZ00121100', 'CZ699001182', 'CZ00101494'],
            array_map(static fn (VatSubject $subject): string => (string) $subject->vatId, $subjects),
        );
        self::assertSame(
            [SubjectType::VatPayer, SubjectType::VatPayer, SubjectType::VatGroup, SubjectType::IdentifiedPerson],
            array_map(static fn (VatSubject $subject): SubjectType => $subject->type, $subjects),
        );
    }

    public function testVatPayerIsMappedWithTrimmedTextsAndAddress(): void
    {
        $subject = self::parseFixture('status-mixed.xml')[0];

        self::assertFalse($subject->unreliable);
        self::assertNull($subject->unreliableSince);
        self::assertSame('ČEZ, A. S.', $subject->name);
        self::assertNotNull($subject->address);
        self::assertSame('Duhová 1444/2', $subject->address->street);
        self::assertSame('MICHLE (PRAHA 4)', $subject->address->district);
        self::assertSame('PRAHA 4', $subject->address->city);
        self::assertSame('14000', $subject->address->postalCode);
        self::assertSame('Česká republika', $subject->address->countryName);
        self::assertNull($subject->address->text);
        self::assertNull($subject->address->streetName);
        self::assertNull($subject->address->houseNumber);
        self::assertNull($subject->address->countryCode);
    }

    public function testCheckedAtIsTheDateTheResponseWasGenerated(): void
    {
        foreach (self::parseFixture('status-mixed.xml') as $subject) {
            self::assertSame('2026-10-03', $subject->checkedAt->format('Y-m-d'));
            self::assertSame('Europe/Prague', $subject->checkedAt->getTimezone()->getName());
        }
    }

    public function testTaxOfficeCodeIsPaddedToThreeDigits(): void
    {
        $codes = array_map(
            static fn (VatSubject $subject): ?string => $subject->taxOfficeCode,
            self::parseFixture('status-mixed.xml'),
        );

        self::assertSame(['013', '456', '013', '461'], $codes);
    }

    public function testSingleDigitTaxOfficeCodeIsPaddedWithTwoZeros(): void
    {
        self::assertSame('007', self::subjectOf('status-unreliable-person.xml')->taxOfficeCode);
    }

    public function testStandardAndNonStandardAccountsAreAllMapped(): void
    {
        $accounts = self::parseFixture('status-mixed.xml')[0]->bankAccounts;

        self::assertSame(
            ['71504011/0100', '2001260209/2600', 'CZ6426000000002001268200', '27-5868650297/0100', 'CZ5803000000000017640143', '19-2808601/0100'],
            array_map(static fn ($account): string => (string) $account, $accounts),
        );
    }

    public function testStandardAccountKeepsPrefixNumberAndBankCodeSeparately(): void
    {
        $account = self::parseFixture('status-mixed.xml')[0]->bankAccounts[3];

        self::assertSame('27', $account->prefix);
        self::assertSame('5868650297', $account->number);
        self::assertSame('0100', $account->bankCode);
        self::assertTrue($account->isStandard());
    }

    public function testStandardAccountWithoutPrefixHasNullPrefix(): void
    {
        $account = self::parseFixture('status-mixed.xml')[0]->bankAccounts[0];

        self::assertNull($account->prefix);
        self::assertSame('71504011', $account->number);
    }

    public function testNonStandardAccountKeepsTheWholeNumberAndHasNoBankCode(): void
    {
        $account = self::parseFixture('status-mixed.xml')[0]->bankAccounts[2];

        self::assertNull($account->prefix);
        self::assertNull($account->bankCode);
        self::assertSame('CZ6426000000002001268200', $account->number);
        self::assertFalse($account->isStandard());
    }

    public function testPublicationDateOfAnAccountIsMapped(): void
    {
        $accounts = self::parseFixture('status-mixed.xml')[0]->bankAccounts;

        self::assertSame('2013-04-01', $accounts[0]->publishedFrom->format('Y-m-d'));
        self::assertSame('2013-08-12', $accounts[5]->publishedFrom->format('Y-m-d'));
        self::assertNull($accounts[0]->publishedUntil);
    }

    public function testUnreliablePayerCarriesTheDateItWasPublished(): void
    {
        $subject = self::parseFixture('status-mixed.xml')[1];

        self::assertTrue($subject->unreliable);
        self::assertNotNull($subject->unreliableSince);
        self::assertSame('2017-03-16', $subject->unreliableSince->format('Y-m-d'));
        self::assertSame('LIDRU, A.S.', $subject->name);
        self::assertNotNull($subject->address);
        self::assertSame('LIBOTENICE', $subject->address->district);
    }

    public function testVatGroupWithoutNameAndAddressIsMapped(): void
    {
        $subject = self::parseFixture('status-mixed.xml')[2];

        self::assertSame(SubjectType::VatGroup, $subject->type);
        self::assertTrue($subject->isVatPayer());
        self::assertNull($subject->name);
        self::assertNull($subject->address);
        self::assertCount(3, $subject->bankAccounts);
    }

    public function testIdentifiedPersonWithoutPublishedAccountsHasNoBankAccounts(): void
    {
        $subject = self::parseFixture('status-mixed.xml')[3];

        self::assertSame(SubjectType::IdentifiedPerson, $subject->type);
        self::assertFalse($subject->isVatPayer());
        self::assertSame([], $subject->bankAccounts);
        self::assertSame('KNIHOVNA JIŘÍHO MAHENA', $subject->name);
        self::assertNotNull($subject->address);
        self::assertSame('BRNO-STŘED', $subject->address->city);
    }

    // --- hand-made states ---

    public function testUnreliablePersonTypeIsMapped(): void
    {
        $subject = self::subjectOf('status-unreliable-person.xml');

        self::assertSame(SubjectType::UnreliablePerson, $subject->type);
        self::assertTrue($subject->unreliable);
        self::assertNotNull($subject->unreliableSince);
        self::assertSame('2020-05-18', $subject->unreliableSince->format('Y-m-d'));
        self::assertFalse($subject->isVatPayer());
    }

    public function testEndedAccountKeepsItsPublicationEndAndIsStillMapped(): void
    {
        $subject = self::subjectOf('status-ended-account.xml');

        self::assertCount(3, $subject->bankAccounts);
        self::assertTrue($subject->bankAccounts[0]->isActive());
        self::assertFalse($subject->bankAccounts[1]->isActive());
        self::assertNotNull($subject->bankAccounts[1]->publishedUntil);
        self::assertSame('2020-12-31', $subject->bankAccounts[1]->publishedUntil->format('Y-m-d'));
        self::assertSame('27-5868650297/0100', (string) $subject->bankAccounts[0]);
        self::assertSame('DE89370400440532013000', (string) $subject->bankAccounts[2]);
        self::assertNull($subject->address);
    }

    public function testSubjectWithoutAccountsAddressNameAndTaxOfficeIsMappedToEmptyValues(): void
    {
        $subject = self::subjectOf('status-no-accounts-no-address.xml');

        self::assertSame('CZ45274649', (string) $subject->vatId);
        self::assertSame([], $subject->bankAccounts);
        self::assertNull($subject->address);
        self::assertNull($subject->name);
        self::assertNull($subject->taxOfficeCode);
        self::assertNull($subject->unreliableSince);
    }

    public function testResponseWithoutSubjectsYieldsAnEmptyList(): void
    {
        self::assertSame([], ResponseParser::parseSubjects(self::envelope(self::okStatus())));
    }

    public function testEmptyAccountListYieldsNoAccounts(): void
    {
        $xml = self::envelope(self::okStatus().'<statusSubjektu typSubjektu="PLATCE_DPH" dic="45274649" nespolehlivyPlatce="NE"><zverejneneUcty/></statusSubjektu>');

        self::assertSame([], ResponseParser::parseSubjects($xml)[0]->bankAccounts);
    }

    public function testVatIdWithCountryPrefixInTheResponseIsAccepted(): void
    {
        $xml = self::envelope(self::okStatus().'<statusSubjektu typSubjektu="PLATCE_DPH" dic="CZ45274649" nespolehlivyPlatce="NE"/>');

        self::assertSame('CZ45274649', (string) ResponseParser::parseSubjects($xml)[0]->vatId);
    }

    // --- status codes ---

    public function testStatusCodeOneIsAnInvalidResponseWithoutTheStatusText(): void
    {
        $error = self::invalidResponseFor(FixtureLoader::read('Adis/status-code-1.xml'));

        self::assertSame(Source::Adis, $error->source);
        self::assertMatchesRegularExpression('~^ADIS: expected .+ at '.preg_quote(self::RESPONSE_PATH.'/r:status/@statusCode', '~').'$~', $error->getMessage());
        self::assertStringNotContainsString('SENTINEL-STATUS-TEXT', $error->getMessage());
    }

    public function testUnknownStatusCodeIsAnInvalidResponseWithoutTheStatusText(): void
    {
        $error = self::invalidResponseFor(FixtureLoader::read('Adis/status-code-9.xml'));

        self::assertSame(Source::Adis, $error->source);
        self::assertMatchesRegularExpression('~^ADIS: expected .+ at '.preg_quote(self::RESPONSE_PATH.'/r:status/@statusCode', '~').'$~', $error->getMessage());
        self::assertStringNotContainsString('SENTINEL-STATUS-TEXT', $error->getMessage());
    }

    public function testStatusCodeTwoMeansTheServiceIsInMaintenance(): void
    {
        $error = self::unavailableFor(FixtureLoader::read('Adis/status-code-2.xml'));

        self::assertSame(Source::Adis, $error->source);
        self::assertSame('2', $error->errorCode);
        self::assertSame('ADIS is in scheduled maintenance (error code 2)', $error->getMessage());
        self::assertStringNotContainsString('status code', $error->getMessage());
        self::assertStringNotContainsString('SENTINEL-STATUS-TEXT', $error->getMessage());
    }

    public function testStatusCodeThreeMeansTheServiceIsUnavailableWithoutLeakingTheStatusText(): void
    {
        $error = self::unavailableFor(FixtureLoader::read('Adis/status-code-3.xml'));

        self::assertSame(Source::Adis, $error->source);
        self::assertSame('3', $error->errorCode);
        self::assertSame('ADIS service is unavailable (error code 3)', $error->getMessage());
        self::assertStringNotContainsString('status code', $error->getMessage());
        self::assertStringNotContainsString('SENTINEL-STATUS-TEXT', $error->getMessage());
    }

    public function testSoapFaultMeansTheServiceIsUnavailable(): void
    {
        $error = self::unavailableFor(FixtureLoader::read('Adis/soap-fault.xml'));

        self::assertSame(Source::Adis, $error->source);
        self::assertSame('soapenv:Server', $error->errorCode);
        self::assertStringEndsWith(' (error code soapenv:Server)', $error->getMessage());
        self::assertSame(1, substr_count($error->getMessage(), 'soapenv:Server'));
        self::assertStringNotContainsString('SENTINEL-FAULT-STRING', $error->getMessage());
    }

    // --- invalid responses ---

    public function testUnknownSubjectTypeIsAnInvalidResponseWithoutTheUnknownValue(): void
    {
        $error = self::invalidResponseFor(FixtureLoader::read('Adis/status-unknown-type.xml'));

        self::assertSame(Source::Adis, $error->source);
        self::assertSame('ADIS: expected known subject type at '.self::RESPONSE_PATH.'/r:statusSubjektu[1]/@typSubjektu', $error->getMessage());
        self::assertStringNotContainsString('SENTINEL_TYPE', $error->getMessage());
    }

    public function testMissingVatIdNamesTheSubjectPosition(): void
    {
        $error = self::invalidResponseFor(FixtureLoader::read('Adis/status-missing-dic.xml'));

        self::assertSame('ADIS: missing '.self::RESPONSE_PATH.'/r:statusSubjektu[2]/@dic', $error->getMessage());
    }

    public function testInvalidXmlIsAnInvalidResponse(): void
    {
        $error = self::invalidResponseFor(FixtureLoader::read('Adis/invalid.xml'));

        self::assertSame(Source::Adis, $error->source);
        self::assertSame('ADIS: response is not well-formed XML', $error->getMessage());
    }

    public function testEmptyBodyIsAnInvalidResponse(): void
    {
        self::assertSame('ADIS: response is not well-formed XML', self::invalidResponseFor('')->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMalformedStructures(): iterable
    {
        $subjects = self::RESPONSE_PATH.'/r:statusSubjektu[1]';

        yield 'not a soap document' => ['<html xmlns="http://www.w3.org/1999/xhtml"/>', 'ADIS: missing s:Body'];
        yield 'response of another operation' => [
            self::envelope('<status odpovedGenerovana="2026-10-03" statusCode="0"/>', 'SeznamNespolehlivyPlatceResponse'),
            'ADIS: missing '.self::RESPONSE_PATH,
        ];
        yield 'response element of the V2 request name' => [
            self::envelope('<status odpovedGenerovana="2026-10-03" statusCode="0"/>', 'StatusNespolehlivySubjektRozsirenyV2Response'),
            'ADIS: missing '.self::RESPONSE_PATH,
        ];
        yield 'missing status element' => [self::envelope(''), 'ADIS: missing '.self::RESPONSE_PATH.'/r:status'];
        yield 'missing status code' => [
            self::envelope('<status odpovedGenerovana="2026-10-03"/>'),
            'ADIS: missing '.self::RESPONSE_PATH.'/r:status/@statusCode',
        ];
        yield 'missing generation date' => [
            self::envelope('<status statusCode="0"/>'),
            'ADIS: missing '.self::RESPONSE_PATH.'/r:status/@odpovedGenerovana',
        ];
        yield 'invalid generation date' => [
            self::envelope('<status odpovedGenerovana="2026-13-45" statusCode="0"/>'),
            'ADIS: expected date (Y-m-d) at '.self::RESPONSE_PATH.'/r:status/@odpovedGenerovana',
        ];
        yield 'missing subject type' => [
            self::envelope('<status odpovedGenerovana="2026-10-03" statusCode="0"/><statusSubjektu dic="45274649" nespolehlivyPlatce="NE"/>'),
            'ADIS: missing '.$subjects.'/@typSubjektu',
        ];
        yield 'missing unreliable flag' => [
            self::envelope('<status odpovedGenerovana="2026-10-03" statusCode="0"/><statusSubjektu typSubjektu="PLATCE_DPH" dic="45274649"/>'),
            'ADIS: missing '.$subjects.'/@nespolehlivyPlatce',
        ];
        yield 'invalid vat id' => [
            self::envelope('<status odpovedGenerovana="2026-10-03" statusCode="0"/><statusSubjektu typSubjektu="PLATCE_DPH" dic="ABC" nespolehlivyPlatce="NE"/>'),
            'ADIS: expected VAT id at '.$subjects.'/@dic',
        ];
        yield 'invalid unreliable since date' => [
            self::envelope('<status odpovedGenerovana="2026-10-03" statusCode="0"/><statusSubjektu typSubjektu="PLATCE_DPH" dic="45274649" nespolehlivyPlatce="ANO" datumZverejneniNespolehlivosti="2017-02-30"/>'),
            'ADIS: expected date (Y-m-d) at '.$subjects.'/@datumZverejneniNespolehlivosti',
        ];
        yield 'account without publication date' => [
            self::envelope('<status odpovedGenerovana="2026-10-03" statusCode="0"/><statusSubjektu typSubjektu="PLATCE_DPH" dic="45274649" nespolehlivyPlatce="NE"><zverejneneUcty><ucet><standardniUcet cislo="71504011" kodBanky="0100"/></ucet></zverejneneUcty></statusSubjektu>'),
            'ADIS: missing '.$subjects.'/r:zverejneneUcty/r:ucet[1]/@datumZverejneni',
        ];
        yield 'standard account without number' => [
            self::envelope('<status odpovedGenerovana="2026-10-03" statusCode="0"/><statusSubjektu typSubjektu="PLATCE_DPH" dic="45274649" nespolehlivyPlatce="NE"><zverejneneUcty><ucet datumZverejneni="2013-04-01"><standardniUcet kodBanky="0100"/></ucet></zverejneneUcty></statusSubjektu>'),
            'ADIS: missing '.$subjects.'/r:zverejneneUcty/r:ucet[1]/r:standardniUcet/@cislo',
        ];
        yield 'standard account without bank code' => [
            self::envelope('<status odpovedGenerovana="2026-10-03" statusCode="0"/><statusSubjektu typSubjektu="PLATCE_DPH" dic="45274649" nespolehlivyPlatce="NE"><zverejneneUcty><ucet datumZverejneni="2013-04-01"><standardniUcet cislo="71504011"/></ucet></zverejneneUcty></statusSubjektu>'),
            'ADIS: missing '.$subjects.'/r:zverejneneUcty/r:ucet[1]/r:standardniUcet/@kodBanky',
        ];
        yield 'account of neither kind' => [
            self::envelope('<status odpovedGenerovana="2026-10-03" statusCode="0"/><statusSubjektu typSubjektu="PLATCE_DPH" dic="45274649" nespolehlivyPlatce="NE"><zverejneneUcty><ucet datumZverejneni="2013-04-01"/></zverejneneUcty></statusSubjektu>'),
            'ADIS: missing '.$subjects.'/r:zverejneneUcty/r:ucet[1]/r:nestandardniUcet',
        ];
    }

    #[DataProvider('provideMalformedStructures')]
    public function testMissingOrMalformedMandatoryPartIsAnInvalidResponseNamingThePath(string $xml, string $message): void
    {
        $error = self::invalidResponseFor($xml);

        self::assertSame(Source::Adis, $error->source);
        self::assertSame($message, $error->getMessage());
    }

    // --- list of unreliable payers ---

    private const string LIST_PATH = 's:Body/r:SeznamNespolehlivyPlatceResponse';

    private static function listEnvelope(string $statusAndEntries): string
    {
        return self::envelope($statusAndEntries, 'SeznamNespolehlivyPlatceResponse');
    }

    private static function invalidListResponseFor(string $xml): InvalidResponse
    {
        try {
            ResponseParser::parseUnreliablePayers($xml);
        } catch (InvalidResponse $e) {
            return $e;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    private static function unavailableListFor(string $xml): ServiceUnavailable
    {
        try {
            ResponseParser::parseUnreliablePayers($xml);
        } catch (ServiceUnavailable $e) {
            return $e;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    public function testUnreliablePayerListIsMappedInResponseOrder(): void
    {
        $payers = ResponseParser::parseUnreliablePayers(FixtureLoader::read('Adis/unreliable-payers.xml'));

        self::assertSame(
            ['CZ00121100', 'CZ00559709', 'CZ27205746'],
            array_map(static fn (UnreliablePayer $payer): string => (string) $payer->vatId, $payers),
        );
    }

    public function testUnreliablePayerCarriesThePublicationDateAndATaxOfficeCodePaddedToThreeDigits(): void
    {
        $payers = ResponseParser::parseUnreliablePayers(FixtureLoader::read('Adis/unreliable-payers.xml'));

        self::assertSame(
            ['456', '461', '013'],
            array_map(static fn (UnreliablePayer $payer): ?string => $payer->taxOfficeCode, $payers),
        );
        self::assertNotNull($payers[0]->since);
        self::assertSame('2017-03-16', $payers[0]->since->format('Y-m-d'));
        self::assertSame('Europe/Prague', $payers[0]->since->getTimezone()->getName());
        self::assertNotNull($payers[2]->since);
        self::assertSame('2024-06-19', $payers[2]->since->format('Y-m-d'));
    }

    public function testUnreliablePayerWithoutDateAndTaxOfficeHasNullsForThem(): void
    {
        $payers = ResponseParser::parseUnreliablePayers(self::listEnvelope(
            self::okStatus().'<statusPlatceDPH dic="00121100" nespolehlivyPlatce="ANO"/>',
        ));

        self::assertCount(1, $payers);
        self::assertNull($payers[0]->since);
        self::assertNull($payers[0]->taxOfficeCode);
    }

    public function testUnreliablePayerListWithoutEntriesIsAnEmptyList(): void
    {
        self::assertSame([], ResponseParser::parseUnreliablePayers(self::listEnvelope(self::okStatus())));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidStatusCodes(): iterable
    {
        yield 'more than 100 ids' => ['1'];
        yield 'unknown code' => ['9'];
    }

    #[DataProvider('provideInvalidStatusCodes')]
    public function testUnreliablePayerListWithAnUnexpectedStatusCodeIsAnInvalidResponseWithoutTheStatusText(string $code): void
    {
        $error = self::invalidListResponseFor(self::listEnvelope(
            '<status odpovedGenerovana="2026-10-03" statusCode="'.$code.'" statusText="SENTINEL-STATUS-TEXT"/>',
        ));

        self::assertSame(Source::Adis, $error->source);
        self::assertMatchesRegularExpression('~^ADIS: expected .+ at '.preg_quote(self::LIST_PATH.'/r:status/@statusCode', '~').'$~', $error->getMessage());
        self::assertStringNotContainsString('SENTINEL-STATUS-TEXT', $error->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnavailableStatusCodes(): iterable
    {
        yield 'maintenance' => ['2', 'ADIS is in scheduled maintenance (error code 2)'];
        yield 'unavailable' => ['3', 'ADIS service is unavailable (error code 3)'];
    }

    #[DataProvider('provideUnavailableStatusCodes')]
    public function testUnreliablePayerListWhileTheServiceIsDownIsServiceUnavailableWithoutTheStatusText(string $code, string $message): void
    {
        $error = self::unavailableListFor(self::listEnvelope(
            '<status odpovedGenerovana="2026-10-03" statusCode="'.$code.'" statusText="SENTINEL-STATUS-TEXT"/>',
        ));

        self::assertSame(Source::Adis, $error->source);
        self::assertSame($code, $error->errorCode);
        self::assertSame($message, $error->getMessage());
        self::assertStringNotContainsString('SENTINEL-STATUS-TEXT', $error->getMessage());
    }

    public function testUnreliablePayerListAnsweredWithASoapFaultIsServiceUnavailable(): void
    {
        $error = self::unavailableListFor(FixtureLoader::read('Adis/soap-fault.xml'));

        self::assertSame('soapenv:Server', $error->errorCode);
        self::assertStringNotContainsString('SENTINEL-FAULT-STRING', $error->getMessage());
    }

    public function testUnreliablePayerWithoutVatIdNamesTheEntryPosition(): void
    {
        $error = self::invalidListResponseFor(self::listEnvelope(
            self::okStatus().'<statusPlatceDPH dic="00121100" nespolehlivyPlatce="ANO"/><statusPlatceDPH nespolehlivyPlatce="ANO"/>',
        ));

        self::assertSame('ADIS: missing '.self::LIST_PATH.'/r:statusPlatceDPH[2]/@dic', $error->getMessage());
    }

    public function testUnreliablePayerWithAnInvalidVatIdIsAnInvalidResponseWithoutTheValue(): void
    {
        $error = self::invalidListResponseFor(self::listEnvelope(
            self::okStatus().'<statusPlatceDPH dic="SENTINEL-DIC" nespolehlivyPlatce="ANO"/>',
        ));

        self::assertSame('ADIS: expected VAT id at '.self::LIST_PATH.'/r:statusPlatceDPH[1]/@dic', $error->getMessage());
        self::assertStringNotContainsString('SENTINEL-DIC', $error->getMessage());
    }

    public function testUnreliablePayerListThatIsNotXmlIsAnInvalidResponse(): void
    {
        self::assertSame('ADIS: response is not well-formed XML', self::invalidListResponseFor('')->getMessage());
    }
}
