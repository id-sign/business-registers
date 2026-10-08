<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Isir;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Isir\InsolvencyProceeding;
use IdSign\BusinessRegisters\Isir\InsolvencyProceedings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InsolvencyProceedings::class)]
final class InsolvencyProceedingsTest extends TestCase
{
    private static function proceeding(int $caseNumber, ?string $stateCode): InsolvencyProceeding
    {
        return new InsolvencyProceeding(
            companyId: CompanyId::fromRegister('25083325'),
            birthNumber: null,
            senate: 95,
            caseType: 'INS',
            caseNumber: $caseNumber,
            year: 2022,
            court: null,
            bornOn: null,
            titleBefore: null,
            titleAfter: null,
            firstName: null,
            name: null,
            addressKind: null,
            address: null,
            stateCode: $stateCode,
            detailUrl: null,
            otherDebtorInProceeding: false,
            insolvencyDeclaredOn: null,
            endedOn: null,
        );
    }

    private static function synchronisedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-08 09:26:35', new \DateTimeZone('Europe/Prague'));
    }

    public function testCollectionExposesItsProceedingsAndTheSynchronisationTime(): void
    {
        $first = self::proceeding(1, 'KONKURS');
        $second = self::proceeding(2, 'ODSKRTNUTA');
        $synchronisedAt = self::synchronisedAt();

        $proceedings = new InsolvencyProceedings([$first, $second], $synchronisedAt);

        self::assertSame([$first, $second], $proceedings->proceedings);
        self::assertSame($synchronisedAt, $proceedings->synchronisedAt);
    }

    public function testSynchronisationTimeMayBeUnknown(): void
    {
        self::assertNull(new InsolvencyProceedings([], null)->synchronisedAt);
    }

    public function testCountAndIterationFollowTheResponseOrder(): void
    {
        $first = self::proceeding(1, 'KONKURS');
        $second = self::proceeding(2, 'ODSKRTNUTA');
        $third = self::proceeding(3, null);

        $proceedings = new InsolvencyProceedings([$first, $second, $third], null);

        self::assertCount(3, $proceedings);
        self::assertSame([$first, $second, $third], iterator_to_array($proceedings));
    }

    public function testEmptyCollectionHasNothingOngoing(): void
    {
        $proceedings = new InsolvencyProceedings([], self::synchronisedAt());

        self::assertCount(0, $proceedings);
        self::assertSame([], iterator_to_array($proceedings));
        self::assertSame([], $proceedings->ongoing());
        self::assertFalse($proceedings->hasOngoing());
    }

    public function testOngoingKeepsOnlyOngoingProceedingsInOrderAsAList(): void
    {
        $ended = self::proceeding(1, 'ODSKRTNUTA');
        $petition = self::proceeding(2, 'NEVYRIZENA');
        $alsoEnded = self::proceeding(3, 'PRAVOMOCNA');
        $bankruptcy = self::proceeding(4, 'KONKURS');

        $proceedings = new InsolvencyProceedings([$ended, $petition, $alsoEnded, $bankruptcy], null);

        self::assertSame([$petition, $bankruptcy], $proceedings->ongoing());
        self::assertTrue($proceedings->hasOngoing());
    }

    public function testCollectionOfEndedProceedingsOnlyHasNothingOngoing(): void
    {
        $proceedings = new InsolvencyProceedings([self::proceeding(1, 'ODSKRTNUTA'), self::proceeding(2, 'PRAVOMOCNA')], null);

        self::assertSame([], $proceedings->ongoing());
        self::assertFalse($proceedings->hasOngoing());
    }

    public function testProceedingWithAnUnknownStateCountsAsOngoing(): void
    {
        $unknown = self::proceeding(1, 'SOMETHING NEW');

        $proceedings = new InsolvencyProceedings([$unknown], null);

        self::assertSame([$unknown], $proceedings->ongoing());
        self::assertTrue($proceedings->hasOngoing());
    }

    public function testCollectionIsAFinalReadonlyCountableIteratorAggregate(): void
    {
        $class = new \ReflectionClass(InsolvencyProceedings::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertTrue($class->implementsInterface(\IteratorAggregate::class));
        self::assertTrue($class->implementsInterface(\Countable::class));
        self::assertTrue($class->getProperty('proceedings')->isPublic());
        self::assertTrue($class->getProperty('synchronisedAt')->isPublic());
    }
}
