<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Live;

use IdSign\BusinessRegisters\Ares\AresClient;
use IdSign\BusinessRegisters\Ares\AresRegister;
use IdSign\BusinessRegisters\Ares\RegistrationStatus;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

#[Group('live')]
#[CoversNothing]
final class AresLiveTest extends TestCase
{
    private static function client(): AresClient
    {
        return new AresClient(HttpClient::create());
    }

    public function testCezIsFoundWithVatIdSeatAndActiveVatRegistration(): void
    {
        $company = self::client()->find('45274649');

        self::assertNotNull($company);
        self::assertNotSame('', $company->name);
        self::assertNotNull($company->id);
        self::assertSame('45274649', $company->id->value);
        self::assertNotNull($company->vatId);
        self::assertSame('CZ45274649', (string) $company->vatId);
        self::assertNotNull($company->seat);
        self::assertNotNull($company->seat->street);
        self::assertNotSame('', $company->seat->street);
        self::assertTrue($company->registrations->isActive(AresRegister::Vat));
        self::assertFalse($company->isNaturalPerson());
    }

    public function testKomercniBankaIsAGroupVatMemberWithoutOwnVatId(): void
    {
        $company = self::client()->find('45317054');

        self::assertNotNull($company);
        self::assertNull($company->vatId);
        self::assertNotNull($company->groupVatId);
        self::assertSame('CZ699001182', (string) $company->groupVatId);
        self::assertNotNull($company->vatLookupId());
        self::assertTrue($company->vatLookupId()->equals($company->groupVatId));
        self::assertSame(RegistrationStatus::Active, $company->registrations->status(AresRegister::VatGroup));
    }

    public function testPragueIsFound(): void
    {
        $company = self::client()->find('00064581');

        self::assertNotNull($company);
        self::assertNotSame('', $company->name);
    }

    public function testDeletedSubjectIsNotFound(): void
    {
        self::assertNull(self::client()->find('04957423'));
    }
}
