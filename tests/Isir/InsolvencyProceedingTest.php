<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Isir;

use IdSign\BusinessRegisters\Address;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Isir\InsolvencyProceeding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InsolvencyProceeding::class)]
final class InsolvencyProceedingTest extends TestCase
{
    private static function proceeding(
        ?string $stateCode = 'KONKURS',
        ?\DateTimeImmutable $endedOn = null,
        int $senate = 95,
        string $caseType = 'INS',
        int $caseNumber = 12575,
        int $year = 2022,
    ): InsolvencyProceeding {
        return new InsolvencyProceeding(
            companyId: CompanyId::fromRegister('25083325'),
            birthNumber: null,
            senate: $senate,
            caseType: $caseType,
            caseNumber: $caseNumber,
            year: $year,
            court: 'Městský soud v Praze',
            bornOn: null,
            titleBefore: null,
            titleAfter: null,
            firstName: null,
            name: 'Sberbank CZ, a.s. v likvidaci',
            addressKind: 'SÍDLO FY',
            address: new Address(city: 'Praha 5'),
            stateCode: $stateCode,
            detailUrl: null,
            otherDebtorInProceeding: false,
            insolvencyDeclaredOn: new \DateTimeImmutable('2022-09-13', new \DateTimeZone('Europe/Prague')),
            endedOn: $endedOn,
        );
    }

    /**
     * @return iterable<string, array{?string, bool, bool}> state, whether an end date is set, expected isOngoing()
     */
    public static function provideStatesAndEndDates(): iterable
    {
        $ended = ['ODSKRTNUTA', 'PRAVOMOCNA', 'VYRIZENA', 'MYLNÝ ZÁP.'];
        $notEnded = ['NEVYRIZENA', 'ÚPADEK', 'KONKURS', 'REORGANIZ', 'ODDLUŽENÍ', 'MORATORIUM'];

        foreach ($ended as $state) {
            yield $state.' without an end date' => [$state, false, false];
            yield $state.' with an end date' => [$state, true, false];
        }

        foreach ($notEnded as $state) {
            yield $state.' without an end date' => [$state, false, true];
            yield $state.' with an end date' => [$state, true, false];
        }

        yield 'missing state without an end date' => [null, false, true];
        yield 'missing state with an end date' => [null, true, false];
        yield 'unknown state without an end date' => ['SOMETHING NEW', false, true];
        yield 'unknown state with an end date' => ['SOMETHING NEW', true, false];
        yield 'ended state in lower case is not recognised' => ['odskrtnuta', false, true];
        yield 'ended state with trailing text is not recognised' => ['ODSKRTNUTA X', false, true];
    }

    #[DataProvider('provideStatesAndEndDates')]
    public function testProceedingIsOngoingUnlessItHasAnEndDateOrAnEndedState(?string $stateCode, bool $hasEndDate, bool $expected): void
    {
        $endedOn = $hasEndDate ? new \DateTimeImmutable('2022-07-01', new \DateTimeZone('Europe/Prague')) : null;

        self::assertSame($expected, self::proceeding($stateCode, $endedOn)->isOngoing());
    }

    public function testReferenceIsSenateCaseTypeNumberAndYear(): void
    {
        self::assertSame('95 INS 12575/2022', self::proceeding()->reference());
    }

    public function testReferenceDoesNotPadItsNumbers(): void
    {
        self::assertSame('5 INS 1/2020', self::proceeding(senate: 5, caseNumber: 1, year: 2020)->reference());
    }

    public function testStateCodeStaysAFreeStringAndIsExposedAsReceived(): void
    {
        self::assertSame('MYLNÝ ZÁP.', self::proceeding('MYLNÝ ZÁP.')->stateCode);
        self::assertNull(self::proceeding(null)->stateCode);
    }

    public function testProceedingIsAFinalReadonlyValue(): void
    {
        $class = new \ReflectionClass(InsolvencyProceeding::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }
}
