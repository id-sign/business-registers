<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Ares\Internal;

use IdSign\BusinessRegisters\Address;
use IdSign\BusinessRegisters\Ares\AresRegister;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\Internal\CompanyMapper;
use IdSign\BusinessRegisters\Ares\RegistrationStatus;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Internal\JsonReader;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\FixtureLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompanyMapper::class)]
final class CompanyMapperTest extends TestCase
{
    private static function fromFixture(string $name): Company
    {
        return CompanyMapper::map(JsonReader::fromJson(FixtureLoader::read('Ares/'.$name), Source::Ares));
    }

    private static function fromJson(string $json): Company
    {
        return CompanyMapper::map(JsonReader::fromJson($json, Source::Ares));
    }

    /**
     * @param array<string, mixed> $seat
     */
    private static function addressOf(array $seat): Address
    {
        $company = self::fromJson(json_encode(
            ['icoId' => '45274649', 'obchodniJmeno' => 'Test', 'sidlo' => $seat],
            \JSON_THROW_ON_ERROR,
        ));

        self::assertNotNull($company->seat);

        return $company->seat;
    }

    private static function failure(string $json): InvalidResponse
    {
        try {
            self::fromJson($json);
        } catch (InvalidResponse $e) {
            return $e;
        }

        self::fail('Expected InvalidResponse was not thrown.');
    }

    // --- full record ---

    public function testFullRecordIsMappedToEveryCompanyProperty(): void
    {
        $company = self::fromFixture('find-cez.json');

        self::assertSame('45274649', $company->aresId);
        self::assertNotNull($company->id);
        self::assertSame('45274649', $company->id->value);
        self::assertSame('ČEZ, a. s.', $company->name);
        self::assertSame('121', $company->legalFormCode);
        self::assertNotNull($company->vatId);
        self::assertSame('CZ45274649', (string) $company->vatId);
        self::assertNull($company->groupVatId);
        self::assertSame('013', $company->taxOfficeCode);
        self::assertSame('1992-05-06', $company->establishedOn?->format('Y-m-d'));
        self::assertNull($company->dissolvedOn);
        self::assertSame('2026-09-17', $company->updatedOn?->format('Y-m-d'));
        self::assertNotSame([], $company->naceCodes);
        self::assertContains('25620', $company->naceCodes);
        self::assertContains('821', $company->naceCodes2008);
        self::assertSame('B 1581/MSPH', $company->fileNumber);
        self::assertSame('ros', $company->primarySource);
    }

    public function testSeatIsMappedFromTheRecordedAddress(): void
    {
        $seat = self::fromFixture('find-cez.json')->seat;

        self::assertNotNull($seat);
        self::assertSame('Duhová 1444/2', $seat->street);
        self::assertSame('Duhová', $seat->streetName);
        self::assertSame('1444', $seat->houseNumber);
        self::assertSame(1, $seat->houseNumberType);
        self::assertSame('2', $seat->orientationNumber);
        self::assertSame('Michle', $seat->district);
        self::assertSame('Praha 4', $seat->cityDistrict);
        self::assertSame('Praha', $seat->city);
        self::assertSame('14000', $seat->postalCode);
        self::assertSame('140 00', $seat->postalCodeFormatted());
        self::assertSame('CZ', $seat->countryCode);
        self::assertSame('Česká republika', $seat->countryName);
        self::assertSame('Hlavní město Praha', $seat->region);
        self::assertSame(25583697, $seat->addressPointId);
        self::assertSame(554782, $seat->municipalityCode);
        self::assertSame('Duhová 1444/2, Michle, 14000 Praha 4', $seat->text);
    }

    public function testDeliveryAddressLinesKeepTheThreeFilledLines(): void
    {
        $company = self::fromFixture('find-cez.json');

        self::assertSame(['Duhová 1444/2', 'Michle', '14000 Praha 4'], $company->deliveryAddressLines);
    }

    public function testDeliveryAddressLinesContainOnlyFilledLines(): void
    {
        $company = self::fromJson(<<<'JSON'
            {"icoId": "45274649", "obchodniJmeno": "T", "adresaDorucovaci": {"radekAdresy1": "A", "radekAdresy2": " ", "radekAdresy3": "C"}}
            JSON);

        self::assertSame(['A', 'C'], $company->deliveryAddressLines);
    }

