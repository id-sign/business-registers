<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Internal;

use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Internal\JsonReader;
use IdSign\BusinessRegisters\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonReader::class)]
final class JsonReaderTest extends TestCase
{
    private const string DOCUMENT = <<<'JSON'
        {
            "name": " ČEZ ",
            "psc": 14000,
            "pscTxt": "140 00",
            "n": null,
            "empty": "",
            "blank": "   ",
            "num": "554782",
            "numPadded": " 42 ",
            "flag": true,
            "d": "2026-10-03",
            "dt": "2026-10-03T14:18:12.832Z",
            "codes": ["01.1", " 02.2 ", ""],
            "nums": [1, " 2 "],
            "mixed": ["a", true],
            "sidlo": {"kodObce": "554782"},
            "zaznamy": [{"organy": [{"typ": "A"}, {}]}],
            "bad": 1.5
        }
        JSON;

    private static function reader(): JsonReader
    {
        return JsonReader::fromJson(self::DOCUMENT, Source::Ares);
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
        $reader = JsonReader::fromJson('{"sidlo":{}}', Source::Vies);

        self::assertSame(Source::Vies, $reader->source);
        self::assertSame(Source::Vies, $reader->object('sidlo')->source);
    }

    public function testEmptyObjectIsAccepted(): void
    {
        self::assertNull(JsonReader::fromJson('{}', Source::Ares)->optionalString('x'));
    }

    public function testInvalidJsonIsRejectedWithTheParserExceptionAsPrevious(): void
    {
        $e = self::failure(static fn () => JsonReader::fromJson('not json', Source::Ares));

        self::assertSame('ARES: response is not valid JSON', $e->getMessage());
        self::assertSame(Source::Ares, $e->source);
        self::assertInstanceOf(\JsonException::class, $e->getPrevious());
    }

