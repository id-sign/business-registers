<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests;

use IdSign\BusinessRegisters\Adis\BankAccount;
use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\VatRegister;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\CompanyDirectory;
use IdSign\BusinessRegisters\CompanyLookup;
use IdSign\BusinessRegisters\Exception\ExceptionInterface;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
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

    private static function subject(SubjectType $type = SubjectType::VatPayer): VatSubject
    {
        return new VatSubject(
            vatId: VatId::parse('CZ45274649'),
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

    private static function viesResult(bool $valid = true): ViesResult
    {
        return new ViesResult(
            vatId: VatId::parse('CZ45274649'),
            valid: $valid,
            name: $valid ? 'Test a.s.' : null,
            address: null,
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
            $requester,
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

    public function testInvalidInputFromTheVatSectionIsNotCaught(): void
    {
        $failure = new InvalidInput('Only Czech VAT ids are accepted');
        $lookup = new CompanyLookup($this->directoryStub(self::companyWithVatId()), $this->vatRegisterFailingWith($failure));

        try {
            $lookup->byCompanyId(self::ICO, Section::Vat);
            self::fail('InvalidInput must propagate.');
        } catch (InvalidInput $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testInvalidInputFromTheViesSectionIsNotCaught(): void
    {
        $failure = new InvalidInput('Invalid VAT id', 'INVALID_INPUT');
        $lookup = new CompanyLookup($this->directoryStub(self::companyWithVatId()), null, $this->viesFailingWith($failure));

        try {
            $lookup->byCompanyId(self::ICO, Section::Vies);
            self::fail('InvalidInput must propagate.');
        } catch (InvalidInput $caught) {
            self::assertSame($failure, $caught);
        }
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
}
