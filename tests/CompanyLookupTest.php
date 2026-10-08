<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests;

use IdSign\BusinessRegisters\Adis\BankAccount;
use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\VatRegister;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\Adis\VatSubjects;
use IdSign\BusinessRegisters\Ares\Companies;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\CompanyDirectory;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\CompanyLookup;
use IdSign\BusinessRegisters\Exception\ExceptionInterface;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Isir\InsolvencyProceeding;
use IdSign\BusinessRegisters\Isir\InsolvencyProceedings;
use IdSign\BusinessRegisters\Isir\InsolvencyRegister;
use IdSign\BusinessRegisters\RiskFlag;
use IdSign\BusinessRegisters\Section;
use IdSign\BusinessRegisters\SectionStatus;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\Double\CompanyFactory;
use IdSign\BusinessRegisters\VatId;
use IdSign\BusinessRegisters\Vies\Vies;
use IdSign\BusinessRegisters\Vies\ViesResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompanyLookup::class)]
final class CompanyLookupTest extends TestCase
{
    private const string ICO = '45274649';

    private static function companyWithVatId(): Company
    {
        return CompanyFactory::create(id: self::ICO, vatId: VatId::parse('CZ45274649'));
    }

    private static function subject(SubjectType $type = SubjectType::VatPayer, string $vatId = 'CZ45274649'): VatSubject
    {
        return new VatSubject(
            vatId: VatId::parse($vatId),
            type: $type,
            unreliable: false,
            unreliableSince: null,
            taxOfficeCode: '013',
            name: 'Test a.s.',
            address: null,
            bankAccounts: [
                new BankAccount(
                    prefix: null,
                    number: '71504011',
                    bankCode: '0100',
                    publishedFrom: new \DateTimeImmutable('2013-04-01'),
                    publishedUntil: null,
                ),
            ],
            checkedAt: new \DateTimeImmutable('2026-10-03'),
        );
    }

    private static function viesResult(bool $valid = true, string $vatId = 'CZ45274649'): ViesResult
    {
        return new ViesResult(
            vatId: VatId::parse($vatId),
            valid: $valid,
            name: $valid ? 'Test a.s.' : null,
            address: null,
            nameMatch: null,
            streetMatch: null,
            postalCodeMatch: null,
            cityMatch: null,
            companyTypeMatch: null,
            consultationNumber: null,
            checkedAt: new \DateTimeImmutable('2026-10-03T10:00:00Z'),
        );
    }

    /**
     * ARES answers exactly once with the given company.
     */
    private function directoryAnswering(?Company $company): CompanyDirectory&MockObject
    {
        $directory = $this->createMock(CompanyDirectory::class);
        $directory->expects(self::once())->method('find')->willReturn($company)->seal();

        return $directory;
    }

    private function directoryStub(?Company $company): CompanyDirectory
    {
        $directory = self::createStub(CompanyDirectory::class);
        $directory->method('find')->willReturn($company);

        return $directory;
    }

    private function untouchedDirectory(): CompanyDirectory&MockObject
    {
        $directory = $this->createMock(CompanyDirectory::class);
        $directory->expects(self::never())->method('find')->seal();

        return $directory;
    }

    private function untouchedVatRegister(): VatRegister&MockObject
    {
        $register = $this->createMock(VatRegister::class);
        $register->expects(self::never())->method('find')->seal();

        return $register;
    }

    private function untouchedVies(): Vies&MockObject
    {
        $vies = $this->createMock(Vies::class);
        $vies->expects(self::never())->method('check')->seal();

        return $vies;
    }

    private function vatRegisterStub(?VatSubject $subject): VatRegister
    {
        $register = self::createStub(VatRegister::class);
        $register->method('find')->willReturn($subject);

        return $register;
    }

    private function vatRegisterFailingWith(\Throwable $exception): VatRegister
    {
        $register = self::createStub(VatRegister::class);
        $register->method('find')->willThrowException($exception);

        return $register;
    }

    private function viesStub(ViesResult $result): Vies
    {
        $vies = self::createStub(Vies::class);
        $vies->method('check')->willReturn($result);

        return $vies;
    }

    private function viesFailingWith(\Throwable $exception): Vies
    {
        $vies = self::createStub(Vies::class);
        $vies->method('check')->willThrowException($exception);

        return $vies;
    }

    /**
     * VatRegister that must be asked exactly once, for the given DIČ.
     */
    private function vatRegisterAskedFor(string $expectedVatId, ?VatSubject $answer = null): VatRegister&MockObject
    {
        $register = $this->createMock(VatRegister::class);
        $register->expects(self::once())
            ->method('find')
            ->with(self::callback(static fn (mixed $id): bool => $id instanceof VatId && (string) $id === $expectedVatId))
            ->willReturn($answer)
            ->seal();

        return $register;
    }

    /**
     * Vies that must be asked exactly once, for the given DIČ and requester.
     */
    private function viesAskedFor(string $expectedVatId, ?VatId $expectedRequester, ViesResult $answer): Vies&MockObject
    {
        $vies = $this->createMock(Vies::class);
        $vies->expects(self::once())
            ->method('check')
            ->with(
                self::callback(static fn (mixed $id): bool => $id instanceof VatId && (string) $id === $expectedVatId),
                self::callback(static fn (mixed $requester): bool => $requester === $expectedRequester
                    || ($requester instanceof VatId && $expectedRequester instanceof VatId && $requester->equals($expectedRequester))),
            )
            ->willReturn($answer)
            ->seal();

        return $vies;
    }

    private static function proceeding(bool $ongoing, int $caseNumber = 12575): InsolvencyProceeding
    {
        return new InsolvencyProceeding(
            companyId: CompanyId::fromRegister(self::ICO),
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
            stateCode: $ongoing ? 'KONKURS' : 'ODSKRTNUTA',
            detailUrl: null,
            otherDebtorInProceeding: false,
            insolvencyDeclaredOn: null,
            endedOn: $ongoing ? null : new \DateTimeImmutable('2022-07-01'),
        );
    }

    private static function proceedings(InsolvencyProceeding ...$proceedings): InsolvencyProceedings
    {
        return new InsolvencyProceedings(
            array_values($proceedings),
            new \DateTimeImmutable('2026-10-08 09:26:35', new \DateTimeZone('Europe/Prague')),
        );
    }

    private function untouchedInsolvencyRegister(): InsolvencyRegister&MockObject
    {
        $register = $this->createMock(InsolvencyRegister::class);
        $register->expects(self::never())->method('find')->seal();

        return $register;
    }

    private function insolvencyRegisterStub(InsolvencyProceedings $answer): InsolvencyRegister
    {
        $register = self::createStub(InsolvencyRegister::class);
        $register->method('find')->willReturn($answer);

        return $register;
    }

    private function insolvencyRegisterFailingWith(\Throwable $exception): InsolvencyRegister
    {
        $register = self::createStub(InsolvencyRegister::class);
        $register->method('find')->willThrowException($exception);

        return $register;
    }

    /**
     * InsolvencyRegister that must be asked exactly once, for the given company id.
     */
    private function insolvencyRegisterAskedFor(string $expectedId, InsolvencyProceedings $answer): InsolvencyRegister&MockObject
    {
        $register = $this->createMock(InsolvencyRegister::class);
        $register->expects(self::once())
            ->method('find')
            ->with(self::callback(static fn (mixed $id): bool => ($id instanceof CompanyId || \is_string($id)) && (string) $id === $expectedId))
            ->willReturn($answer)
            ->seal();

        return $register;
    }

    /**
     * InsolvencyRegister that must be asked exactly $calls times; every call appends the company id to $asked.
     *
     * @param array<array-key, InsolvencyProceedings|\Throwable> $answers keyed by the company id asked for (PHP turns digit-only ids into int keys)
     * @param \ArrayObject<int, string>                          $asked
     */
    private function insolvencyRegisterAnswering(array $answers, \ArrayObject $asked, int $calls): InsolvencyRegister&MockObject
    {
        $register = $this->createMock(InsolvencyRegister::class);
        $register->expects(self::exactly($calls))
            ->method('find')
            ->willReturnCallback(static function (CompanyId|string $id) use ($answers, $asked): InsolvencyProceedings {
                $asked[] = (string) $id;
                $answer = $answers[(string) $id] ?? throw new \LogicException(\sprintf('Unexpected ISIR call for %s', $id));
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            })
            ->seal();

        return $register;
    }