    public function testEmptyBodyIsNotValidJson(): void
    {
        $e = self::failure(static fn () => JsonReader::fromJson('', Source::Ares));

        self::assertSame('ARES: response is not valid JSON', $e->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonObjectRoots(): iterable
    {
        yield 'list' => ['[1]'];
        yield 'string' => ['"text"'];
        yield 'number' => ['42'];
        yield 'null' => ['null'];
        yield 'boolean' => ['true'];
    }

    #[DataProvider('provideNonObjectRoots')]
    public function testNonObjectRootIsRejected(string $json): void
    {
        $e = self::failure(static fn () => JsonReader::fromJson($json, Source::Ares));

        self::assertSame('ARES: expected object at root', $e->getMessage());
        self::assertSame(Source::Ares, $e->source);
    }

    #[DataProvider('provideNonObjectRoots')]
    public function testTryFromJsonReturnsNullForNonObjectRoot(string $json): void
    {
        self::assertNull(JsonReader::tryFromJson($json, Source::Ares));
    }

    public function testTryFromJsonReturnsNullForInvalidJson(): void
    {
        self::assertNull(JsonReader::tryFromJson('not json', Source::Ares));
    }

    public function testTryFromJsonReadsAValidObject(): void
    {
        $reader = JsonReader::tryFromJson('{"subKod":"X"}', Source::Ares);

        self::assertSame('X', $reader?->optionalString('subKod'));
    }

    // --- strings ---

    public function testStringIsTrimmed(): void
    {
        self::assertSame('ČEZ', self::reader()->string('name'));
        self::assertSame('ČEZ', self::reader()->optionalString('name'));
    }

    public function testStringAcceptsAnInteger(): void
    {
        self::assertSame('14000', self::reader()->string('psc'));
        self::assertSame('14000', self::reader()->optionalString('psc'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAbsentStringKeys(): iterable
    {
        yield 'absent key' => ['nothing'];
        yield 'null' => ['n'];
        yield 'empty string' => ['empty'];
        yield 'blank string' => ['blank'];
    }

    #[DataProvider('provideAbsentStringKeys')]
    public function testOptionalStringIsNullForAbsentNullAndEmptyValues(string $key): void
    {
        self::assertNull(self::reader()->optionalString($key));
    }

    #[DataProvider('provideAbsentStringKeys')]
    public function testMandatoryStringReportsAbsentNullAndEmptyValuesAsMissing(string $key): void
    {
        $e = self::failure(static fn () => self::reader()->string($key));

        self::assertSame('ARES: missing '.$key, $e->getMessage());
        self::assertSame(Source::Ares, $e->source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonStringKeys(): iterable
    {
        yield 'float' => ['bad'];
        yield 'boolean' => ['flag'];
        yield 'object' => ['sidlo'];
        yield 'list' => ['codes'];
    }

    #[DataProvider('provideNonStringKeys')]
    public function testStringRejectsOtherTypes(string $key): void
    {
        $e = self::failure(static fn () => self::reader()->string($key));

        self::assertSame('ARES: expected string at '.$key, $e->getMessage());
    }

    #[DataProvider('provideNonStringKeys')]
    public function testOptionalStringRejectsOtherTypes(string $key): void
    {
        $e = self::failure(static fn () => self::reader()->optionalString($key));

        self::assertSame('ARES: expected string at '.$key, $e->getMessage());
    }

    public function testMissingMessageUsesTheLabelOfTheSource(): void
    {
        $e = self::failure(static fn () => JsonReader::fromJson('{}', Source::Vies)->string('valid'));

        self::assertSame('VIES: missing valid', $e->getMessage());
        self::assertSame(Source::Vies, $e->source);
    }

    // --- integers ---

    public function testIntAcceptsAnIntegerAndADigitString(): void
    {
        self::assertSame(14000, self::reader()->int('psc'));
        self::assertSame(554782, self::reader()->int('num'));
        self::assertSame(42, self::reader()->int('numPadded'));
        self::assertSame(554782, self::reader()->optionalInt('num'));
    }

    public function testOptionalIntIsNullForAbsentAndNullValues(): void
    {
        self::assertNull(self::reader()->optionalInt('nothing'));
        self::assertNull(self::reader()->optionalInt('n'));
    }

    public function testMandatoryIntReportsAnAbsentValueAsMissing(): void
    {
        $e = self::failure(static fn () => self::reader()->int('nothing'));

        self::assertSame('ARES: missing nothing', $e->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonIntegerKeys(): iterable
    {
        yield 'text' => ['name'];
        yield 'float' => ['bad'];
        yield 'boolean' => ['flag'];
        yield 'digits with a space inside' => ['pscTxt'];
    }

    #[DataProvider('provideNonIntegerKeys')]
    public function testIntRejectsOtherValues(string $key): void
    {
        $e = self::failure(static fn () => self::reader()->int($key));

        self::assertSame('ARES: expected integer at '.$key, $e->getMessage());
    }

    #[DataProvider('provideNonIntegerKeys')]
    public function testOptionalIntRejectsOtherValues(string $key): void
    {
        $e = self::failure(static fn () => self::reader()->optionalInt($key));

        self::assertSame('ARES: expected integer at '.$key, $e->getMessage());
    }

    // --- booleans ---

    public function testBoolReadsAJsonBoolean(): void
    {
        self::assertTrue(self::reader()->bool('flag'));
        self::assertTrue(self::reader()->optionalBool('flag'));
        self::assertNull(self::reader()->optionalBool('nothing'));
        self::assertNull(self::reader()->optionalBool('n'));
    }

    public function testBoolReadsFalseAsFalseNotAsMissing(): void
    {
        $reader = JsonReader::fromJson('{"valid":false}', Source::Vies);

        self::assertFalse($reader->bool('valid'));
        self::assertFalse($reader->optionalBool('valid'));
    }

    public function testMandatoryBoolReportsAnAbsentValueAsMissing(): void
    {
        $e = self::failure(static fn () => self::reader()->bool('n'));

        self::assertSame('ARES: missing n', $e->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonBooleanKeys(): iterable
    {
        yield 'text' => ['name'];
        yield 'integer' => ['psc'];
        yield 'text true' => ['num'];
    }

    #[DataProvider('provideNonBooleanKeys')]
    public function testBoolAcceptsNothingButAJsonBoolean(string $key): void
    {
        $e = self::failure(static fn () => self::reader()->bool($key));

        self::assertStringStartsWith('ARES: expected ', $e->getMessage());
        self::assertStringEndsWith(' at '.$key, $e->getMessage());

        $optional = self::failure(static fn () => self::reader()->optionalBool($key));

        self::assertSame($e->getMessage(), $optional->getMessage());
    }

    // --- dates ---

    public function testDateIsMidnightInPrague(): void
    {
        $date = self::reader()->date('d');

        self::assertSame('2026-10-03T00:00:00+02:00', $date->format('c'));
        self::assertSame('2026-10-03T00:00:00+02:00', self::reader()->optionalDate('d')?->format('c'));
    }

    public function testOptionalDateIsNullForAbsentAndNullValues(): void
    {
        self::assertNull(self::reader()->optionalDate('nothing'));
        self::assertNull(self::reader()->optionalDate('n'));
        self::assertNull(self::reader()->optionalDate('empty'));
    }

    public function testMandatoryDateReportsAnAbsentValueAsMissing(): void
    {
        $e = self::failure(static fn () => self::reader()->date('n'));

        self::assertSame('ARES: missing n', $e->getMessage());
    }

    public function testDateRejectsUnreadableValues(): void
    {
        $e = self::failure(static fn () => self::reader()->date('name'));

        self::assertSame('ARES: expected date (Y-m-d) at name', $e->getMessage());

        $optional = self::failure(static fn () => self::reader()->optionalDate('name'));

        self::assertSame('ARES: expected date (Y-m-d) at name', $optional->getMessage());
    }

    public function testDateRejectsAnOverflowingDate(): void
    {
        $reader = JsonReader::fromJson('{"x":"2022-13-45"}', Source::Ares);

        $e = self::failure(static fn () => $reader->date('x'));

        self::assertSame('ARES: expected date (Y-m-d) at x', $e->getMessage());
    }

    public function testDateTimeIsConvertedToUtc(): void
    {
        $dateTime = self::reader()->dateTimeUtc('dt');

        self::assertSame('2026-10-03T14:18:12.832+00:00', $dateTime->format('Y-m-d\TH:i:s.vP'));
        self::assertSame('2026-10-03T14:18:12+00:00', self::reader()->optionalDateTimeUtc('dt')?->format('c'));
    }

    public function testOptionalDateTimeIsNullForAbsentAndNullValues(): void
    {
        self::assertNull(self::reader()->optionalDateTimeUtc('nothing'));
        self::assertNull(self::reader()->optionalDateTimeUtc('n'));
    }

    public function testMandatoryDateTimeReportsAnAbsentValueAsMissing(): void
    {
        $e = self::failure(static fn () => self::reader()->dateTimeUtc('nothing'));

        self::assertSame('ARES: missing nothing', $e->getMessage());
    }

    public function testDateTimeRejectsUnreadableValues(): void
    {
        $e = self::failure(static fn () => self::reader()->dateTimeUtc('name'));

        self::assertSame('ARES: expected datetime at name', $e->getMessage());

        $optional = self::failure(static fn () => self::reader()->optionalDateTimeUtc('name'));

        self::assertSame('ARES: expected datetime at name', $optional->getMessage());
    }

    // --- objects ---

    public function testObjectReturnsAChildReader(): void
    {
        self::assertSame(554782, self::reader()->object('sidlo')->int('kodObce'));
        self::assertSame('554782', self::reader()->optionalObject('sidlo')?->string('kodObce'));
    }

    public function testOptionalObjectIsNullForAbsentAndNullValues(): void
    {
        self::assertNull(self::reader()->optionalObject('nothing'));
        self::assertNull(self::reader()->optionalObject('n'));
    }

    public function testMandatoryObjectReportsAnAbsentValueAsMissing(): void
    {
        $e = self::failure(static fn () => self::reader()->object('nothing'));

        self::assertSame('ARES: missing nothing', $e->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonObjectKeys(): iterable
    {
        yield 'text' => ['name'];
        yield 'non-empty list' => ['zaznamy'];
        yield 'integer' => ['psc'];
    }

    #[DataProvider('provideNonObjectKeys')]
    public function testObjectRejectsOtherTypes(string $key): void
    {
        $e = self::failure(static fn () => self::reader()->object($key));

        self::assertSame('ARES: expected object at '.$key, $e->getMessage());

        $optional = self::failure(static fn () => self::reader()->optionalObject($key));

        self::assertSame('ARES: expected object at '.$key, $optional->getMessage());
    }

    public function testAnEmptyJsonObjectInAListIsAReaderWithNoKeys(): void
    {
        $organs = self::reader()->objectList('zaznamy')[0]->objectList('organy');

        self::assertNull($organs[1]->optionalString('typ'));
    }

    // --- object lists ---

    public function testObjectListReturnsOneReaderPerElement(): void
    {
        $records = self::reader()->objectList('zaznamy');

        self::assertCount(1, $records);

        $organs = $records[0]->objectList('organy');

        self::assertCount(2, $organs);
        self::assertSame('A', $organs[0]->string('typ'));
    }

    public function testOptionalObjectListIsEmptyForAbsentAndNullValues(): void
    {
        self::assertSame([], self::reader()->optionalObjectList('nothing'));
        self::assertSame([], self::reader()->optionalObjectList('n'));
    }

    public function testOptionalObjectListReadsElements(): void
    {
        self::assertCount(1, self::reader()->optionalObjectList('zaznamy'));
    }

    public function testMandatoryObjectListReportsAnAbsentValueAsMissing(): void
    {
        $e = self::failure(static fn () => self::reader()->objectList('nothing'));

        self::assertSame('ARES: missing nothing', $e->getMessage());
    }

    public function testPathOfANestedElementIsZeroBased(): void
    {
        $organs = self::reader()->objectList('zaznamy')[0]->objectList('organy');

        $e = self::failure(static fn () => $organs[1]->string('typ'));

        self::assertSame('ARES: missing zaznamy[0].organy[1].typ', $e->getMessage());
    }

    public function testPathOfAChildObjectIsDotSeparated(): void
    {
        $sidlo = self::reader()->object('sidlo');

        $e = self::failure(static fn () => $sidlo->string('nothing'));

        self::assertSame('ARES: missing sidlo.nothing', $e->getMessage());
    }

    public function testObjectListRejectsAnObject(): void
    {
        $e = self::failure(static fn () => self::reader()->objectList('sidlo'));

        self::assertSame('ARES: expected list of objects at sidlo', $e->getMessage());

        $optional = self::failure(static fn () => self::reader()->optionalObjectList('sidlo'));

        self::assertSame('ARES: expected list of objects at sidlo', $optional->getMessage());
    }

    public function testObjectListRejectsAScalar(): void
    {
        $e = self::failure(static fn () => self::reader()->objectList('name'));

        self::assertSame('ARES: expected list of objects at name', $e->getMessage());
    }

    public function testObjectListRejectsANonObjectElement(): void
    {
        $e = self::failure(static fn () => self::reader()->objectList('codes'));

        self::assertSame('ARES: expected object at codes[0]', $e->getMessage());
    }

    // --- string lists ---

    public function testStringListTrimsElementsAndDropsEmptyOnes(): void
    {
        self::assertSame(['01.1', '02.2'], self::reader()->stringList('codes'));
        self::assertSame(['01.1', '02.2'], self::reader()->optionalStringList('codes'));
    }

    public function testStringListAcceptsIntegerElements(): void
    {
        self::assertSame(['1', '2'], self::reader()->stringList('nums'));
    }

    public function testOptionalStringListIsEmptyForAbsentAndNullValues(): void
    {
        self::assertSame([], self::reader()->optionalStringList('nothing'));
        self::assertSame([], self::reader()->optionalStringList('n'));
    }

    public function testMandatoryStringListReportsAnAbsentValueAsMissing(): void
    {
        $e = self::failure(static fn () => self::reader()->stringList('nothing'));

        self::assertSame('ARES: missing nothing', $e->getMessage());
    }

    public function testStringListRejectsANonList(): void
    {
        $e = self::failure(static fn () => self::reader()->stringList('name'));

        self::assertSame('ARES: expected list of strings at name', $e->getMessage());

        $optional = self::failure(static fn () => self::reader()->optionalStringList('sidlo'));

        self::assertSame('ARES: expected list of strings at sidlo', $optional->getMessage());
    }

    public function testStringListRejectsABadElementWithItsIndex(): void
    {
        $e = self::failure(static fn () => self::reader()->stringList('mixed'));

        self::assertSame('ARES: expected string at mixed[1]', $e->getMessage());
    }

    // --- caller validation ---

    public function testInvalidBuildsAnExceptionWithKeyPathAndExpectedType(): void
    {
        $e = self::reader()->invalid('ico', 'company id');

        self::assertSame('ARES: expected company id at ico', $e->getMessage());
        self::assertSame(Source::Ares, $e->source);
    }

    public function testInvalidUsesThePathOfTheChildReader(): void
    {
        $e = self::reader()->objectList('zaznamy')[0]->invalid('ico', 'company id');

        self::assertSame('ARES: expected company id at zaznamy[0].ico', $e->getMessage());
    }

    // --- no response content in messages ---

    /**
     * @return iterable<string, array{\Closure(JsonReader): mixed}>
     */
    public static function provideFailingAccessors(): iterable
    {
        yield 'string' => [static fn (JsonReader $r) => $r->string('sidlo')];
        yield 'int' => [static fn (JsonReader $r) => $r->int('name')];
        yield 'bool' => [static fn (JsonReader $r) => $r->bool('name')];
        yield 'date' => [static fn (JsonReader $r) => $r->date('name')];
        yield 'datetime' => [static fn (JsonReader $r) => $r->dateTimeUtc('name')];
        yield 'object' => [static fn (JsonReader $r) => $r->object('name')];
        yield 'object list' => [static fn (JsonReader $r) => $r->objectList('name')];
        yield 'object list element' => [static fn (JsonReader $r) => $r->objectList('codes')];
        yield 'string list' => [static fn (JsonReader $r) => $r->stringList('name')];
        yield 'string list element' => [static fn (JsonReader $r) => $r->stringList('mixed')];
        yield 'nested missing' => [static fn (JsonReader $r) => $r->object('sidlo')->string('name')];
    }

    /**
     * @param \Closure(JsonReader): mixed $access
     */
    #[DataProvider('provideFailingAccessors')]
    public function testMessagesNeverContainValuesFromTheResponse(\Closure $access): void
    {
        $reader = JsonReader::fromJson(
            '{"name":"SENTINEL-NAME","flag":"SENTINEL-FLAG","codes":["SENTINEL-CODE"],"mixed":["SENTINEL-OK",true],'
            .'"sidlo":{"other":"SENTINEL-NESTED"}}',
            Source::Ares,
        );

        $e = self::failure(static fn () => $access($reader));

        self::assertStringNotContainsString('SENTINEL', $e->getMessage());
    }

    public function testInvalidJsonMessageDoesNotContainTheBody(): void
    {
        $e = self::failure(static fn () => JsonReader::fromJson('SENTINEL-BODY {', Source::Ares));

        self::assertStringNotContainsString('SENTINEL', $e->getMessage());
    }
}
