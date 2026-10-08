<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Ares;

use IdSign\BusinessRegisters\Ares\AresRegister;
use IdSign\BusinessRegisters\Ares\Registrations;
use IdSign\BusinessRegisters\Ares\RegistrationStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Registrations::class)]
#[CoversClass(AresRegister::class)]
#[CoversClass(RegistrationStatus::class)]
final class RegistrationsTest extends TestCase
{
    /**
     * @param array<string, RegistrationStatus> $overrides keyed by AresRegister::value
     */
    private static function registrations(array $overrides): Registrations
    {
        $statuses = [];
        foreach (AresRegister::cases() as $register) {
            $statuses[$register->value] = $overrides[$register->value] ?? RegistrationStatus::Nonexistent;
        }

        return new Registrations($statuses);
    }

    public function testRegisterEnumHasSixteenCasesBackedByTheKeySuffix(): void
    {
        $values = array_map(
            static fn (\ReflectionEnumBackedCase $case): int|string => $case->getBackingValue(),
            (new \ReflectionEnum(AresRegister::class))->getCases(),
        );

        self::assertCount(16, $values);
        self::assertSame(
            ['Ros', 'Vr', 'Res', 'Rzp', 'Nrpzs', 'Rpsh', 'Rcns', 'Szr', 'Dph', 'SkDph', 'Sd', 'Ir', 'Ceu', 'Rs', 'Red', 'Monitor'],
            $values,
        );
    }

    /**
     * @return iterable<string, array{string, AresRegister}>
     */
    public static function provideRegisterValues(): iterable
    {
        yield 'persons register' => ['Ros', AresRegister::PersonsRegister];
        yield 'public register' => ['Vr', AresRegister::PublicRegister];
        yield 'vat' => ['Dph', AresRegister::Vat];
        yield 'vat group' => ['SkDph', AresRegister::VatGroup];
        yield 'insolvency' => ['Ir', AresRegister::Insolvency];
        yield 'state accounting' => ['Monitor', AresRegister::StateAccounting];
    }

    #[DataProvider('provideRegisterValues')]
    public function testRegisterCaseNamesFollowTheSpecification(string $value, AresRegister $expected): void
    {
        self::assertSame($expected, AresRegister::from($value));
    }

    /**
     * @return iterable<string, array{string, RegistrationStatus}>
     */
    public static function provideStatusValues(): iterable
    {
        yield 'active' => ['AKTIVNI', RegistrationStatus::Active];
        yield 'historical' => ['HISTORICKY', RegistrationStatus::Historical];
        yield 'ended' => ['ZANIKLY', RegistrationStatus::Ended];
        yield 'nonexistent' => ['NEEXISTUJICI', RegistrationStatus::Nonexistent];
        yield 'suspended' => ['POZASTAVENY', RegistrationStatus::Suspended];
        yield 'future' => ['BUDOUCI', RegistrationStatus::Future];
        yield 'logically deleted' => ['LOGICKY_SMAZANY', RegistrationStatus::LogicallyDeleted];
        yield 'unknown' => ['UNKNOWN', RegistrationStatus::Unknown];
    }

    #[DataProvider('provideStatusValues')]
    public function testStatusEnumIsBackedBySourceValues(string $value, RegistrationStatus $expected): void
    {
        self::assertSame($expected, RegistrationStatus::from($value));
    }

    public function testStatusReturnsTheStoredStatusPerRegister(): void
    {
        $registrations = self::registrations([
            AresRegister::Vat->value => RegistrationStatus::Active,
            AresRegister::VatGroup->value => RegistrationStatus::Ended,
        ]);

        self::assertSame(RegistrationStatus::Active, $registrations->status(AresRegister::Vat));
        self::assertSame(RegistrationStatus::Ended, $registrations->status(AresRegister::VatGroup));
        self::assertSame(RegistrationStatus::Nonexistent, $registrations->status(AresRegister::Insolvency));
    }

    public function testIsActiveIsTrueOnlyForActiveStatus(): void
    {
        $registrations = self::registrations([
            AresRegister::Vat->value => RegistrationStatus::Active,
            AresRegister::Trade->value => RegistrationStatus::Historical,
            AresRegister::Insolvency->value => RegistrationStatus::Suspended,
        ]);

        self::assertTrue($registrations->isActive(AresRegister::Vat));
        self::assertFalse($registrations->isActive(AresRegister::Trade));
        self::assertFalse($registrations->isActive(AresRegister::Insolvency));
        self::assertFalse($registrations->isActive(AresRegister::PublicRegister));
    }

    public function testActiveListsAllActiveRegistersInEnumOrder(): void
    {
        $registrations = self::registrations([
            AresRegister::Vat->value => RegistrationStatus::Active,
            AresRegister::PersonsRegister->value => RegistrationStatus::Active,
            AresRegister::Trade->value => RegistrationStatus::Historical,
        ]);

        self::assertSame([AresRegister::PersonsRegister, AresRegister::Vat], $registrations->active());
    }

    public function testActiveIsEmptyWhenNothingIsActive(): void
    {
        self::assertSame([], self::registrations([])->active());
    }
}