    public function testMissingOptionalSectionsMapToEmptyValues(): void
    {
        $company = self::fromJson('{"icoId": "45274649", "obchodniJmeno": "T"}');

        self::assertNull($company->seat);
        self::assertSame([], $company->deliveryAddressLines);
        self::assertSame([], $company->naceCodes);
        self::assertSame([], $company->naceCodes2008);
        self::assertNull($company->fileNumber);
        self::assertNull($company->legalFormCode);
        self::assertNull($company->vatId);
    }

    public function testFileNumberComesOnlyFromThePublicRegisterRecord(): void
    {
        $company = self::fromJson(<<<'JSON'
            {"icoId": "45274649", "obchodniJmeno": "T", "dalsiUdaje": [
                {"datovyZdroj": "res", "spisovaZnacka": "X 1"},
                {"datovyZdroj": "vr", "spisovaZnacka": "C 123/MSPH"}
            ]}
            JSON);

        self::assertSame('C 123/MSPH', $company->fileNumber);
    }

    public function testFileNumberIsNullWithoutAPublicRegisterRecord(): void
    {
        $company = self::fromFixture('find-praha.json');

        self::assertNull($company->fileNumber);
    }

    // --- company id from the register ---

    public function testRegisterSubjectWithAWrongCheckDigitKeepsItsCompanyId(): void
    {
        $company = self::fromFixture('find-check-digit-mismatch.json');

        self::assertNotNull($company->id);
        self::assertSame('00123562', $company->id->value);
        self::assertFalse($company->id->hasValidCheckDigit());
        self::assertSame('Zemědělské družstvo Předměřice nad Labem - v likvidaci', $company->name);
    }

    public function testShortCompanyIdFromTheRegisterIsLeftPadded(): void
    {
        $company = self::fromJson('{"icoId": "123562", "obchodniJmeno": "T", "ico": "123562"}');

        self::assertSame('00123562', $company->id?->value);
    }

    // --- VAT ids ---

    public function testRecordWithoutDicHasNoVatId(): void
    {
        $company = self::fromFixture('find-no-dic.json');

        self::assertNull($company->vatId);
        self::assertNull($company->vatLookupId());
    }

    public function testGroupVatMemberKeepsTheGroupIdAndHasNoOwnVatId(): void
    {
        $company = self::fromFixture('find-komercni-banka.json');

        self::assertNull($company->vatId);
        self::assertNotNull($company->groupVatId);
        self::assertSame('CZ699001182', (string) $company->groupVatId);
        self::assertNotNull($company->vatLookupId());
        self::assertSame('CZ699001182', (string) $company->vatLookupId());
    }

    // --- natural person, subject without IČO ---

    public function testSoleTraderIsRecognisedAsNaturalPerson(): void
    {
        $company = self::fromFixture('find-natural-person.json');

        self::assertSame('101', $company->legalFormCode);
        self::assertTrue($company->isNaturalPerson());
        self::assertSame('CZ7001019999', (string) $company->vatId);
    }

    public function testSubjectWithoutCompanyIdKeepsTheAresId(): void
    {
        $company = self::fromFixture('find-no-ico.json');

        self::assertSame('ARES_00369838', $company->aresId);
        self::assertNull($company->id);
    }

    public function testForeignAddressWithOnlyTextAndCountryIsMapped(): void
    {
        $seat = self::fromFixture('find-no-ico.json')->seat;

        self::assertNotNull($seat);
        self::assertSame('Hauptstraße 27, 3443 Rappoltenkirchen, Rakousko', $seat->text);
        self::assertSame('AT', $seat->countryCode);
        self::assertNull($seat->street);
        self::assertNull($seat->postalCode);
    }

    // --- registrations ---

