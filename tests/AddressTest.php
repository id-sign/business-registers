<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests;

use IdSign\BusinessRegisters\Address;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Address::class)]
final class AddressTest extends TestCase
{
    public function testEveryPropertyDefaultsToNull(): void
    {
        $address = new Address();

        foreach ((new \ReflectionClass($address))->getProperties() as $property) {
            self::assertNull($property->getValue($address), $property->getName());
        }
    }

    public function testPropertiesAreExactlyTheDocumentedSixteenInDocumentedOrder(): void
    {
        $names = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(Address::class))->getProperties(),
        );

        self::assertSame([
            'text',
            'street',
            'streetName',
            'houseNumber',
            'houseNumberType',
            'orientationNumber',
            'district',
            'cityDistrict',
            'city',
            'postalCode',
            'county',
            'region',
            'countryCode',
            'countryName',
            'addressPointId',
            'municipalityCode',
        ], $names);
    }

    public function testValuesAreKeptUnderTheirNamedArguments(): void
    {
        $address = new Address(
            text: 'Duhová 1444/2, Praha',
            street: 'Duhová 1444/2',
            streetName: 'Duhová',
            houseNumber: '1444',
            houseNumberType: 1,
            orientationNumber: '2a',
            district: 'Michle',
            cityDistrict: 'Praha 4',
            city: 'Praha',
            postalCode: '14000',
            county: 'Hlavní město Praha',
            region: 'Hlavní město Praha',
            countryCode: 'CZ',
            countryName: 'Česká republika',
            addressPointId: 12345678,
            municipalityCode: 554782,
        );

        self::assertSame('Duhová 1444/2, Praha', $address->text);
        self::assertSame('Duhová 1444/2', $address->street);
        self::assertSame('Duhová', $address->streetName);
        self::assertSame('1444', $address->houseNumber);
        self::assertSame(1, $address->houseNumberType);
        self::assertSame('2a', $address->orientationNumber);
        self::assertSame('Michle', $address->district);
        self::assertSame('Praha 4', $address->cityDistrict);
        self::assertSame('Praha', $address->city);
        self::assertSame('14000', $address->postalCode);
        self::assertSame('Hlavní město Praha', $address->county);
        self::assertSame('Hlavní město Praha', $address->region);
        self::assertSame('CZ', $address->countryCode);
        self::assertSame('Česká republika', $address->countryName);
        self::assertSame(12345678, $address->addressPointId);
        self::assertSame(554782, $address->municipalityCode);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function providePostalCodes(): iterable
    {
        yield 'five digits' => ['14000', '140 00'];
        yield 'five digits other' => ['60200', '602 00'];
        yield 'already formatted' => ['140 00', '140 00'];
        yield 'four digits unchanged' => ['1234', '1234'];
        yield 'six digits unchanged' => ['123456', '123456'];
        yield 'five characters with a letter unchanged' => ['1400A', '1400A'];
        yield 'foreign code unchanged' => ['SW1A 1AA', 'SW1A 1AA'];
        yield 'missing' => [null, null];
    }

    #[DataProvider('providePostalCodes')]
    public function testPostalCodeIsFormattedOnlyWhenItHasFiveDigits(?string $postalCode, ?string $expected): void
    {
        self::assertSame($expected, (new Address(postalCode: $postalCode))->postalCodeFormatted());
    }

    public function testPostalCodeWithTrailingNewlineIsReturnedUnchanged(): void
    {
        self::assertSame("14000\n", (new Address(postalCode: "14000\n"))->postalCodeFormatted());
    }

    public function testFormattingDoesNotChangeTheStoredPostalCode(): void
    {
        $address = new Address(postalCode: '14000');

        $address->postalCodeFormatted();

        self::assertSame('14000', $address->postalCode);
    }
}
