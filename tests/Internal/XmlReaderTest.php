<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Internal;

use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Internal\XmlReader;
use IdSign\BusinessRegisters\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(XmlReader::class)]
final class XmlReaderTest extends TestCase
{
    private const array ADIS_NAMESPACES = [
        's' => 'http://schemas.xmlsoap.org/soap/envelope/',
        'r' => 'http://adis.mfcr.cz/rozhraniCRPDPH/',
    ];

    private const array ISIR_NAMESPACES = [
        'typ' => 'http://isirpublicservice.cuzk.cz/types/',
    ];

    private const string RESPONSE_PATH = 's:Body/r:StatusNespolehlivySubjektRozsirenyResponse';

    private const string ADIS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:r="http://adis.mfcr.cz/rozhraniCRPDPH/">
          <s:Body>
            <r:StatusNespolehlivySubjektRozsirenyResponse>
              <r:status statusCode="0" odpovedGenerovana="2026-10-03" statusText=""/>
              <r:statusSubjektu typSubjektu=" PLATCE_DPH " dic="45274649" datumZverejneniNespolehlivosti="2026-13-45">
                <r:nazevSubjektu>  ČEZ, a. s.  </r:nazevSubjektu>
                <r:adresa>
                  <r:mesto>Praha</r:mesto>
                  <r:psc>   </r:psc>
                </r:adresa>
              </r:statusSubjektu>
              <r:statusSubjektu typSubjektu="NENALEZEN"/>
            </r:StatusNespolehlivySubjektRozsirenyResponse>
          </s:Body>
        </s:Envelope>
        XML;

    private const string ISIR = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <ns2:isirPub001 xmlns:ns2="http://isirpublicservice.cuzk.cz/types/">
          <data>
            <ic> 12345678 </ic>
            <nazev/>
          </data>
          <data>
            <nazev>X</nazev>
          </data>
        </ns2:isirPub001>
        XML;

    private static function adis(): XmlReader
    {
        return XmlReader::fromString(self::ADIS, Source::Adis, self::ADIS_NAMESPACES);
    }

    private static function adisResponse(): XmlReader
    {
        return self::adis()->element('s:Body')->element('r:StatusNespolehlivySubjektRozsirenyResponse');
    }

    private static function isir(): XmlReader
    {
        return XmlReader::fromString(self::ISIR, Source::Isir, self::ISIR_NAMESPACES);
    }