    public function testRegistrationsContainAllSixteenSourcesWithRecordedStatuses(): void
    {
        $registrations = self::fromFixture('find-cez.json')->registrations;

        self::assertCount(16, $registrations->statuses);
        self::assertSame(RegistrationStatus::Active, $registrations->status(AresRegister::Vat));
        self::assertSame(RegistrationStatus::Dissolved, $registrations->status(AresRegister::ExciseTax));
        self::assertSame(RegistrationStatus::Historical, $registrations->status(AresRegister::Healthcare));
        self::assertSame(RegistrationStatus::Nonexistent, $registrations->status(AresRegister::VatGroup));
    }

    public function testMissingRegistrationKeysAreNonexistent(): void
    {
        $registrations = self::fromFixture('find-natural-person.json')->registrations;

        self::assertCount(16, $registrations->statuses);
        self::assertSame(RegistrationStatus::Nonexistent, $registrations->status(AresRegister::Insolvency));
        self::assertSame(RegistrationStatus::Active, $registrations->status(AresRegister::Trade));
    }

    public function testRecordWithoutRegistrationListHasAllSourcesNonexistent(): void
    {
        $registrations = self::fromJson('{"icoId": "45274649", "obchodniJmeno": "T"}')->registrations;

        self::assertCount(16, $registrations->statuses);
        self::assertSame([], $registrations->active());
    }

    public function testUnknownRegistrationStatusMapsToUnknown(): void
    {
        $registrations = self::fromFixture('find-unknown-status.json')->registrations;

        self::assertSame(RegistrationStatus::Unknown, $registrations->status(AresRegister::Vat));
        self::assertSame(RegistrationStatus::Active, $registrations->status(AresRegister::PublicRegister));
    }

    // --- errors ---

    public function testMissingNameIsInvalidResponseNamingThePath(): void
    {
        $e = self::failure(FixtureLoader::read('Ares/find-missing-name.json'));

        self::assertSame('ARES: missing obchodniJmeno', $e->getMessage());
        self::assertSame(Source::Ares, $e->source);
    }

    public function testMissingAresIdIsInvalidResponse(): void
    {
        $e = self::failure('{"obchodniJmeno": "T"}');

        self::assertSame('ARES: missing icoId', $e->getMessage());
    }

    public function testUnparsableCompanyIdIsInvalidResponseWithoutTheValue(): void
    {
        $e = self::failure('{"icoId": "45274649", "obchodniJmeno": "T", "ico": "SENTINEL-ICO"}');

        self::assertSame('ARES: expected company id at ico', $e->getMessage());
        self::assertStringNotContainsString('SENTINEL-ICO', $e->getMessage());
    }

    public function testNineDigitCompanyIdIsInvalidResponseWithoutTheValue(): void
    {
        $e = self::failure('{"icoId": "45274649", "obchodniJmeno": "T", "ico": "045274649"}');

        self::assertSame('ARES: expected company id at ico', $e->getMessage());
        self::assertStringNotContainsString('045274649', $e->getMessage());
    }

    public function testUnparsableVatIdIsInvalidResponseWithoutTheValue(): void
    {
        $e = self::failure('{"icoId": "45274649", "obchodniJmeno": "T", "dic": "SENTINEL-DIC"}');

        self::assertSame('ARES: expected VAT id at dic', $e->getMessage());
        self::assertStringNotContainsString('SENTINEL-DIC', $e->getMessage());
    }

    public function testUnparsableGroupVatIdIsInvalidResponse(): void
    {
        $e = self::failure('{"icoId": "45274649", "obchodniJmeno": "T", "dicSkDph": "!!"}');

        self::assertSame('ARES: expected VAT id at dicSkDph', $e->getMessage());
    }

    public function testMalformedDateIsInvalidResponseNamingThePath(): void
    {
        $e = self::failure('{"icoId": "45274649", "obchodniJmeno": "T", "datumVzniku": "2026-13-45"}');

        self::assertSame('ARES: expected date (Y-m-d) at datumVzniku', $e->getMessage());
    }

    public function testErrorInsideTheSeatNamesTheNestedPath(): void
    {
        $e = self::failure('{"icoId": "45274649", "obchodniJmeno": "T", "sidlo": {"psc": 1.5}}');

        self::assertSame('ARES: expected string at sidlo.psc', $e->getMessage());
    }