    /**
     * @return \ArrayObject<int, string> empty log of ISIR calls
     */
    private static function idLog(): \ArrayObject
    {
        return new \ArrayObject();
    }

    public function testUnknownCompanyYieldsNullWithoutAskingAnySection(): void
    {
        $lookup = new CompanyLookup($this->directoryAnswering(null), $this->untouchedVatRegister(), $this->untouchedVies());

        self::assertNull($lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies));
    }

    /**
     * @return iterable<string, array{ExceptionInterface&\Throwable}>
     */
    public static function provideAresFailures(): iterable
    {
        yield 'service unavailable' => [new ServiceUnavailable('ARES is down', Source::Ares)];
        yield 'invalid response' => [new InvalidResponse('ARES: missing obchodniJmeno', Source::Ares)];
        yield 'invalid input' => [new InvalidInput('Invalid company id')];
    }

    #[DataProvider('provideAresFailures')]
    public function testAresFailureIsNotCaughtAndNoSectionIsAsked(ExceptionInterface&\Throwable $failure): void
    {
        $directory = self::createStub(CompanyDirectory::class);
        $directory->method('find')->willThrowException($failure);
        $lookup = new CompanyLookup($directory, $this->untouchedVatRegister(), $this->untouchedVies());

        try {
            $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies);
            self::fail('The ARES failure must propagate.');
        } catch (\Throwable $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testWithoutSectionsOnlyAresIsAskedExactlyOnce(): void
    {
        $company = self::companyWithVatId();
        $lookup = new CompanyLookup($this->directoryAnswering($company), $this->untouchedVatRegister(), $this->untouchedVies());

        $profile = $lookup->byCompanyId(self::ICO);

        self::assertNotNull($profile);
        self::assertSame($company, $profile->company);
        self::assertNull($profile->vat);
        self::assertNull($profile->vies);
        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vies));
        self::assertTrue($profile->isComplete());
    }

    public function testLookupWorksWithOnlyADirectoryWhenNoSectionIsRequested(): void
    {
        $lookup = new CompanyLookup($this->directoryAnswering(self::companyWithVatId()));

        self::assertNotNull($lookup->byCompanyId(self::ICO));
    }

    public function testVatSectionWithoutAVatRegisterIsALogicErrorBeforeAnyRequest(): void
    {
        $lookup = new CompanyLookup($this->untouchedDirectory(), null, $this->untouchedVies());

        $this->expectException(\LogicException::class);

        $lookup->byCompanyId(self::ICO, Section::Vat);
    }

    public function testViesSectionWithoutViesIsALogicErrorBeforeAnyRequest(): void
    {
        $lookup = new CompanyLookup($this->untouchedDirectory(), $this->untouchedVatRegister());

        $this->expectException(\LogicException::class);

        $lookup->byCompanyId(self::ICO, Section::Vies);
    }

    public function testMissingClientOfALaterSectionStopsBeforeTheEarlierSectionIsAsked(): void
    {
        $lookup = new CompanyLookup($this->untouchedDirectory(), $this->untouchedVatRegister());

        $this->expectException(\LogicException::class);

        $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies);
    }

    public function testVatSectionIsOkWithTheRegisterSubject(): void
    {
        $subject = self::subject();
        $lookup = new CompanyLookup($this->directoryStub(self::companyWithVatId()), $this->vatRegisterAskedFor('CZ45274649', $subject));

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
        self::assertSame($subject, $profile->vat);
        self::assertNull($profile->vies);
        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vies));
        self::assertNull($profile->error(Section::Vat));
    }

    public function testVatSectionIsNotFoundWhenTheRegisterHasNoSubject(): void
    {
        $lookup = new CompanyLookup($this->directoryStub(self::companyWithVatId()), $this->vatRegisterStub(null));

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::NotFound, $profile->status(Section::Vat));
        self::assertNull($profile->vat);
        self::assertTrue($profile->isComplete());
    }

    public function testVatSectionIsNotApplicableWithoutAVatIdAndTheRegisterIsNotAsked(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(CompanyFactory::create(id: self::ICO)),
            $this->untouchedVatRegister(),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Vat));
        self::assertNull($profile->vat);
    }

    /**
     * @return iterable<string, array{ExceptionInterface&\Throwable}>
     */
    public static function provideSectionOutages(): iterable
    {
        yield 'service unavailable' => [new ServiceUnavailable('The service is down', Source::Adis)];
        yield 'invalid response' => [new InvalidResponse('ADIS: missing s:Body', Source::Adis)];
    }

    #[DataProvider('provideSectionOutages')]
    public function testVatSectionOutageIsRecordedAsUnavailableWithTheException(ExceptionInterface&\Throwable $outage): void
    {
        $lookup = new CompanyLookup($this->directoryStub(self::companyWithVatId()), $this->vatRegisterFailingWith($outage));

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Vat));
        self::assertSame($outage, $profile->error(Section::Vat));
        self::assertNull($profile->vat);
        self::assertFalse($profile->isComplete());
    }

    public function testViesSectionIsOkWithTheViesResult(): void
    {
        $result = self::viesResult();
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            null,
            $this->viesAskedFor('CZ45274649', null, $result),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
        self::assertSame($result, $profile->vies);
        self::assertNull($profile->vat);
        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vat));
    }

    public function testViesIsAskedWithTheConfiguredRequester(): void
    {
        $requester = VatId::parse('CZ27082440');
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            null,
            $this->viesAskedFor('CZ45274649', $requester, self::viesResult()),
            viesRequester: $requester,
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
    }

    public function testInvalidVatIdInViesIsAnOkSectionWithValidFalse(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            null,
            $this->viesStub(self::viesResult(false)),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
        self::assertNotNull($profile->vies);
        self::assertFalse($profile->vies->valid);
        self::assertTrue($profile->hasFlag(RiskFlag::ViesInvalid));
    }

    public function testViesSectionIsNotApplicableWithoutAVatIdAndViesIsNotAsked(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(CompanyFactory::create(id: self::ICO)),
            null,
            $this->untouchedVies(),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Vies));
        self::assertNull($profile->vies);
    }

    #[DataProvider('provideSectionOutages')]
    public function testViesSectionOutageIsRecordedAsUnavailableWithTheException(ExceptionInterface&\Throwable $outage): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            null,
            $this->viesFailingWith($outage),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Vies));
        self::assertSame($outage, $profile->error(Section::Vies));
        self::assertNull($profile->vies);
        self::assertFalse($profile->isComplete());
    }

    public function testVatOutageDoesNotAffectTheViesSection(): void
    {
        $outage = new ServiceUnavailable('ADIS is down', Source::Adis);
        $result = self::viesResult();
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterFailingWith($outage),
            $this->viesStub($result),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
        self::assertSame($result, $profile->vies);
        self::assertSame($outage, $profile->error(Section::Vat));
        self::assertNull($profile->error(Section::Vies));
        self::assertFalse($profile->isComplete());
    }

    public function testViesOutageDoesNotAffectTheVatSection(): void
    {
        $outage = new ServiceUnavailable('VIES is down', Source::Vies, 'SERVICE_UNAVAILABLE');
        $subject = self::subject();
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterStub($subject),
            $this->viesFailingWith($outage),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
        self::assertSame($subject, $profile->vat);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Vies));
        self::assertSame($outage, $profile->error(Section::Vies));
    }

    public function testBothSectionsOkMakeTheProfileComplete(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterStub(self::subject()),
            $this->viesStub(self::viesResult()),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies);

        self::assertNotNull($profile);
        self::assertTrue($profile->isComplete());
        self::assertSame([], $profile->flags());
    }

    public function testNoFlagIsRaisedFromAnUnavailableSection(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterFailingWith(new ServiceUnavailable('ADIS is down', Source::Adis)),
            $this->viesFailingWith(new ServiceUnavailable('VIES is down', Source::Vies)),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame([], $profile->flags());
    }

    public function testInvalidInputFromTheVatSectionIsRecordedAsRejectedWithTheException(): void
    {
        $failure = new InvalidInput('Only Czech VAT ids are accepted');
        $lookup = new CompanyLookup($this->directoryStub(self::companyWithVatId()), $this->vatRegisterFailingWith($failure));

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Rejected, $profile->status(Section::Vat));
        self::assertSame($failure, $profile->error(Section::Vat));
        self::assertNull($profile->vat);
        self::assertFalse($profile->isComplete());
    }

    /**
     * @return iterable<string, array{InvalidInput}>
     */
    public static function provideViesRejections(): iterable
    {
        yield 'requester info rejected' => [new InvalidInput('VIES rejected the request', 'INVALID_REQUESTER_INFO')];
        yield 'invalid input' => [new InvalidInput('VIES rejected the request', 'INVALID_INPUT')];
        yield 'http 400' => [new InvalidInput('VIES answered HTTP 400')];
    }

    #[DataProvider('provideViesRejections')]
    public function testInvalidInputFromTheViesSectionIsRecordedAsRejectedWithTheException(InvalidInput $rejection): void
    {
        $lookup = new CompanyLookup($this->directoryStub(self::companyWithVatId()), null, $this->viesFailingWith($rejection));

        $profile = $lookup->byCompanyId(self::ICO, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Rejected, $profile->status(Section::Vies));
        self::assertSame($rejection, $profile->error(Section::Vies));
        self::assertNull($profile->vies);
        self::assertFalse($profile->isComplete());
    }

    public function testViesRequesterErrorKeepsTheVatSectionAndItsErrorCode(): void
    {
        $rejection = new InvalidInput('VIES rejected the request', 'INVALID_REQUESTER_INFO');
        $subject = self::subject();
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterStub($subject),
            $this->viesFailingWith($rejection),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
        self::assertSame($subject, $profile->vat);
        self::assertSame(SectionStatus::Rejected, $profile->status(Section::Vies));
        $error = $profile->error(Section::Vies);
        self::assertInstanceOf(InvalidInput::class, $error);
        self::assertSame('INVALID_REQUESTER_INFO', $error->errorCode);
        self::assertNull($profile->error(Section::Vat));
    }

    public function testVatRejectionDoesNotAffectTheViesSection(): void
    {
        $rejection = new InvalidInput('Only Czech VAT ids are accepted');
        $result = self::viesResult();
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterFailingWith($rejection),
            $this->viesStub($result),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Rejected, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
        self::assertSame($result, $profile->vies);
    }

    public function testNoFlagIsRaisedFromARejectedSection(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterFailingWith(new InvalidInput('Only Czech VAT ids are accepted')),
            $this->viesFailingWith(new InvalidInput('VIES rejected the request', 'INVALID_REQUESTER_INFO')),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame([], $profile->flags());
    }

    public function testProfileShortcutIsUnknownWhenAdisRejectsTheRequest(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterFailingWith(new InvalidInput('Only Czech VAT ids are accepted')),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat);

        self::assertNotNull($profile);
        self::assertNull($profile->isVatPayer());
    }

    public function testVatGroupMemberIsLookedUpInAdisUnderTheGroupVatId(): void
    {
        $member = CompanyFactory::create(id: '45317054', vatId: null, groupVatId: VatId::parse('CZ699001182'));
        $subject = self::subject(SubjectType::VatGroup);
        $lookup = new CompanyLookup($this->directoryStub($member), $this->vatRegisterAskedFor('CZ699001182', $subject));

        $profile = $lookup->byCompanyId('45317054', Section::Vat);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
        self::assertSame($subject, $profile->vat);
    }

    public function testGroupVatIdWinsOverTheOwnVatId(): void
    {
        $member = CompanyFactory::create(
            id: '45317054',
            vatId: VatId::parse('CZ45317054'),
            groupVatId: VatId::parse('CZ699001182'),
        );
        $lookup = new CompanyLookup($this->directoryStub($member), $this->vatRegisterAskedFor('CZ699001182'));

        $profile = $lookup->byCompanyId('45317054', Section::Vat);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::NotFound, $profile->status(Section::Vat));
    }

    public function testVatGroupMemberIsCheckedInViesUnderTheGroupVatId(): void
    {
        $member = CompanyFactory::create(id: '45317054', vatId: null, groupVatId: VatId::parse('CZ699001182'));
        $lookup = new CompanyLookup($this->directoryStub($member), null, $this->viesAskedFor('CZ699001182', null, self::viesResult()));

        $profile = $lookup->byCompanyId('45317054', Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
    }

    public function testASectionRequestedTwiceIsAskedOnce(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterAskedFor('CZ45274649', self::subject()),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vat);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
    }

    public function testProfileShortcutAnswersTrueForAPayerFoundInAdis(): void
    {
        $lookup = new CompanyLookup($this->directoryStub(self::companyWithVatId()), $this->vatRegisterStub(self::subject()));

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat);

        self::assertNotNull($profile);
        self::assertTrue($profile->isVatPayer());
        self::assertTrue($profile->hasPublishedAccount('71504011/0100'));
    }

    public function testProfileShortcutIsUnknownWhenAdisIsUnavailable(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterFailingWith(new ServiceUnavailable('ADIS is down', Source::Adis)),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat);

        self::assertNotNull($profile);
        self::assertNull($profile->isVatPayer());
        self::assertNull($profile->hasPublishedAccount('71504011/0100'));
        self::assertFalse($profile->isComplete());
    }

    public function testProfileShortcutIsFalseForACompanyWithoutVatId(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(CompanyFactory::create(id: self::ICO)),
            $this->untouchedVatRegister(),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat);

        self::assertNotNull($profile);
        self::assertFalse($profile->isVatPayer());
    }

    public function testProfileShortcutThrowsWhenTheVatSectionWasNotRequested(): void
    {
        $lookup = new CompanyLookup($this->directoryStub(self::companyWithVatId()));
        $profile = $lookup->byCompanyId(self::ICO);

        self::assertNotNull($profile);
        $this->expectException(\LogicException::class);

        $profile->isVatPayer();
    }

    private static function bulkCompany(CompanyId|string $ico, ?string $vatId = null, ?string $groupVatId = null): Company
    {
        return CompanyFactory::create(
            id: $ico,
            vatId: null === $vatId ? null : VatId::parse($vatId),
            groupVatId: null === $groupVatId ? null : VatId::parse($groupVatId),
        );
    }

    /**
     * Sorted string forms of a VAT id list, whatever mix of VatId and string the facade passes.
     *
     * @return list<string>
     */
    private static function sortedVatIds(mixed $vatIds): array
    {
        if (!\is_array($vatIds)) {
            return [];
        }

        $strings = [];
        foreach ($vatIds as $vatId) {
            $strings[] = $vatId instanceof VatId || \is_string($vatId) ? (string) $vatId : '';
        }
        sort($strings);

        return $strings;
    }

    /**
     * @return \ArrayObject<int, array{string, ?string}> empty log of VIES calls
     */
    private static function callLog(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /**
     * ARES answers with the given companies; the test does not care how often it is asked.
     *
     * @param list<Company> $companies
     */
    private function directoryFindingMany(array $companies): CompanyDirectory
    {
        $directory = self::createStub(CompanyDirectory::class);
        $directory->method('findMany')->willReturn(new Companies($companies));

        return $directory;
    }

    /**
     * ARES that must be asked exactly once, in bulk.
     *
     * @param list<Company> $companies
     */
    private function directoryFindingManyOnce(array $companies): CompanyDirectory&MockObject
    {
        $directory = $this->createMock(CompanyDirectory::class);
        $directory->expects(self::once())->method('findMany')->willReturn(new Companies($companies))->seal();

        return $directory;
    }

    private function untouchedBulkDirectory(): CompanyDirectory&MockObject
    {
        $directory = $this->createMock(CompanyDirectory::class);
        $directory->expects(self::never())->method('findMany')->seal();

        return $directory;
    }

    private function untouchedBulkVatRegister(): VatRegister&MockObject
    {
        $register = $this->createMock(VatRegister::class);
        $register->expects(self::never())->method('findMany')->seal();

        return $register;
    }

    /**
     * VatRegister that must be asked exactly once, in bulk, for exactly the given VAT ids (any order).
     *
     * @param list<string>     $expectedVatIds
     * @param list<VatSubject> $subjects
     */
    private function bulkVatRegisterAskedFor(array $expectedVatIds, array $subjects): VatRegister&MockObject
    {
        sort($expectedVatIds);
        $register = $this->createMock(VatRegister::class);
        $register->expects(self::once())
            ->method('findMany')
            ->with(self::callback(static fn (mixed $vatIds): bool => self::sortedVatIds($vatIds) === $expectedVatIds))
            ->willReturn(new VatSubjects($subjects))
            ->seal();

        return $register;
    }

    private function bulkVatRegisterFailingWith(\Throwable $exception): VatRegister
    {
        $register = self::createStub(VatRegister::class);
        $register->method('findMany')->willThrowException($exception);

        return $register;
    }

    /**
     * Vies that must be asked exactly $calls times; every call is appended to $asked as [VAT id, requester].
     *
     * @param array<string, ViesResult|\Throwable>      $answers keyed by the VAT id asked for
     * @param \ArrayObject<int, array{string, ?string}> $asked
     */
    private function viesAnswering(array $answers, \ArrayObject $asked, int $calls): Vies&MockObject
    {
        $vies = $this->createMock(Vies::class);
        $vies->expects(self::exactly($calls))
            ->method('check')
            ->willReturnCallback(static function (VatId|string $vatId, VatId|string|null $requester = null) use ($answers, $asked): ViesResult {
                $asked[] = [(string) $vatId, null === $requester ? null : (string) $requester];
                $answer = $answers[(string) $vatId] ?? throw new \LogicException(\sprintf('Unexpected VIES call for %s', $vatId));
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            })
            ->seal();

        return $vies;
    }

    public function testBulkLookupOfOneHundredCompaniesWithTheVatSectionIsOneAresAndOneAdisRequest(): void
    {
        $ids = [];
        $companies = [];
        $subjects = [];
        $vatIds = [];
        for ($i = 1; $i <= 100; ++$i) {
            $ico = \sprintf('%08d', 10000000 + $i);
            $ids[] = CompanyId::fromRegister($ico);
            $companies[] = self::bulkCompany(CompanyId::fromRegister($ico), 'CZ'.$ico);
            $subjects[] = self::subject(vatId: 'CZ'.$ico);
            $vatIds[] = 'CZ'.$ico;
        }
        $lookup = new CompanyLookup($this->directoryFindingManyOnce($companies), $this->bulkVatRegisterAskedFor($vatIds, $subjects));

        $profiles = $lookup->byCompanyIds($ids, Section::Vat);

        self::assertCount(100, $profiles);
        foreach ($profiles as $profile) {
            self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
        }
    }

    public function testBulkLookupReturnsACompanyProfilesCollectionInTheOrderAresAnswered(): void
    {
        $cez = self::bulkCompany('45274649');
        $kb = self::bulkCompany('45317054');
        $lookup = new CompanyLookup($this->directoryFindingMany([$cez, $kb]));

        $profiles = $lookup->byCompanyIds(['45317054', '45274649']);

        self::assertCount(2, $profiles);
        self::assertSame([$cez, $kb], array_map(static fn ($profile) => $profile->company, $profiles->all()));
        self::assertSame($cez, $profiles->get('45274649')?->company);
    }

    public function testBulkLookupWithoutSectionsAsksOnlyAres(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryFindingManyOnce([self::bulkCompany('45274649', 'CZ45274649')]),
            $this->untouchedBulkVatRegister(),
            $this->untouchedVies(),
        );

        $profiles = $lookup->byCompanyIds(['45274649']);

        $profile = $profiles->get('45274649');
        self::assertNotNull($profile);
        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vies));
        self::assertNull($profile->vat);
        self::assertTrue($profile->isComplete());
    }

    public function testBulkLookupVatSectionIsOkWithTheRegisterSubjectOfEachCompany(): void
    {
        $subject = self::subject();
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649'), self::bulkCompany('28255933', 'CZ28255933')]),
            $this->bulkVatRegisterAskedFor(['CZ45274649', 'CZ28255933'], [$subject, self::subject(vatId: 'CZ28255933')]),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933'], Section::Vat);

        $profile = $profiles->get('45274649');
        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
        self::assertSame($subject, $profile->vat);
        self::assertTrue($profile->isVatPayer());
        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vies));
        self::assertSame('CZ28255933', (string) $profiles->get('28255933')?->vat?->vatId);
    }

    public function testBulkLookupAsksAdisOnceForAVatGroupAndEveryMemberGetsTheGroupSubject(): void
    {
        $group = self::subject(SubjectType::VatGroup, 'CZ699001182');
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([
                self::bulkCompany('45317054', null, 'CZ699001182'),
                self::bulkCompany('27082440', null, 'CZ699001182'),
                self::bulkCompany('45274649', 'CZ45274649'),
            ]),
            $this->bulkVatRegisterAskedFor(['CZ699001182', 'CZ45274649'], [$group, self::subject()]),
        );

        $profiles = $lookup->byCompanyIds(['45317054', '27082440', '45274649'], Section::Vat);

        $first = $profiles->get('45317054');
        $second = $profiles->get('27082440');
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($group, $first->vat);
        self::assertSame($group, $second->vat);
        self::assertSame(SectionStatus::Ok, $second->status(Section::Vat));
    }

    public function testBulkLookupAsksAdisUnderTheGroupVatIdEvenWhenTheMemberHasItsOwn(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45317054', 'CZ45317054', 'CZ699001182')]),
            $this->bulkVatRegisterAskedFor(['CZ699001182'], []),
        );

        $profiles = $lookup->byCompanyIds(['45317054'], Section::Vat);

        self::assertSame(SectionStatus::NotFound, $profiles->get('45317054')?->status(Section::Vat));
    }

    public function testBulkLookupMarksACompanyWithoutAVatIdNotApplicableInBothSectionsAndDoesNotAskForIt(): void
    {
        $asked = self::callLog();
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('00064581'), self::bulkCompany('45274649', 'CZ45274649')]),
            $this->bulkVatRegisterAskedFor(['CZ45274649'], [self::subject()]),
            $this->viesAnswering(['CZ45274649' => self::viesResult()], $asked, 1),
        );

        $profiles = $lookup->byCompanyIds(['00064581', '45274649'], Section::Vat, Section::Vies);

        $withoutVatId = $profiles->get('00064581');
        self::assertNotNull($withoutVatId);
        self::assertSame(SectionStatus::NotApplicable, $withoutVatId->status(Section::Vat));
        self::assertSame(SectionStatus::NotApplicable, $withoutVatId->status(Section::Vies));
        self::assertNull($withoutVatId->vat);
        self::assertNull($withoutVatId->vies);
        self::assertSame([['CZ45274649', null]], $asked->getArrayCopy());
    }

    public function testBulkLookupOfCompaniesWithoutAnyVatIdAsksNoSource(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('00064581')]),
            $this->untouchedBulkVatRegister(),
            $this->untouchedVies(),
        );

        $profile = $lookup->byCompanyIds(['00064581'], Section::Vat, Section::Vies)->get('00064581');

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Vies));
    }

    public function testBulkLookupVatIdUnknownToAdisIsNotFound(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649'), self::bulkCompany('28255933', 'CZ28255933')]),
            $this->bulkVatRegisterAskedFor(['CZ45274649', 'CZ28255933'], [self::subject()]),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933'], Section::Vat);

        $unknown = $profiles->get('28255933');
        self::assertNotNull($unknown);
        self::assertSame(SectionStatus::NotFound, $unknown->status(Section::Vat));
        self::assertNull($unknown->vat);
        self::assertTrue($unknown->isComplete());
        self::assertSame(SectionStatus::Ok, $profiles->get('45274649')?->status(Section::Vat));
    }

    public function testBulkLookupReportsAnIdUnknownToAresAsMissingAndAsksNoSectionForIt(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649')]),
            $this->bulkVatRegisterAskedFor(['CZ45274649'], [self::subject()]),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '04957423'], Section::Vat);

        self::assertCount(1, $profiles);
        self::assertFalse($profiles->has('04957423'));
        self::assertEquals([CompanyId::parse('04957423')], $profiles->missing(['45274649', '04957423']));
    }

    public function testBulkLookupWithAnAdisOutageMakesTheVatSectionUnavailableForEveryAskedCompany(): void
    {
        $outage = new ServiceUnavailable('ADIS is down', Source::Adis);
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([
                self::bulkCompany('45274649', 'CZ45274649'),
                self::bulkCompany('45317054', null, 'CZ699001182'),
                self::bulkCompany('00064581'),
            ]),
            $this->bulkVatRegisterFailingWith($outage),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '45317054', '00064581'], Section::Vat);

        foreach (['45274649', '45317054'] as $ico) {
            $profile = $profiles->get($ico);
            self::assertNotNull($profile);
            self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Vat));
            self::assertSame($outage, $profile->error(Section::Vat));
            self::assertNull($profile->vat);
            self::assertNull($profile->isVatPayer());
            self::assertFalse($profile->isComplete());
        }
        self::assertSame(SectionStatus::NotApplicable, $profiles->get('00064581')?->status(Section::Vat));
    }

    public function testBulkLookupWithAnInvalidAdisResponseMakesTheVatSectionUnavailable(): void
    {
        $outage = new InvalidResponse('ADIS: missing s:Body', Source::Adis);
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649')]),
            $this->bulkVatRegisterFailingWith($outage),
        );

        $profile = $lookup->byCompanyIds(['45274649'], Section::Vat)->get('45274649');

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Vat));
        self::assertSame($outage, $profile->error(Section::Vat));
    }

    public function testBulkLookupWithAnAdisRejectionMakesTheVatSectionRejectedForEveryAskedCompany(): void
    {
        $rejection = new InvalidInput('ADIS rejected the request');
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649'), self::bulkCompany('28255933', 'CZ28255933')]),
            $this->bulkVatRegisterFailingWith($rejection),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933'], Section::Vat);

        foreach ($profiles as $profile) {
            self::assertSame(SectionStatus::Rejected, $profile->status(Section::Vat));
            self::assertSame($rejection, $profile->error(Section::Vat));
            self::assertNull($profile->vat);
            self::assertNull($profile->isVatPayer());
        }
    }

    public function testBulkLookupRejectsOnlyTheCompanyWithANonCzechVatIdAndStillAsksAdisForTheOthers(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649'), self::bulkCompany('28255933', 'DE123456789')]),
            $this->bulkVatRegisterAskedFor(['CZ45274649'], [self::subject()]),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933'], Section::Vat);

        $foreign = $profiles->get('28255933');
        self::assertNotNull($foreign);
        self::assertSame(SectionStatus::Rejected, $foreign->status(Section::Vat));
        self::assertInstanceOf(InvalidInput::class, $foreign->error(Section::Vat));
        self::assertNull($foreign->vat);
        self::assertSame(SectionStatus::Ok, $profiles->get('45274649')?->status(Section::Vat));
    }

    #[DataProvider('provideAresFailures')]
    public function testBulkLookupAresFailureIsNotCaughtAndNoSectionIsAsked(ExceptionInterface&\Throwable $failure): void
    {
        $directory = self::createStub(CompanyDirectory::class);
        $directory->method('findMany')->willThrowException($failure);
        $lookup = new CompanyLookup($directory, $this->untouchedBulkVatRegister(), $this->untouchedVies());

        try {
            $lookup->byCompanyIds(['45274649'], Section::Vat, Section::Vies);
            self::fail('The ARES failure must propagate.');
        } catch (\Throwable $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testBulkLookupAsksViesOncePerCompanyWithAVatIdPassingTheRequester(): void
    {
        $requester = VatId::parse('CZ27082440');
        $asked = self::callLog();
        $first = self::viesResult();
        $second = self::viesResult(vatId: 'CZ28255933');
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649'), self::bulkCompany('28255933', 'CZ28255933')]),
            null,
            $this->viesAnswering(['CZ45274649' => $first, 'CZ28255933' => $second], $asked, 2),
            viesRequester: $requester,
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933'], Section::Vies);

        $firstProfile = $profiles->get('45274649');
        self::assertNotNull($firstProfile);
        self::assertSame($first, $firstProfile->vies);
        self::assertSame(SectionStatus::Ok, $firstProfile->status(Section::Vies));
        self::assertSame($second, $profiles->get('28255933')?->vies);
        self::assertEqualsCanonicalizing(
            [['CZ45274649', 'CZ27082440'], ['CZ28255933', 'CZ27082440']],
            $asked->getArrayCopy(),
        );
    }

    public function testBulkLookupViesIsCheckedUnderTheGroupVatIdOfAMember(): void
    {
        $asked = self::callLog();
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45317054', null, 'CZ699001182')]),
            null,
            $this->viesAnswering(['CZ699001182' => self::viesResult(vatId: 'CZ699001182')], $asked, 1),
        );

        $profile = $lookup->byCompanyIds(['45317054'], Section::Vies)->get('45317054');

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
        self::assertSame([['CZ699001182', null]], $asked->getArrayCopy());
    }

    public function testBulkLookupViesCapacityRejectionMakesOnlyThatCompanysViesSectionUnavailable(): void
    {
        $outage = new ServiceUnavailable('VIES is busy', Source::Vies, 'MS_MAX_CONCURRENT_REQ');
        $ok = self::viesResult(vatId: 'CZ28255933');
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649'), self::bulkCompany('28255933', 'CZ28255933')]),
            null,
            $this->viesAnswering(['CZ45274649' => $outage, 'CZ28255933' => $ok], self::callLog(), 2),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933'], Section::Vies);

        $busy = $profiles->get('45274649');
        self::assertNotNull($busy);
        self::assertSame(SectionStatus::Unavailable, $busy->status(Section::Vies));
        self::assertSame($outage, $busy->error(Section::Vies));
        self::assertNull($busy->vies);
        $fine = $profiles->get('28255933');
        self::assertNotNull($fine);
        self::assertSame(SectionStatus::Ok, $fine->status(Section::Vies));
        self::assertSame($ok, $fine->vies);
    }

    public function testBulkLookupViesRequesterErrorMakesThatCompanysViesSectionRejected(): void
    {
        $rejection = new InvalidInput('VIES rejected the request', 'INVALID_REQUESTER_INFO');
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649')]),
            null,
            $this->viesAnswering(['CZ45274649' => $rejection], self::callLog(), 1),
        );

        $profile = $lookup->byCompanyIds(['45274649'], Section::Vies)->get('45274649');

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Rejected, $profile->status(Section::Vies));
        self::assertSame($rejection, $profile->error(Section::Vies));
        self::assertNull($profile->vies);
    }

    public function testBulkLookupAsksViesOnceForAVatGroupAndEveryMemberGetsTheSameResult(): void
    {
        $ids = [];
        $companies = [];
        for ($i = 1; $i <= 5; ++$i) {
            $ico = \sprintf('%08d', 10000000 + $i);
            $ids[] = CompanyId::fromRegister($ico);
            $companies[] = self::bulkCompany(
                CompanyId::fromRegister($ico),
                1 === $i ? null : 'CZ'.$ico,
                'CZ699001182',
            );
        }
        $group = self::viesResult(vatId: 'CZ699001182');
        $asked = self::callLog();
        $lookup = new CompanyLookup(
            $this->directoryFindingManyOnce($companies),
            $this->bulkVatRegisterAskedFor(['CZ699001182'], [self::subject(SubjectType::VatGroup, 'CZ699001182')]),
            $this->viesAnswering(['CZ699001182' => $group], $asked, 1),
        );

        $profiles = $lookup->byCompanyIds($ids, Section::Vat, Section::Vies);

        self::assertCount(5, $profiles);
        foreach ($profiles as $profile) {
            self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
            self::assertSame($group, $profile->vies);
        }
        self::assertSame([['CZ699001182', null]], $asked->getArrayCopy());
    }

    /**
     * @return iterable<string, array{\Throwable, SectionStatus}>
     */
    public static function provideSharedViesFailures(): iterable
    {
        yield 'capacity' => [new ServiceUnavailable('VIES is busy', Source::Vies, 'MS_MAX_CONCURRENT_REQ'), SectionStatus::Unavailable];
        yield 'invalid response' => [new InvalidResponse('VIES answered garbage', Source::Vies), SectionStatus::Unavailable];
        yield 'invalid input' => [new InvalidInput('VIES rejected the request', 'INVALID_INPUT'), SectionStatus::Rejected];
    }

    #[DataProvider('provideSharedViesFailures')]
    public function testBulkLookupSharesAViesFailureAmongCompaniesWithTheSameLookupVatId(\Throwable $failure, SectionStatus $expected): void
    {
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([
                self::bulkCompany('45274649', 'CZ699001182'),
                self::bulkCompany('45317054', null, 'CZ699001182'),
            ]),
            null,
            $this->viesAnswering(['CZ699001182' => $failure], self::callLog(), 1),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '45317054'], Section::Vies);

        foreach ($profiles as $profile) {
            self::assertSame($expected, $profile->status(Section::Vies));
            self::assertSame($failure, $profile->error(Section::Vies));
            self::assertNull($profile->vies);
        }
    }

    public function testBulkLookupStopsAskingViesAfterARequesterRejectionAndRejectsTheRemainingCompanies(): void
    {
        $requester = VatId::parse('CZ12345678');
        $answered = self::viesResult();
        $rejection = new InvalidInput('VIES rejected the request', 'INVALID_REQUESTER_INFO');
        $asked = self::callLog();
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([
                self::bulkCompany('45274649', 'CZ45274649'),
                self::bulkCompany('28255933', 'CZ28255933'),
                self::bulkCompany('26168685', 'CZ45274649'),
                self::bulkCompany('27082440', 'CZ27082440'),
                self::bulkCompany('45317054', 'DE123456789'),
            ]),
            null,
            $this->viesAnswering(['CZ45274649' => $answered, 'CZ28255933' => $rejection], $asked, 2),
            viesRequester: $requester,
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933', '26168685', '27082440', '45317054'], Section::Vies);

        self::assertSame([['CZ45274649', 'CZ12345678'], ['CZ28255933', 'CZ12345678']], $asked->getArrayCopy());
        $first = $profiles->get('45274649');
        self::assertNotNull($first);
        self::assertSame(SectionStatus::Ok, $first->status(Section::Vies));
        self::assertSame($answered, $first->vies);
        $sameVatId = $profiles->get('26168685');
        self::assertNotNull($sameVatId);
        self::assertSame(SectionStatus::Ok, $sameVatId->status(Section::Vies));
        self::assertSame($answered, $sameVatId->vies);
        self::assertNull($sameVatId->error(Section::Vies));
        foreach (['28255933', '27082440', '45317054'] as $ico) {
            $profile = $profiles->get($ico);
            self::assertNotNull($profile);
            self::assertSame(SectionStatus::Rejected, $profile->status(Section::Vies));
            self::assertSame($rejection, $profile->error(Section::Vies));
            self::assertNull($profile->vies);
        }
    }

    public function testBulkLookupOfFiftyCompaniesWithARejectedRequesterIsOneViesRequest(): void
    {
        $ids = [];
        $companies = [];
        for ($i = 1; $i <= 50; ++$i) {
            $ico = \sprintf('%08d', 10000000 + $i);
            $ids[] = CompanyId::fromRegister($ico);
            $companies[] = self::bulkCompany(CompanyId::fromRegister($ico), 'CZ'.$ico);
        }
        $rejection = new InvalidInput('VIES rejected the request', 'INVALID_REQUESTER_INFO');
        $lookup = new CompanyLookup(
            $this->directoryFindingMany($companies),
            null,
            $this->viesAnswering(['CZ10000001' => $rejection], self::callLog(), 1),
            viesRequester: VatId::parse('CZ12345678'),
        );

        $profiles = $lookup->byCompanyIds($ids, Section::Vies);

        self::assertCount(50, $profiles);
        foreach ($profiles as $profile) {
            self::assertSame(SectionStatus::Rejected, $profile->status(Section::Vies));
            self::assertSame($rejection, $profile->error(Section::Vies));
        }
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function provideNonStoppingViesFailures(): iterable
    {
        yield 'invalid input' => [new InvalidInput('VIES rejected the request', 'INVALID_INPUT')];
        yield 'invalid input without a code' => [new InvalidInput('VIES answered HTTP 400')];
        yield 'capacity' => [new ServiceUnavailable('VIES is busy', Source::Vies, 'MS_MAX_CONCURRENT_REQ')];
        yield 'capacity with the requester code' => [new ServiceUnavailable('VIES answered HTTP 500', Source::Vies, 'INVALID_REQUESTER_INFO')];
        yield 'invalid response' => [new InvalidResponse('VIES answered garbage', Source::Vies)];
    }

    #[DataProvider('provideNonStoppingViesFailures')]
    public function testBulkLookupAViesFailureOfOneVatIdDoesNotStopViesForTheOthers(\Throwable $failure): void
    {
        $ok = self::viesResult(vatId: 'CZ28255933');
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649'), self::bulkCompany('28255933', 'CZ28255933')]),
            null,
            $this->viesAnswering(['CZ45274649' => $failure, 'CZ28255933' => $ok], self::callLog(), 2),
            viesRequester: VatId::parse('CZ12345678'),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933'], Section::Vies);

        $second = $profiles->get('28255933');
        self::assertNotNull($second);
        self::assertSame(SectionStatus::Ok, $second->status(Section::Vies));
        self::assertSame($ok, $second->vies);
    }

    public function testBulkLookupViesRequesterRejectionLeavesVatAndNotApplicableCompaniesUntouched(): void
    {
        $rejection = new InvalidInput('VIES rejected the request', 'INVALID_REQUESTER_INFO');
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([
                self::bulkCompany('45274649', 'CZ45274649'),
                self::bulkCompany('28255933', 'CZ28255933'),
                self::bulkCompany('27082440'),
                self::bulkCompany('45317054', 'DE123456789'),
            ]),
            $this->bulkVatRegisterAskedFor(['CZ45274649', 'CZ28255933'], [self::subject(), self::subject(vatId: 'CZ28255933')]),
            $this->viesAnswering(['CZ45274649' => $rejection], self::callLog(), 1),
            viesRequester: VatId::parse('CZ12345678'),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933', '27082440', '45317054'], Section::Vat, Section::Vies);

        foreach (['45274649', '28255933'] as $ico) {
            $profile = $profiles->get($ico);
            self::assertNotNull($profile);
            self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
            self::assertSame(SectionStatus::Rejected, $profile->status(Section::Vies));
            self::assertSame($rejection, $profile->error(Section::Vies));
        }
        $withoutVatId = $profiles->get('27082440');
        self::assertNotNull($withoutVatId);
        self::assertSame(SectionStatus::NotApplicable, $withoutVatId->status(Section::Vat));
        self::assertSame(SectionStatus::NotApplicable, $withoutVatId->status(Section::Vies));
        $foreign = $profiles->get('45317054');
        self::assertNotNull($foreign);
        self::assertSame(SectionStatus::Rejected, $foreign->status(Section::Vat));
        self::assertNotSame($rejection, $foreign->error(Section::Vat));
        self::assertSame(SectionStatus::Rejected, $foreign->status(Section::Vies));
        self::assertSame($rejection, $foreign->error(Section::Vies));
    }

    public function testBulkLookupAsksViesAgainInTheNextCall(): void
    {
        $rejection = new InvalidInput('VIES rejected the request', 'INVALID_REQUESTER_INFO');
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649')]),
            null,
            $this->viesAnswering(['CZ45274649' => $rejection], self::callLog(), 2),
            viesRequester: VatId::parse('CZ12345678'),
        );

        $first = $lookup->byCompanyIds(['45274649'], Section::Vies)->get('45274649');
        $second = $lookup->byCompanyIds(['45274649'], Section::Vies)->get('45274649');

        self::assertSame(SectionStatus::Rejected, $first?->status(Section::Vies));
        self::assertSame(SectionStatus::Rejected, $second?->status(Section::Vies));
    }

    public function testBulkLookupAdisOutageDoesNotAffectTheViesSection(): void
    {
        $outage = new ServiceUnavailable('ADIS is down', Source::Adis);
        $result = self::viesResult();
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649')]),
            $this->bulkVatRegisterFailingWith($outage),
            $this->viesAnswering(['CZ45274649' => $result], self::callLog(), 1),
        );

        $profile = $lookup->byCompanyIds(['45274649'], Section::Vat, Section::Vies)->get('45274649');

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
        self::assertSame($result, $profile->vies);
    }

    public function testBulkLookupASectionRequestedTwiceIsAskedOnce(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649')]),
            $this->bulkVatRegisterAskedFor(['CZ45274649'], [self::subject()]),
        );

        $profile = $lookup->byCompanyIds(['45274649'], Section::Vat, Section::Vat)->get('45274649');

        self::assertSame(SectionStatus::Ok, $profile?->status(Section::Vat));
    }

    public function testBulkLookupOfNoIdsAsksNoSectionAndReturnsAnEmptyCollection(): void
    {
        $register = self::createStub(VatRegister::class);
        $register->method('findMany')->willReturn(new VatSubjects([]));
        $lookup = new CompanyLookup($this->directoryFindingMany([]), $register, $this->untouchedVies());

        $profiles = $lookup->byCompanyIds([], Section::Vat, Section::Vies);

        self::assertCount(0, $profiles);
        self::assertSame([], $profiles->all());
    }

    public function testBulkLookupAcceptsACompanyIdThatFailsTheCheckDigit(): void
    {
        $company = CompanyFactory::create(id: CompanyId::fromRegister('00123562'));
        $lookup = new CompanyLookup($this->directoryFindingManyOnce([$company]));

        $profiles = $lookup->byCompanyIds([CompanyId::fromRegister('00123562')]);

        self::assertSame($company, $profiles->get(CompanyId::fromRegister('00123562'))?->company);
    }

    public function testBulkLookupRejectsAStringIdThatFailsTheCheckDigitBeforeAnyRequest(): void
    {
        $lookup = new CompanyLookup($this->untouchedBulkDirectory(), $this->untouchedBulkVatRegister(), $this->untouchedVies());

        $this->expectException(InvalidInput::class);

        $lookup->byCompanyIds(['45274649', '00123562'], Section::Vat, Section::Vies);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidIdElements(): iterable
    {
        yield 'int' => [45274649];
        yield 'null' => [null];
        yield 'array' => [['45274649']];
        yield 'object' => [new \stdClass()];
        yield 'empty string' => [''];
        yield 'letters' => ['abc'];
    }

    #[DataProvider('provideInvalidIdElements')]
    public function testBulkLookupRejectsAnInvalidElementBeforeAnyRequest(mixed $element): void
    {
        $lookup = new CompanyLookup($this->untouchedBulkDirectory(), $this->untouchedBulkVatRegister(), $this->untouchedVies());

        $this->expectException(InvalidInput::class);

        // reflection: PHPStan rejects a wrong-typed element in the typed list; the runtime check is what non-analysed callers hit
        new \ReflectionMethod($lookup, 'byCompanyIds')->invoke($lookup, ['45274649', $element], Section::Vat, Section::Vies);
    }

    public function testBulkLookupVatSectionWithoutAVatRegisterIsALogicErrorBeforeAnyRequest(): void
    {
        $lookup = new CompanyLookup($this->untouchedBulkDirectory(), null, $this->untouchedVies());

        $this->expectException(\LogicException::class);

        $lookup->byCompanyIds(['45274649'], Section::Vat);
    }

    public function testBulkLookupViesSectionWithoutViesIsALogicErrorBeforeAnyRequest(): void
    {
        $lookup = new CompanyLookup($this->untouchedBulkDirectory(), $this->untouchedBulkVatRegister());

        $this->expectException(\LogicException::class);

        $lookup->byCompanyIds(['45274649'], Section::Vies);
    }

    public function testBulkLookupMissingClientOfALaterSectionStopsBeforeTheEarlierSectionIsAsked(): void
    {
        $lookup = new CompanyLookup($this->untouchedBulkDirectory(), $this->untouchedBulkVatRegister());

        $this->expectException(\LogicException::class);

        $lookup->byCompanyIds(['45274649'], Section::Vat, Section::Vies);
    }

    public function testConstructorTakesTheInsolvencyRegisterBeforeTheViesRequester(): void
    {
        $requester = VatId::parse('CZ27082440');
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            null,
            $this->viesAskedFor('CZ45274649', $requester, self::viesResult()),
            $this->untouchedInsolvencyRegister(),
            $requester,
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vies);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
    }

    public function testInsolvencySectionIsOkWithOngoingProceedings(): void
    {
        $answer = self::proceedings(self::proceeding(true), self::proceeding(false, 7001));
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            insolvencyRegister: $this->insolvencyRegisterAskedFor(self::ICO, $answer),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Insolvency));
        self::assertSame($answer, $profile->insolvencies);
        self::assertNull($profile->error(Section::Insolvency));
        self::assertTrue($profile->isComplete());
        self::assertTrue($profile->hasFlag(RiskFlag::Insolvency));
        self::assertTrue($profile->isInInsolvency());
        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vies));
    }

    public function testInsolvencySectionWithEndedProceedingsOnlyIsOkAndRaisesNoFlag(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            insolvencyRegister: $this->insolvencyRegisterStub(self::proceedings(self::proceeding(false))),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Insolvency));
        self::assertFalse($profile->hasFlag(RiskFlag::Insolvency));
        self::assertFalse($profile->isInInsolvency());
    }

    public function testInsolvencySectionWithAnEmptyCollectionIsOkNotNotFound(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            insolvencyRegister: $this->insolvencyRegisterStub(self::proceedings()),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Insolvency));
        self::assertNotNull($profile->insolvencies);
        self::assertCount(0, $profile->insolvencies);
        self::assertTrue($profile->isComplete());
        self::assertSame([], $profile->flags());
        self::assertFalse($profile->isInInsolvency());
    }

    /**
     * @return iterable<string, array{ExceptionInterface&\Throwable}>
     */
    public static function provideInsolvencyOutages(): iterable
    {
        yield 'service unavailable' => [new ServiceUnavailable('The service is down', Source::Isir)];
        yield 'invalid response' => [new InvalidResponse('ISIR: missing s:Body', Source::Isir)];
    }

    #[DataProvider('provideInsolvencyOutages')]
    public function testInsolvencySectionOutageIsRecordedAsUnavailableWithTheException(ExceptionInterface&\Throwable $outage): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            insolvencyRegister: $this->insolvencyRegisterFailingWith($outage),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Insolvency));
        self::assertSame($outage, $profile->error(Section::Insolvency));
        self::assertNull($profile->insolvencies);
        self::assertFalse($profile->isComplete());
        self::assertNull($profile->isInInsolvency());
        self::assertFalse($profile->hasFlag(RiskFlag::Insolvency));
    }

    public function testInsolvencySectionRejectedRequestIsRecordedAsRejectedWithTheException(): void
    {
        $rejection = new InvalidInput('ISIR rejected the request');
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            insolvencyRegister: $this->insolvencyRegisterFailingWith($rejection),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Rejected, $profile->status(Section::Insolvency));
        self::assertSame($rejection, $profile->error(Section::Insolvency));
        self::assertNull($profile->insolvencies);
        self::assertFalse($profile->isComplete());
        self::assertNull($profile->isInInsolvency());
    }

    public function testInsolvencySectionIsOkForACompanyWithoutAVatIdWhileVatAndViesAreNotApplicable(): void
    {
        $answer = self::proceedings(self::proceeding(true));
        $lookup = new CompanyLookup(
            $this->directoryStub(CompanyFactory::create(id: self::ICO)),
            $this->untouchedVatRegister(),
            $this->untouchedVies(),
            $this->insolvencyRegisterAskedFor(self::ICO, $answer),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Vies));
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Insolvency));
        self::assertSame($answer, $profile->insolvencies);
    }

    public function testInsolvencySectionIsNotApplicableForASubjectWithoutACompanyIdAndTheRegisterIsNotAsked(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(CompanyFactory::create(id: null)),
            insolvencyRegister: $this->untouchedInsolvencyRegister(),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Insolvency));
        self::assertNull($profile->insolvencies);
        self::assertFalse($profile->isComplete());
        self::assertNull($profile->isInInsolvency());
    }

    public function testVatSectionStaysOkForASubjectWithoutACompanyIdWhileInsolvencyIsNotApplicable(): void
    {
        $subject = self::subject();
        $lookup = new CompanyLookup(
            $this->directoryStub(CompanyFactory::create(id: null, vatId: VatId::parse('CZ45274649'))),
            $this->vatRegisterAskedFor('CZ45274649', $subject),
            insolvencyRegister: $this->untouchedInsolvencyRegister(),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
        self::assertSame($subject, $profile->vat);
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Insolvency));
    }

    public function testInsolvencyIsAskedUnderTheCompanyIdNotUnderTheGroupVatId(): void
    {
        $member = self::bulkCompany('26168685', 'CZ26168685', 'CZ45274649');
        $lookup = new CompanyLookup(
            $this->directoryStub($member),
            insolvencyRegister: $this->insolvencyRegisterAskedFor('26168685', self::proceedings()),
        );

        $profile = $lookup->byCompanyId('26168685', Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Insolvency));
    }

    public function testInsolvencySectionWithoutARegisterIsALogicErrorBeforeAnyRequest(): void
    {
        $lookup = new CompanyLookup($this->untouchedDirectory(), $this->untouchedVatRegister(), $this->untouchedVies());

        $this->expectException(\LogicException::class);

        $lookup->byCompanyId(self::ICO, Section::Insolvency);
    }

    public function testMissingInsolvencyRegisterStopsBeforeTheEarlierSectionsAreAsked(): void
    {
        $lookup = new CompanyLookup($this->untouchedDirectory(), $this->untouchedVatRegister(), $this->untouchedVies());

        $this->expectException(\LogicException::class);

        $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies, Section::Insolvency);
    }

    public function testInsolvencySectionRequestedTwiceIsAskedOnce(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            insolvencyRegister: $this->insolvencyRegisterAskedFor(self::ICO, self::proceedings()),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Insolvency, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Insolvency));
    }

    public function testInsolvencyOutageLeavesTheVatAndViesSectionsOk(): void
    {
        $subject = self::subject();
        $result = self::viesResult();
        $outage = new ServiceUnavailable('ISIR is down', Source::Isir);
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterStub($subject),
            $this->viesStub($result),
            $this->insolvencyRegisterFailingWith($outage),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
        self::assertSame($subject, $profile->vat);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vies));
        self::assertSame($result, $profile->vies);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Insolvency));
        self::assertSame($outage, $profile->error(Section::Insolvency));
    }

    public function testVatAndViesOutagesLeaveTheInsolvencySectionOk(): void
    {
        $answer = self::proceedings(self::proceeding(true));
        $lookup = new CompanyLookup(
            $this->directoryStub(self::companyWithVatId()),
            $this->vatRegisterFailingWith(new ServiceUnavailable('ADIS is down', Source::Adis)),
            $this->viesFailingWith(new ServiceUnavailable('VIES is down', Source::Vies)),
            $this->insolvencyRegisterStub($answer),
        );

        $profile = $lookup->byCompanyId(self::ICO, Section::Vat, Section::Vies, Section::Insolvency);

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Vies));
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Insolvency));
        self::assertSame($answer, $profile->insolvencies);
    }

    public function testBulkLookupAsksIsirOncePerCompanyInTheOrderAresAnswered(): void
    {
        $first = self::proceedings(self::proceeding(true));
        $second = self::proceedings();
        $third = self::proceedings(self::proceeding(false));
        $asked = self::idLog();
        $lookup = new CompanyLookup(
            $this->directoryFindingManyOnce([
                self::bulkCompany('45274649'),
                self::bulkCompany('28255933'),
                self::bulkCompany('45317054'),
            ]),
            insolvencyRegister: $this->insolvencyRegisterAnswering(
                ['45274649' => $first, '28255933' => $second, '45317054' => $third],
                $asked,
                3,
            ),
        );

        $profiles = $lookup->byCompanyIds(['45317054', '45274649', '28255933'], Section::Insolvency);

        self::assertSame(['45274649', '28255933', '45317054'], $asked->getArrayCopy());
        self::assertCount(3, $profiles);
        self::assertSame($first, $profiles->get('45274649')?->insolvencies);
        self::assertSame($second, $profiles->get('28255933')?->insolvencies);
        self::assertSame($third, $profiles->get('45317054')?->insolvencies);
        $ongoing = $profiles->get('45274649');
        $ended = $profiles->get('45317054');
        self::assertTrue($ongoing->hasFlag(RiskFlag::Insolvency));
        self::assertFalse($ended->hasFlag(RiskFlag::Insolvency));
    }

    public function testBulkLookupIsirFailureOfOneCompanyLeavesTheOthersOk(): void
    {
        $outage = new ServiceUnavailable('ISIR is down', Source::Isir);
        $asked = self::idLog();
        $lookup = new CompanyLookup(
            $this->directoryFindingManyOnce([
                self::bulkCompany('45274649'),
                self::bulkCompany('28255933'),
                self::bulkCompany('45317054'),
            ]),
            insolvencyRegister: $this->insolvencyRegisterAnswering(
                ['45274649' => self::proceedings(), '28255933' => $outage, '45317054' => self::proceedings()],
                $asked,
                3,
            ),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933', '45317054'], Section::Insolvency);

        self::assertSame(['45274649', '28255933', '45317054'], $asked->getArrayCopy());
        self::assertSame(SectionStatus::Ok, $profiles->get('45274649')?->status(Section::Insolvency));
        self::assertSame(SectionStatus::Ok, $profiles->get('45317054')?->status(Section::Insolvency));
        $failed = $profiles->get('28255933');
        self::assertNotNull($failed);
        self::assertSame(SectionStatus::Unavailable, $failed->status(Section::Insolvency));
        self::assertSame($outage, $failed->error(Section::Insolvency));
        self::assertNull($failed->insolvencies);
    }

    public function testBulkLookupIsirInvalidResponseOfOneCompanyLeavesTheOthersOk(): void
    {
        $broken = new InvalidResponse('ISIR: missing s:Body/ns2:getIsirWsCuzkDataResponse/stav', Source::Isir);
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649'), self::bulkCompany('28255933')]),
            insolvencyRegister: $this->insolvencyRegisterAnswering(
                ['45274649' => $broken, '28255933' => self::proceedings()],
                self::idLog(),
                2,
            ),
        );

        $profiles = $lookup->byCompanyIds(['45274649', '28255933'], Section::Insolvency);

        $failed = $profiles->get('45274649');
        self::assertNotNull($failed);
        self::assertSame(SectionStatus::Unavailable, $failed->status(Section::Insolvency));
        self::assertSame($broken, $failed->error(Section::Insolvency));
        self::assertSame(SectionStatus::Ok, $profiles->get('28255933')?->status(Section::Insolvency));
    }

    public function testBulkLookupInsolvencySectionIsOkForCompaniesWithoutAVatIdWhileVatAndViesAreNotApplicable(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('00064581')]),
            $this->untouchedBulkVatRegister(),
            $this->untouchedVies(),
            $this->insolvencyRegisterAnswering(['00064581' => self::proceedings()], self::idLog(), 1),
        );

        $profile = $lookup->byCompanyIds(['00064581'], Section::Vat, Section::Vies, Section::Insolvency)->get('00064581');

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Vies));
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Insolvency));
    }

    public function testBulkLookupVatOutageLeavesTheInsolvencySectionOkAndIsirOutageLeavesVatOk(): void
    {
        $subject = self::subject();
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649')]),
            $this->bulkVatRegisterFailingWith(new ServiceUnavailable('ADIS is down', Source::Adis)),
            insolvencyRegister: $this->insolvencyRegisterAnswering(['45274649' => self::proceedings()], self::idLog(), 1),
        );
        $profile = $lookup->byCompanyIds(['45274649'], Section::Vat, Section::Insolvency)->get('45274649');

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Insolvency));

        $lookup = new CompanyLookup(
            $this->directoryFindingMany([self::bulkCompany('45274649', 'CZ45274649')]),
            $this->bulkVatRegisterAskedFor(['CZ45274649'], [$subject]),
            insolvencyRegister: $this->insolvencyRegisterFailingWith(new ServiceUnavailable('ISIR is down', Source::Isir)),
        );
        $profile = $lookup->byCompanyIds(['45274649'], Section::Vat, Section::Insolvency)->get('45274649');

        self::assertNotNull($profile);
        self::assertSame(SectionStatus::Ok, $profile->status(Section::Vat));
        self::assertSame($subject, $profile->vat);
        self::assertSame(SectionStatus::Unavailable, $profile->status(Section::Insolvency));
    }

    public function testBulkLookupOfNoIdsWithTheInsolvencySectionAsksNothing(): void
    {
        $lookup = new CompanyLookup(
            $this->directoryFindingMany([]),
            insolvencyRegister: $this->untouchedInsolvencyRegister(),
        );

        $profiles = $lookup->byCompanyIds([], Section::Insolvency);

        self::assertCount(0, $profiles);
    }

    public function testBulkLookupWithoutAnInsolvencyRegisterIsALogicErrorBeforeAnyRequest(): void
    {
        $lookup = new CompanyLookup($this->untouchedBulkDirectory(), $this->untouchedBulkVatRegister(), $this->untouchedVies());

        $this->expectException(\LogicException::class);

        $lookup->byCompanyIds(['45274649'], Section::Vat, Section::Vies, Section::Insolvency);
    }
}