    /**
     * @param \Closure(): mixed $action
     */
    private static function failure(\Closure $action): InvalidResponse
    {
        try {
            $action();
        } catch (InvalidResponse $e) {
            return $e;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    // --- construction ---

    public function testReaderExposesItsSource(): void
    {
        self::assertSame(Source::Adis, self::adis()->source);
        self::assertSame(Source::Adis, self::adisResponse()->source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedDocuments(): iterable
    {
        yield 'mismatched tags' => ['<a><b></a>'];
        yield 'not xml' => ['not xml'];
        yield 'empty body' => [''];
    }

    #[DataProvider('provideMalformedDocuments')]
    public function testMalformedXmlIsRejectedWithoutPhpWarnings(string $xml): void
    {
        $e = self::failure(static fn () => XmlReader::fromString($xml, Source::Adis, self::ADIS_NAMESPACES));

        self::assertSame('ADIS: response is not well-formed XML', $e->getMessage());
        self::assertSame(Source::Adis, $e->source);
    }

    public function testMalformedXmlKeepsTheParserExceptionAsPrevious(): void
    {
        $e = self::failure(static fn () => XmlReader::fromString('<a><b></a>', Source::Adis, []));

        self::assertInstanceOf(\DOMException::class, $e->getPrevious());
    }

    public function testMalformedXmlMessageDoesNotContainTheBody(): void
    {
        $e = self::failure(static fn () => XmlReader::fromString('<a>SENTINEL-BODY<b></a>', Source::Adis, []));

        self::assertStringNotContainsString('SENTINEL', $e->getMessage());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideLibxmlErrorModes(): iterable
    {
        yield 'internal errors off' => [false];
        yield 'internal errors on' => [true];
    }

    #[DataProvider('provideLibxmlErrorModes')]
    public function testLibxmlInternalErrorSettingIsRestored(bool $before): void
    {
        $original = libxml_use_internal_errors($before);

        try {
            self::failure(static fn () => XmlReader::fromString('<a><b></a>', Source::Adis, []));
            self::assertSame($before, libxml_use_internal_errors($before));

            XmlReader::fromString('<a/>', Source::Adis, []);
            self::assertSame($before, libxml_use_internal_errors($before));
        } finally {
            libxml_use_internal_errors($original);
        }
    }

    public function testNoLibxmlErrorsAreLeftBehind(): void
    {
        $original = libxml_use_internal_errors(false);

        try {
            self::failure(static fn () => XmlReader::fromString('<a><b></a>', Source::Adis, []));

            self::assertSame([], libxml_get_errors());
        } finally {
            libxml_use_internal_errors($original);
        }
    }

    // --- elements ---

    public function testElementDescendsOneLevelAtATime(): void
    {
        $status = self::adisResponse()->element('r:status');

        self::assertSame('0', $status->attribute('statusCode'));
    }

    public function testElementsReturnsAllMatchingChildrenInDocumentOrder(): void
    {
        $subjects = self::adisResponse()->elements('r:statusSubjektu');

        self::assertCount(2, $subjects);
        self::assertSame('45274649', $subjects[0]->attribute('dic'));
        self::assertSame('NENALEZEN', $subjects[1]->attribute('typSubjektu'));
    }

    public function testElementsIsEmptyWhenNothingMatches(): void
    {
        self::assertSame([], self::adisResponse()->elements('r:nothing'));
    }

    public function testOptionalElementIsNullWhenNothingMatches(): void
    {
        self::assertNull(self::adisResponse()->optionalElement('r:nothing'));
    }

    public function testOptionalElementReadsTheFirstMatch(): void
    {
        $subject = self::adisResponse()->optionalElement('r:statusSubjektu');

        self::assertSame('45274649', $subject?->attribute('dic'));
    }

    public function testMandatoryElementReportsAnAbsentElementAsMissingWithItsPath(): void
    {
        $e = self::failure(static fn () => self::adisResponse()->element('r:nothing'));

        self::assertSame('ADIS: missing '.self::RESPONSE_PATH.'/r:nothing', $e->getMessage());
        self::assertSame(Source::Adis, $e->source);
    }

    public function testLookupSeesDirectChildrenOnly(): void
    {
        self::assertNull(self::adis()->optionalElement('r:status'));
        self::assertNull(self::adis()->element('s:Body')->optionalElement('r:status'));
        self::assertNull(self::adisResponse()->elements('r:statusSubjektu')[1]->optionalElement('r:status'));
    }

    public function testUnprefixedNameDoesNotMatchANamespacedElement(): void
    {
        self::assertNull(self::adisResponse()->optionalElement('statusSubjektu'));
        self::assertSame([], self::adisResponse()->elements('statusSubjektu'));
    }

    public function testUnprefixedNameMatchesUnqualifiedChildrenOfANamespacedRoot(): void
    {
        $data = self::isir()->elements('data');

        self::assertCount(2, $data);
        self::assertSame('12345678', $data[0]->string('ic'));
    }

    public function testPrefixedNameDoesNotMatchUnqualifiedChildren(): void
    {
        self::assertNull(self::isir()->optionalElement('typ:data'));
    }

    // --- paths ---

    public function testElementPathsAreOneBasedXPathPositions(): void
    {
        $second = self::adisResponse()->elements('r:statusSubjektu')[1];

        $e = self::failure(static fn () => $second->attribute('dic'));

        self::assertSame('ADIS: missing '.self::RESPONSE_PATH.'/r:statusSubjektu[2]/@dic', $e->getMessage());
    }

    public function testFirstSegmentHasNoLeadingSeparator(): void
    {
        $e = self::failure(static fn () => self::adis()->attribute('nothing'));

        self::assertSame('ADIS: missing @nothing', $e->getMessage());
    }

    public function testPathsOfUnqualifiedElementsAreOneBasedToo(): void
    {
        $second = self::isir()->elements('data')[1];

        $e = self::failure(static fn () => $second->string('ic'));

        self::assertSame('ISIR: missing data[2]/ic', $e->getMessage());
        self::assertSame(Source::Isir, $e->source);
    }

    public function testPathOfAChildElementFollowsItsParent(): void
    {
        $address = self::adisResponse()->elements('r:statusSubjektu')[0]->element('r:adresa');

        $e = self::failure(static fn () => $address->string('r:stat'));

        self::assertSame(
            'ADIS: missing '.self::RESPONSE_PATH.'/r:statusSubjektu[1]/r:adresa/r:stat',
            $e->getMessage(),
        );
    }

    // --- attributes ---

    public function testAttributeIsTrimmed(): void
    {
        $subject = self::adisResponse()->elements('r:statusSubjektu')[0];

        self::assertSame('PLATCE_DPH', $subject->attribute('typSubjektu'));
        self::assertSame('PLATCE_DPH', $subject->optionalAttribute('typSubjektu'));
    }

    public function testOptionalAttributeIsNullWhenAbsentOrEmpty(): void
    {
        $status = self::adisResponse()->element('r:status');

        self::assertNull($status->optionalAttribute('nothing'));
        self::assertNull($status->optionalAttribute('statusText'));
    }

    public function testMandatoryAttributeReportsAnEmptyValueAsMissing(): void
    {
        $status = self::adisResponse()->element('r:status');

        $e = self::failure(static fn () => $status->attribute('statusText'));

        self::assertSame('ADIS: missing '.self::RESPONSE_PATH.'/r:status/@statusText', $e->getMessage());
    }

    public function testDateAttributeIsMidnightInPrague(): void
    {
        $status = self::adisResponse()->element('r:status');

        self::assertSame('2026-10-03T00:00:00+02:00', $status->dateAttribute('odpovedGenerovana')->format('c'));
        self::assertSame('2026-10-03T00:00:00+02:00', $status->optionalDateAttribute('odpovedGenerovana')?->format('c'));
    }

    public function testOptionalDateAttributeIsNullWhenAbsent(): void
    {
        self::assertNull(self::adisResponse()->element('r:status')->optionalDateAttribute('nothing'));
    }

    public function testMandatoryDateAttributeReportsAnAbsentValueAsMissing(): void
    {
        $status = self::adisResponse()->element('r:status');

        $e = self::failure(static fn () => $status->dateAttribute('nothing'));

        self::assertSame('ADIS: missing '.self::RESPONSE_PATH.'/r:status/@nothing', $e->getMessage());
    }

    public function testDateAttributeRejectsAnOverflowingDate(): void
    {
        $subject = self::adisResponse()->elements('r:statusSubjektu')[0];
        $expected = 'ADIS: expected date (Y-m-d) at '.self::RESPONSE_PATH
            .'/r:statusSubjektu[1]/@datumZverejneniNespolehlivosti';

        $e = self::failure(static fn () => $subject->dateAttribute('datumZverejneniNespolehlivosti'));
        self::assertSame($expected, $e->getMessage());

        $optional = self::failure(static fn () => $subject->optionalDateAttribute('datumZverejneniNespolehlivosti'));
        self::assertSame($expected, $optional->getMessage());
    }

    // --- child element text ---

    public function testStringIsTheTrimmedTextOfAChildElement(): void
    {
        $subject = self::adisResponse()->elements('r:statusSubjektu')[0];

        self::assertSame('ČEZ, a. s.', $subject->string('r:nazevSubjektu'));
        self::assertSame('ČEZ, a. s.', $subject->optionalString('r:nazevSubjektu'));
    }

    public function testOptionalStringIsNullForAnAbsentOrBlankElement(): void
    {
        $subjects = self::adisResponse()->elements('r:statusSubjektu');
        $address = $subjects[0]->element('r:adresa');

        self::assertNull($subjects[1]->optionalString('r:nazevSubjektu'));
        self::assertNull($address->optionalString('r:psc'));
    }

    public function testMandatoryStringReportsAnAbsentOrBlankElementAsMissing(): void
    {
        $subjects = self::adisResponse()->elements('r:statusSubjektu');
        $address = $subjects[0]->element('r:adresa');

        $absent = self::failure(static fn () => $subjects[1]->string('r:nazevSubjektu'));
        self::assertSame(
            'ADIS: missing '.self::RESPONSE_PATH.'/r:statusSubjektu[2]/r:nazevSubjektu',
            $absent->getMessage(),
        );

        $blank = self::failure(static fn () => $address->string('r:psc'));
        self::assertSame(
            'ADIS: missing '.self::RESPONSE_PATH.'/r:statusSubjektu[1]/r:adresa/r:psc',
            $blank->getMessage(),
        );
    }

    public function testOptionalStringIsNullForAnEmptyUnqualifiedElement(): void
    {
        self::assertNull(self::isir()->element('data')->optionalString('nazev'));
    }

    // --- caller validation ---

    public function testInvalidNamesAnAttributeWithItsPathAndExpectedType(): void
    {
        $subject = self::adisResponse()->elements('r:statusSubjektu')[0];

        $e = $subject->invalid('@typSubjektu', 'known subject type');

        self::assertSame(
            'ADIS: expected known subject type at '.self::RESPONSE_PATH.'/r:statusSubjektu[1]/@typSubjektu',
            $e->getMessage(),
        );
        self::assertSame(Source::Adis, $e->source);
    }

    public function testInvalidNamesAChildElement(): void
    {
        $subject = self::adisResponse()->elements('r:statusSubjektu')[1];

        $e = $subject->invalid('r:nazevSubjektu', 'name');

        self::assertSame(
            'ADIS: expected name at '.self::RESPONSE_PATH.'/r:statusSubjektu[2]/r:nazevSubjektu',
            $e->getMessage(),
        );
    }

    // --- child element numbers, dates and date-times ---

    private const string ISIR_VALUES = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <ns2:getIsirWsCuzkDataResponse xmlns:ns2="http://isirws.cca.cz/types/">
          <data>
            <number> 42 </number>
            <blank>   </blank>
            <text>abc</text>
            <decimal>1.5</decimal>
            <huge>99999999999999999999</huge>
            <day>2026-10-08Z</day>
            <badDay>2022-13-45Z</badDay>
            <offsetDay>2022-09-13+02:00</offsetDay>
            <negativeOffsetDay>2022-09-13-05:00</negativeOffsetDay>
            <doubleZoneDay>2022-09-13ZZ</doubleZoneDay>
            <bareZone>Z</bareZone>
            <moment>2026-10-08T09:26:35.000Z</moment>
            <winterMoment>2026-01-15T09:26:35.000Z</winterMoment>
            <badMoment>yesterday</badMoment>
          </data>
        </ns2:getIsirWsCuzkDataResponse>
        XML;

    private static function isirValues(): XmlReader
    {
        return XmlReader::fromString(self::ISIR_VALUES, Source::Isir, ['ns2' => 'http://isirws.cca.cz/types/'])->elements('data')[0];
    }

    public function testIntIsTheTrimmedChildTextAsAnInteger(): void
    {
        self::assertSame(42, self::isirValues()->int('number'));
        self::assertSame(42, self::isirValues()->optionalInt('number'));
    }

    public function testOptionalIntIsNullForAnAbsentOrBlankElement(): void
    {
        self::assertNull(self::isirValues()->optionalInt('nothing'));
        self::assertNull(self::isirValues()->optionalInt('blank'));
    }

    public function testMandatoryIntReportsAnAbsentOrBlankElementAsMissing(): void
    {
        $data = self::isirValues();

        self::assertSame('ISIR: missing data[1]/nothing', self::failure(static fn () => $data->int('nothing'))->getMessage());
        self::assertSame('ISIR: missing data[1]/blank', self::failure(static fn () => $data->int('blank'))->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonIntegerElements(): iterable
    {
        yield 'letters' => ['text'];
        yield 'decimal' => ['decimal'];
        yield 'overflow' => ['huge'];
    }

    #[DataProvider('provideNonIntegerElements')]
    public function testIntRejectsTextThatIsNotAnIntegerWithItsPath(string $element): void
    {
        $data = self::isirValues();
        $expected = 'ISIR: expected integer at data[1]/'.$element;

        self::assertSame($expected, self::failure(static fn () => $data->int($element))->getMessage());
        self::assertSame($expected, self::failure(static fn () => $data->optionalInt($element))->getMessage());
    }

    public function testDateIsMidnightInPragueAndToleratesTheZoneSuffix(): void
    {
        self::assertSame('2026-10-08T00:00:00+02:00', self::isirValues()->date('day')->format('c'));
        self::assertSame('2026-10-08T00:00:00+02:00', self::isirValues()->optionalDate('day')?->format('c'));
        self::assertSame('2022-09-13T00:00:00+02:00', self::isirValues()->date('offsetDay')->format('c'));
        self::assertSame('2022-09-13T00:00:00+02:00', self::isirValues()->optionalDate('negativeOffsetDay')?->format('c'));
    }

    public function testOptionalDateIsNullForAnAbsentOrBlankElement(): void
    {
        self::assertNull(self::isirValues()->optionalDate('nothing'));
        self::assertNull(self::isirValues()->optionalDate('blank'));
    }

    public function testMandatoryDateReportsAnAbsentElementAsMissing(): void
    {
        $data = self::isirValues();

        self::assertSame('ISIR: missing data[1]/nothing', self::failure(static fn () => $data->date('nothing'))->getMessage());
    }

    public function testDateRejectsAnUnreadableDateWithItsPath(): void
    {
        $data = self::isirValues();
        $expected = 'ISIR: expected date (Y-m-d) at data[1]/badDay';

        self::assertSame($expected, self::failure(static fn () => $data->date('badDay'))->getMessage());
        self::assertSame($expected, self::failure(static fn () => $data->optionalDate('badDay'))->getMessage());
        self::assertSame('ISIR: expected date (Y-m-d) at data[1]/doubleZoneDay', self::failure(static fn () => $data->date('doubleZoneDay'))->getMessage());
        self::assertSame('ISIR: expected date (Y-m-d) at data[1]/bareZone', self::failure(static fn () => $data->date('bareZone'))->getMessage());
    }

    public function testOptionalDateTimePragueReadsTheClockValueAsPragueLocalTime(): void
    {
        $summer = self::isirValues()->optionalDateTimePrague('moment');
        $winter = self::isirValues()->optionalDateTimePrague('winterMoment');

        self::assertNotNull($summer);
        self::assertNotNull($winter);
        self::assertSame('2026-10-08T09:26:35+02:00', $summer->format('c'));
        self::assertSame('2026-01-15T09:26:35+01:00', $winter->format('c'));
        self::assertSame('Europe/Prague', $summer->getTimezone()->getName());
    }

    public function testOptionalDateTimePragueIsNullForAnAbsentOrBlankElement(): void
    {
        self::assertNull(self::isirValues()->optionalDateTimePrague('nothing'));
        self::assertNull(self::isirValues()->optionalDateTimePrague('blank'));
    }

    public function testOptionalDateTimePragueRejectsAnUnreadableValueWithItsPath(): void
    {
        $data = self::isirValues();

        $e = self::failure(static fn () => $data->optionalDateTimePrague('badMoment'));

        self::assertSame('ISIR: expected date-time at data[1]/badMoment', $e->getMessage());
        self::assertSame(Source::Isir, $e->source);
    }

    public function testMessagesOfTheChildValueReadersNeverContainValuesFromTheResponse(): void
    {
        $reader = XmlReader::fromString(
            '<root><number>SENTINEL-NUMBER</number><day>SENTINEL-DAY</day><moment>SENTINEL-MOMENT</moment></root>',
            Source::Isir,
            [],
        );

        $messages = [
            self::failure(static fn () => $reader->int('number'))->getMessage(),
            self::failure(static fn () => $reader->optionalInt('number'))->getMessage(),
            self::failure(static fn () => $reader->date('day'))->getMessage(),
            self::failure(static fn () => $reader->optionalDate('day'))->getMessage(),
            self::failure(static fn () => $reader->optionalDateTimePrague('moment'))->getMessage(),
        ];

        foreach ($messages as $message) {
            self::assertStringNotContainsString('SENTINEL', $message);
        }
    }

    // --- no response content in messages ---

    /**
     * @return iterable<string, array{\Closure(XmlReader): mixed}>
     */
    public static function provideFailingAccessors(): iterable
    {
        yield 'missing attribute' => [static fn (XmlReader $r) => $r->attribute('nothing')];
        yield 'bad date attribute' => [static fn (XmlReader $r) => $r->dateAttribute('typSubjektu')];
        yield 'bad optional date attribute' => [static fn (XmlReader $r) => $r->optionalDateAttribute('dic')];
        yield 'missing element' => [static fn (XmlReader $r) => $r->element('r:nothing')];
        yield 'missing text' => [static fn (XmlReader $r) => $r->string('r:nothing')];
        yield 'invalid attribute' => [static fn (XmlReader $r) => throw $r->invalid('@typSubjektu', 'known subject type')];
        yield 'invalid element' => [static fn (XmlReader $r) => throw $r->invalid('r:nazevSubjektu', 'name')];
    }

    /**
     * @param \Closure(XmlReader): mixed $access
     */
    #[DataProvider('provideFailingAccessors')]
    public function testMessagesNeverContainValuesFromTheResponse(\Closure $access): void
    {
        $reader = XmlReader::fromString(
            '<r:root xmlns:r="http://adis.mfcr.cz/rozhraniCRPDPH/" typSubjektu="SENTINEL-TYPE" dic="SENTINEL-DIC">'
            .'<r:nazevSubjektu>SENTINEL-NAME</r:nazevSubjektu></r:root>',
            Source::Adis,
            self::ADIS_NAMESPACES,
        );

        $e = self::failure(static fn () => $access($reader));

        self::assertStringNotContainsString('SENTINEL', $e->getMessage());
    }
}