    // --- address composition ---

    public function testStreetJoinsHouseAndOrientationNumber(): void
    {
        $address = self::addressOf(['nazevUlice' => 'Duhová', 'cisloDomovni' => 1444, 'cisloOrientacni' => 2]);

        self::assertSame('Duhová 1444/2', $address->street);
        self::assertSame('Duhová', $address->streetName);
        self::assertSame('2', $address->orientationNumber);
    }

    public function testOrientationLetterIsAppendedToTheOrientationNumber(): void
    {
        $address = self::addressOf([
            'nazevUlice' => 'Duhová',
            'cisloDomovni' => 1444,
            'cisloOrientacni' => 2,
            'cisloOrientacniPismeno' => 'a',
        ]);

        self::assertSame('Duhová 1444/2a', $address->street);
        self::assertSame('2a', $address->orientationNumber);
    }

    public function testStreetWithHouseNumberOnlyHasNoSlash(): void
    {
        $address = self::addressOf(['nazevUlice' => 'Duhová', 'cisloDomovni' => 1444]);

        self::assertSame('Duhová 1444', $address->street);
        self::assertNull($address->orientationNumber);
    }

    public function testDistrictNameReplacesAMissingStreetName(): void
    {
        $address = self::addressOf(['nazevCastiObce' => 'Michle', 'nazevObce' => 'Praha', 'cisloDomovni' => 15]);

        self::assertSame('Michle 15', $address->street);
        self::assertNull($address->streetName);
    }

    public function testMunicipalityNameReplacesAMissingStreetAndDistrictName(): void
    {
        $address = self::addressOf(['nazevObce' => 'Vesnice', 'cisloDomovni' => 7, 'cisloOrientacni' => 3]);

        self::assertSame('Vesnice 7/3', $address->street);
    }

    public function testStreetWithoutNumbersIsTheNameOnly(): void
    {
        $address = self::addressOf(['nazevUlice' => 'Duhová', 'nazevObce' => 'Praha']);

        self::assertSame('Duhová', $address->street);
    }

    public function testStreetIsNullWithoutAnyName(): void
    {
        $address = self::addressOf(['cisloDomovni' => 7, 'psc' => 11000]);

        self::assertNull($address->street);
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function providePostalCodes(): iterable
    {
        yield 'json number' => [14000, '14000'];
        yield 'digit string' => ['14000', '14000'];
        yield 'number with leading zero' => ['01234', '01234'];
    }

    #[DataProvider('providePostalCodes')]
    public function testPostalCodeAcceptsNumberAndString(mixed $psc, string $expected): void
    {
        $address = self::addressOf(['nazevUlice' => 'X', 'psc' => $psc]);

        self::assertSame($expected, $address->postalCode);
    }

    public function testPostalCodeFallsBackToTextualValue(): void
    {
        $address = self::addressOf(['nazevUlice' => 'X', 'pscTxt' => 'SK-81101']);

        self::assertSame('SK-81101', $address->postalCode);
    }

    public function testNumericPostalCodeWinsOverTextualValue(): void
    {
        $address = self::addressOf(['nazevUlice' => 'X', 'psc' => 14000, 'pscTxt' => '140 00']);

        self::assertSame('14000', $address->postalCode);
    }

    public function testHouseNumberAndRuianCodesAcceptStringsWithDigits(): void
    {
        $address = self::addressOf([
            'nazevUlice' => 'X',
            'cisloDomovni' => '12',
            'typCisloDomovni' => '2',
            'kodAdresnihoMista' => '25583697',
            'kodObce' => '554782',
        ]);

        self::assertSame('12', $address->houseNumber);
        self::assertSame(2, $address->houseNumberType);
        self::assertSame(25583697, $address->addressPointId);
        self::assertSame(554782, $address->municipalityCode);
    }

    public function testCountyAndRegionAreMapped(): void
    {
        $address = self::addressOf(['nazevOkresu' => 'Praha-západ', 'nazevKraje' => 'Středočeský kraj']);

        self::assertSame('Praha-západ', $address->county);
        self::assertSame('Středočeský kraj', $address->region);
    }
}
