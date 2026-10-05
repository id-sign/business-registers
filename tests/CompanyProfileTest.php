<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests;

use IdSign\BusinessRegisters\Adis\BankAccount;
use IdSign\BusinessRegisters\Adis\Internal\ResponseParser;
use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\Ares\AresRegister;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\Internal\CompanyMapper;
use IdSign\BusinessRegisters\Ares\RegistrationStatus;
use IdSign\BusinessRegisters\CompanyProfile;
use IdSign\BusinessRegisters\Exception\ExceptionInterface;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\JsonReader;
use IdSign\BusinessRegisters\RiskFlag;
use IdSign\BusinessRegisters\Section;
use IdSign\BusinessRegisters\SectionStatus;
use IdSign\BusinessRegisters\Source;
use IdSign\BusinessRegisters\Tests\Double\CompanyFactory;
use IdSign\BusinessRegisters\VatId;
use IdSign\BusinessRegisters\Vies\ViesResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompanyProfile::class)]
#[CoversClass(RiskFlag::class)]
#[CoversClass(Section::class)]
#[CoversClass(SectionStatus::class)]
final class CompanyProfileTest extends TestCase
{
    /**
     * @param array<string, SectionStatus>      $statuses keyed by Section::name
     * @param array<string, ExceptionInterface> $errors   keyed by Section::name
     */
    private static function profile(
        ?Company $company = null,
        ?VatSubject $vat = null,
        ?ViesResult $vies = null,
        array $statuses = [],
        array $errors = [],
    ): CompanyProfile {
        return new CompanyProfile(
            company: $company ?? CompanyFactory::create(),
            vat: $vat,
            vies: $vies,
            statuses: $statuses,
            errors: $errors,
        );
    }

    /**
     * @param list<BankAccount> $accounts
     */
    private static function subject(SubjectType $type = SubjectType::VatPayer, array $accounts = [], bool $unreliable = false): VatSubject
    {
        return new VatSubject(
            vatId: VatId::parse('CZ45274649'),
            type: $type,
            unreliable: $unreliable,
            unreliableSince: $unreliable ? new \DateTimeImmutable('2025-05-01') : null,
            taxOfficeCode: '013',
            name: 'Test a.s.',
            address: null,
            bankAccounts: $accounts,
            checkedAt: new \DateTimeImmutable('2026-10-03'),
        );
    }

    private static function account(?\DateTimeImmutable $until = null): BankAccount
    {
        return new BankAccount(
            prefix: null,
            number: '71504011',
            bankCode: '0100',
            publishedFrom: new \DateTimeImmutable('2013-04-01'),
            publishedUntil: $until,
        );
    }

    private static function viesResult(bool $valid): ViesResult
    {
        return new ViesResult(
            vatId: VatId::parse('CZ45274649'),
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

    private static function unavailable(): ServiceUnavailable
    {
        return new ServiceUnavailable('ADIS is down', Source::Adis);
    }

    /**
     * @return array<string, SectionStatus>
     */
    private static function vatOk(): array
    {
        return [Section::Vat->name => SectionStatus::Ok];
    }

    public function testSectionAndStatusEnumsHaveExactlyTheM1Cases(): void
    {
        self::assertSame(['Vat', 'Vies'], self::caseNames(Section::class));
        self::assertSame(
            ['NotRequested', 'Ok', 'NotFound', 'NotApplicable', 'Unavailable', 'Rejected'],
            self::caseNames(SectionStatus::class),
        );
        self::assertSame(
            [
                'Dissolved',
                'InLiquidation',
                'InsolvencyRecord',
                'UnreliableVatPayer',
                'UnreliablePerson',
                'VatRegistrationEnded',
                'NoPublishedBankAccount',
                'ViesInvalid',
            ],
            self::caseNames(RiskFlag::class),
        );
    }

    /**
     * @param class-string<\UnitEnum> $enum
     *
     * @return list<string>
     */
    private static function caseNames(string $enum): array
    {
        return array_values(array_map(
            static fn (\ReflectionEnumUnitCase $case): string => $case->getName(),
            (new \ReflectionEnum($enum))->getCases(),
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSectionStatusValues(): iterable
    {
        yield 'NotRequested' => ['not_requested', 'NotRequested'];
        yield 'Ok' => ['ok', 'Ok'];
        yield 'NotFound' => ['not_found', 'NotFound'];
        yield 'NotApplicable' => ['not_applicable', 'NotApplicable'];
        yield 'Unavailable' => ['unavailable', 'Unavailable'];
        yield 'Rejected' => ['rejected', 'Rejected'];
    }

    #[DataProvider('provideSectionStatusValues')]
    public function testSectionStatusIsBackedByASnakeCaseString(string $value, string $caseName): void
    {
        self::assertSame($caseName, SectionStatus::from($value)->name);
    }

    public function testProfileWithoutSectionsIsJsonEncodable(): void
    {
        $json = json_encode(self::profile(), \JSON_THROW_ON_ERROR);

        self::assertJson($json);
    }

    public function testProfileWithSectionsIsJsonEncodableAndCarriesTheStatusValues(): void
    {
        $profile = self::profile(
            vat: self::subject(),
            statuses: [
                Section::Vat->name => SectionStatus::Ok,
                Section::Vies->name => SectionStatus::Unavailable,
            ],
            errors: [Section::Vies->name => self::unavailable()],
        );

        $json = json_encode($profile, \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"ok"', $json);
        self::assertStringContainsString('"unavailable"', $json);
    }

    public function testStatusOfASectionThatWasNotRecordedIsNotRequested(): void
    {
        $profile = self::profile();

        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::NotRequested, $profile->status(Section::Vies));
    }

    public function testStatusReturnsTheRecordedStatusPerSection(): void
    {
        $profile = self::profile(statuses: [
            Section::Vat->name => SectionStatus::NotFound,
            Section::Vies->name => SectionStatus::NotApplicable,
        ]);

        self::assertSame(SectionStatus::NotFound, $profile->status(Section::Vat));
        self::assertSame(SectionStatus::NotApplicable, $profile->status(Section::Vies));
    }

    public function testErrorReturnsTheStoredExceptionOfAnUnavailableSection(): void
    {
        $exception = self::unavailable();
        $profile = self::profile(
            statuses: [Section::Vat->name => SectionStatus::Unavailable],
            errors: [Section::Vat->name => $exception],
        );

        self::assertSame($exception, $profile->error(Section::Vat));
        self::assertNull($profile->error(Section::Vies));
    }

    public function testProfileWithoutAnySectionIsComplete(): void
    {
        self::assertTrue(self::profile()->isComplete());
    }

    public function testProfileIsCompleteWhenNoSectionIsUnavailable(): void
    {
        $profile = self::profile(statuses: [
            Section::Vat->name => SectionStatus::NotFound,
            Section::Vies->name => SectionStatus::NotApplicable,
        ]);

        self::assertTrue($profile->isComplete());
    }

    public function testProfileIsIncompleteWhenAnySectionIsUnavailable(): void
    {
        $profile = self::profile(statuses: [
            Section::Vat->name => SectionStatus::Ok,
            Section::Vies->name => SectionStatus::Unavailable,
        ]);

        self::assertFalse($profile->isComplete());
    }

    public function testProfileIsIncompleteWhenAnySectionIsRejected(): void
    {
        $profile = self::profile(statuses: [
            Section::Vat->name => SectionStatus::Ok,
            Section::Vies->name => SectionStatus::Rejected,
        ]);

        self::assertFalse($profile->isComplete());
    }

    public function testErrorReturnsTheStoredExceptionOfARejectedSection(): void
    {
        $exception = new InvalidInput('VIES rejected the request', 'INVALID_REQUESTER_INFO');
        $profile = self::profile(
            statuses: [Section::Vies->name => SectionStatus::Rejected],
            errors: [Section::Vies->name => $exception],
        );

        self::assertSame($exception, $profile->error(Section::Vies));
        self::assertNull($profile->error(Section::Vat));
    }

    public function testProfileWithARejectedSectionIsJsonEncodableAndCarriesTheStatusValue(): void
    {
        $profile = self::profile(statuses: [Section::Vies->name => SectionStatus::Rejected]);

        self::assertStringContainsString('"rejected"', json_encode($profile, \JSON_THROW_ON_ERROR));
    }

    public function testProfileWithoutRiskHasNoFlags(): void
    {
        $profile = self::profile(vat: self::subject(accounts: [self::account()]), vies: self::viesResult(true), statuses: [
            Section::Vat->name => SectionStatus::Ok,
            Section::Vies->name => SectionStatus::Ok,
        ]);

        self::assertSame([], $profile->flags());
    }

    public function testDissolvedFlagIsRaisedWhenTheCompanyHasADissolutionDate(): void
    {
        $profile = self::profile(CompanyFactory::create(dissolvedOn: new \DateTimeImmutable('2020-01-31')));

        self::assertSame([RiskFlag::Dissolved], $profile->flags());
        self::assertTrue($profile->hasFlag(RiskFlag::Dissolved));
    }

    public function testDissolvedFlagIsNotRaisedForAScheduledDissolutionInTheFuture(): void
    {
        $company = CompanyMapper::map(JsonReader::fromJson(FixtureLoader::read('Ares/find-future-dissolution.json'), Source::Ares));
        $profile = self::profile($company);

        // The recorded date flips to the past in December 2035, on purpose.
        self::assertSame('2035-12-10', $company->dissolvedOn?->format('Y-m-d'));
        self::assertFalse($profile->hasFlag(RiskFlag::Dissolved));
        self::assertSame([], $profile->flags());
    }

    public function testDissolvedFlagIsNotRaisedWithoutADissolutionDate(): void
    {
        self::assertFalse(self::profile(CompanyFactory::create(dissolvedOn: null))->hasFlag(RiskFlag::Dissolved));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideLiquidationNames(): iterable
    {
        yield 'lower case suffix' => ['Test s.r.o. v likvidaci', true];
        yield 'upper case suffix' => ['TEST S.R.O. V LIKVIDACI', true];
        yield 'mixed case suffix' => ['Test a.s. V Likvidaci', true];
        yield 'trailing whitespace' => ['Test s.r.o. v likvidaci  ', true];
        yield 'czech letters before the suffix' => ['Žluťoučký kůň, spol. s r.o. v likvidaci', true];
        yield 'quoted suffix' => ['Test s.r.o. "v likvidaci"', true];
        yield 'quoted suffix with padding spaces' => ['Test s.r.o. " v likvidaci "', true];
        yield 'trailing dot' => ['Test s.r.o. v likvidaci.', true];
        yield 'comma before the suffix and trailing dot' => ['Test, s.r.o., v likvidaci.', true];
        yield 'two spaces between the words' => ['Test s.r.o. v  likvidaci', true];
        yield 'no-break space between the words' => ["Test s.r.o. v\u{00A0}likvidaci", true];
        yield 'phrase before the legal form' => ['Test Business v likvidaci, s.r.o.', true];
        yield 'phrase in the middle only' => ['Test v likvidaci s.r.o.', true];
        yield 'phrase between dashes' => ['Služby města Kralovic - v likvidaci -', true];
        yield 'phrase between slashes' => ['POSEIDON CLUB občanské sdružení /v likvidaci/', true];
        yield 'phrase in parentheses' => ['Firma (v likvidaci) a.s.', true];
        yield 'quoted phrase followed by a dot' => ['Firma "v likvidaci".', true];
        yield 'czech single quotes' => ["Firma \u{201A}v likvidaci\u{2018} s.r.o.", true];
        yield 'guillemets pointing outwards' => ["Firma \u{00BB}v likvidaci\u{00AB} s.r.o.", true];
        yield 'guillemets pointing inwards' => ["Firma \u{00AB}v likvidaci\u{00BB} s.r.o.", true];
        yield 'single angle quotes' => ["Firma \u{203A}v likvidaci\u{2039} s.r.o.", true];
        yield 'reversed double high quote' => ["Firma \u{201F}v likvidaci\u{201D} s.r.o.", true];
        yield 'reversed single high quote' => ["Firma \u{201B}v likvidaci\u{2019} s.r.o.", true];
        yield 'ares double comma and acute accent quotes' => ["Firma ,,v likvidaci\u{00B4}\u{00B4}", true];
        yield 'backtick quoted phrase' => ['Firma `v likvidaci` s.r.o.', true];
        yield 'acute accent right after the phrase' => ["Firma v likvidaci\u{00B4}", true];
        yield 'acute accent right before the phrase' => ["Firma \u{00B4}v likvidaci", true];
        yield 'backtick right after the phrase' => ['Firma v likvidaci`', true];
        yield 'backtick right before the phrase' => ['Firma `v likvidaci', true];
        yield 'phrase after a dash at the end' => ['Zemědělské družstvo Předměřice nad Labem - v likvidaci', true];
        yield 'phrase followed by an abbreviated legal form' => ['MTH ALARIS SPORT CLUB o.s., v likvidaci o.s.', true];
        yield 'phrase followed by an abbreviated legal form with a space' => ['Bublinky - v likvidaci z. s.', true];
        yield 'phrase followed by a comma and a trailing text' => [
            'STAVOMONT Liberec akciová společnost, v likvidaci,      jazykovémutace uvedeny níže',
            true,
        ];
        yield 'phrase followed by a parenthesised abbreviation' => [
            'Lánovská akciová společnost potravinářská, v likvidaci  (ve zkratce: LASPO a.s. v likvidaci)',
            true,
        ];
        yield 'phrase followed by a comma and a legal form word' => ['Nájemní družstvo Nýřany - v likvidaci, družstvo', true];
        yield 'comma terminated, v.o.s.' => ['KAFKA v.o.s., v likvidaci,', true];
        yield 'comma terminated, z.s.' => ['TJ SPV Výšina Havlíčkův Brod, z.s. v likvidaci,', true];
        yield 'comma terminated, s.r.o. with a comma' => ['Pivovar Janáček, s.r.o., v likvidaci,', true];
        yield 'comma terminated, with a dash in the name' => ['Finanční servis - 02, spol. s r.o., v likvidaci,', true];
        yield 'comma terminated, hyphenated name' => ['BAU-IMPEX, s.r.o., v likvidaci,', true];
        yield 'comma terminated, name starting with a digit' => ['4life.cz s.r.o., v likvidaci,', true];
        yield 'comma terminated, upper case name' => ['SANTA FE COUNTRY s.r.o., v likvidaci,', true];
        yield 'comma terminated, holding' => ['JTJ Holding s.r.o., v likvidaci,', true];
        yield 'comma terminated, spaced letters' => ['U N I M A P spol. s r.o., v likvidaci,', true];
        yield 'comma terminated, dotted initials' => ['R.K.M. s.r.o., v likvidaci,', true];
        yield 'phrase alone' => ['v likvidaci', true];
        yield 'word containing the stem elsewhere' => ['Likvidaci servis s.r.o.', false];
        yield 'words glued together' => ['Test s.r.o. vlikvidaci', false];
        yield 'similar word' => ['Likvidace s.r.o.', false];
        yield 'liquidation in the instrumental case' => ['SK RELAX SPORT s likvidací', false];
        yield 'letter between the words' => ['Lewis Robinson v l likvidaci', false];
        yield 'preposition ending a longer word' => ['Kov likvidaci s.r.o.', false];
        yield 'preposition glued to the preceding place name' => ['Horní Podlužív likvidaci', false];
        yield 'stem without the preposition' => ['Správa likvidaci s.r.o.', false];
        yield 'name ending in v.o.s.' => ['HD elektro v.o.s.', false];
        yield 'plain name' => ['Test s.r.o.', false];
    }

    #[DataProvider('provideLiquidationNames')]
    public function testInLiquidationFlagFollowsThePhraseInTheName(string $name, bool $expected): void
    {
        $profile = self::profile(CompanyFactory::create(name: $name));

        self::assertSame($expected, $profile->hasFlag(RiskFlag::InLiquidation));
    }

    /**
     * @return iterable<string, array{RegistrationStatus, bool}>
     */
    public static function provideInsolvencyStatuses(): iterable
    {
        yield 'active' => [RegistrationStatus::Active, true];
        yield 'historical' => [RegistrationStatus::Historical, false];
        yield 'dissolved' => [RegistrationStatus::Dissolved, false];
        yield 'nonexistent' => [RegistrationStatus::Nonexistent, false];
        yield 'unknown' => [RegistrationStatus::Unknown, false];
    }

    #[DataProvider('provideInsolvencyStatuses')]
    public function testInsolvencyRecordFlagIsRaisedOnlyForAnActiveInsolvencyRegistration(RegistrationStatus $status, bool $expected): void
    {
        $company = CompanyFactory::create(statuses: [AresRegister::Insolvency->value => $status]);

        self::assertSame($expected, self::profile($company)->hasFlag(RiskFlag::InsolvencyRecord));
    }

    public function testUnreliableVatPayerFlagIsRaisedFromAnOkVatSection(): void
    {
        $profile = self::profile(vat: self::subject(accounts: [self::account()], unreliable: true), statuses: self::vatOk());

        self::assertSame([RiskFlag::UnreliableVatPayer], $profile->flags());
    }

    public function testUnreliableVatPayerFlagIsRaisedForAnUnreliableVatGroup(): void
    {
        $profile = self::profile(vat: self::subject(SubjectType::VatGroup, [self::account()], unreliable: true), statuses: self::vatOk());

        self::assertTrue($profile->hasFlag(RiskFlag::UnreliableVatPayer));
    }

    public function testUnreliablePersonFromTheRegisterIsNotFlaggedAsAnUnreliableVatPayer(): void
    {
        $subjects = ResponseParser::parseSubjects(FixtureLoader::read('Adis/status-unreliable-person.xml'));
        self::assertCount(1, $subjects);
        $profile = self::profile(vat: $subjects[0], statuses: self::vatOk());

        self::assertTrue($subjects[0]->unreliable);
        self::assertSame([RiskFlag::UnreliablePerson], $profile->flags());
    }

    public function testUnreliableIdentifiedPersonIsFlaggedAsUnreliablePersonNotAsUnreliableVatPayer(): void
    {
        $subjects = ResponseParser::parseSubjects(FixtureLoader::read('Adis/status-identified-person-unreliable.xml'));
        self::assertCount(1, $subjects);
        $profile = self::profile(vat: $subjects[0], statuses: self::vatOk());

        self::assertSame(SubjectType::IdentifiedPerson, $subjects[0]->type);
        self::assertTrue($subjects[0]->unreliable);
        self::assertFalse($profile->isVatPayer());
        self::assertSame([RiskFlag::UnreliablePerson], $profile->flags());
    }

    public function testUnreliableVatPayerFlagIsNotRaisedForAReliableIdentifiedPerson(): void
    {
        $profile = self::profile(vat: self::subject(SubjectType::IdentifiedPerson), statuses: self::vatOk());

        self::assertFalse($profile->hasFlag(RiskFlag::UnreliableVatPayer));
    }

    public function testUnreliableVatPayerFlagIsNotRaisedForAReliablePayer(): void
    {
        $profile = self::profile(vat: self::subject(accounts: [self::account()]), statuses: self::vatOk());

        self::assertFalse($profile->hasFlag(RiskFlag::UnreliableVatPayer));
    }

    public function testUnreliablePersonFlagIsRaisedForAnUnreliablePersonSubject(): void
    {
        $profile = self::profile(vat: self::subject(SubjectType::UnreliablePerson), statuses: self::vatOk());

        self::assertTrue($profile->hasFlag(RiskFlag::UnreliablePerson));
    }

    /**
     * @return iterable<string, array{SubjectType}>
     */
    public static function provideReliableSubjectTypes(): iterable
    {
        yield 'vat payer' => [SubjectType::VatPayer];
        yield 'identified person' => [SubjectType::IdentifiedPerson];
        yield 'vat group' => [SubjectType::VatGroup];
    }

    #[DataProvider('provideReliableSubjectTypes')]
    public function testUnreliablePersonFlagIsNotRaisedForOtherSubjectTypes(SubjectType $type): void
    {
        $profile = self::profile(vat: self::subject($type, [self::account()]), statuses: self::vatOk());

        self::assertFalse($profile->hasFlag(RiskFlag::UnreliablePerson));
    }

    /**
     * @return iterable<string, array{RegistrationStatus, RegistrationStatus, bool}>
     */
    public static function provideVatRegistrationStatuses(): iterable
    {
        yield 'vat dissolved, no group' => [RegistrationStatus::Dissolved, RegistrationStatus::Nonexistent, true];
        yield 'vat historical, no group' => [RegistrationStatus::Historical, RegistrationStatus::Nonexistent, true];
        yield 'vat historical, group historical' => [RegistrationStatus::Historical, RegistrationStatus::Historical, true];
        yield 'vat dissolved, active group' => [RegistrationStatus::Dissolved, RegistrationStatus::Active, false];
        yield 'vat historical, active group' => [RegistrationStatus::Historical, RegistrationStatus::Active, false];
        yield 'vat active' => [RegistrationStatus::Active, RegistrationStatus::Nonexistent, false];
        yield 'vat nonexistent' => [RegistrationStatus::Nonexistent, RegistrationStatus::Nonexistent, false];
        yield 'vat suspended' => [RegistrationStatus::Suspended, RegistrationStatus::Nonexistent, false];
        yield 'vat unknown' => [RegistrationStatus::Unknown, RegistrationStatus::Nonexistent, false];
    }

    #[DataProvider('provideVatRegistrationStatuses')]
    public function testVatRegistrationEndedFlagFollowsTheAresVatAndVatGroupRegistrations(
        RegistrationStatus $vat,
        RegistrationStatus $group,
        bool $expected,
    ): void {
        $company = CompanyFactory::create(statuses: [
            AresRegister::Vat->value => $vat,
            AresRegister::VatGroup->value => $group,
        ]);

        self::assertSame($expected, self::profile($company)->hasFlag(RiskFlag::VatRegistrationEnded));
    }

    // The ADIS fixture is a real response; the ARES one is the real record of a subject whose ARES VAT registration lags the VAT register.
    public function testVatRegistrationEndedFlagYieldsToAnOkVatSectionThatSaysThePayerIsAPayer(): void
    {
        $company = CompanyMapper::map(JsonReader::fromJson(FixtureLoader::read('Ares/find-vat-dissolved-payer.json'), Source::Ares));
        $subjects = ResponseParser::parseSubjects(FixtureLoader::read('Adis/status-vat-dissolved-payer.xml'));
        self::assertCount(1, $subjects);
        $profile = self::profile($company, $subjects[0], statuses: self::vatOk());

        self::assertSame(RegistrationStatus::Dissolved, $company->registrations->status(AresRegister::Vat));
        self::assertTrue($profile->isVatPayer());
        self::assertFalse($profile->hasFlag(RiskFlag::VatRegistrationEnded));
    }

    /**
     * @return iterable<string, array{?SubjectType, ?SectionStatus, bool}> subject type of the attached VAT answer, Vat section status, flag expected
     */
    public static function provideVatRegistrationEndedWithAVatAnswer(): iterable
    {
        yield 'section not requested' => [null, null, true];
        yield 'section not found' => [null, SectionStatus::NotFound, true];
        yield 'section unavailable, payer attached' => [SubjectType::VatPayer, SectionStatus::Unavailable, true];
        yield 'section rejected, payer attached' => [SubjectType::VatPayer, SectionStatus::Rejected, true];
        yield 'ok, identified person' => [SubjectType::IdentifiedPerson, SectionStatus::Ok, true];
        yield 'ok, vat payer' => [SubjectType::VatPayer, SectionStatus::Ok, false];
        yield 'ok, vat group' => [SubjectType::VatGroup, SectionStatus::Ok, false];
    }

    #[DataProvider('provideVatRegistrationEndedWithAVatAnswer')]
    public function testVatRegistrationEndedFlagDependsOnTheVatSectionOnlyWhenItIsOk(?SubjectType $type, ?SectionStatus $status, bool $expected): void
    {
        $company = CompanyFactory::create(statuses: [AresRegister::Vat->value => RegistrationStatus::Dissolved]);
        $vat = null === $type ? null : self::subject($type, [self::account()]);
        $statuses = null === $status ? [] : [Section::Vat->name => $status];

        self::assertSame($expected, self::profile($company, $vat, statuses: $statuses)->hasFlag(RiskFlag::VatRegistrationEnded));
    }

    public function testNoPublishedBankAccountFlagIsRaisedForAPayerWithoutAnAccount(): void
    {
        $profile = self::profile(vat: self::subject(SubjectType::VatPayer), statuses: self::vatOk());

        self::assertSame([RiskFlag::NoPublishedBankAccount], $profile->flags());
    }

    public function testNoPublishedBankAccountFlagIsRaisedWhenOnlyEndedAccountsExist(): void
    {
        $ended = self::account(new \DateTimeImmutable('2021-01-01'));
        $profile = self::profile(vat: self::subject(SubjectType::VatPayer, [$ended]), statuses: self::vatOk());

        self::assertTrue($profile->hasFlag(RiskFlag::NoPublishedBankAccount));
    }

    public function testNoPublishedBankAccountFlagIsRaisedForAVatGroupWithoutAnAccount(): void
    {
        $profile = self::profile(vat: self::subject(SubjectType::VatGroup), statuses: self::vatOk());

        self::assertTrue($profile->hasFlag(RiskFlag::NoPublishedBankAccount));
    }

    public function testNoPublishedBankAccountFlagIsNotRaisedWhenAnActiveAccountExists(): void
    {
        $profile = self::profile(vat: self::subject(SubjectType::VatPayer, [self::account()]), statuses: self::vatOk());

        self::assertFalse($profile->hasFlag(RiskFlag::NoPublishedBankAccount));
    }

    public function testNoPublishedBankAccountFlagIsNotRaisedForAnIdentifiedPerson(): void
    {
        $profile = self::profile(vat: self::subject(SubjectType::IdentifiedPerson), statuses: self::vatOk());

        self::assertFalse($profile->hasFlag(RiskFlag::NoPublishedBankAccount));
    }

    public function testViesInvalidFlagIsRaisedForAnOkViesSectionWithAnInvalidVatId(): void
    {
        $profile = self::profile(vies: self::viesResult(false), statuses: [Section::Vies->name => SectionStatus::Ok]);

        self::assertSame([RiskFlag::ViesInvalid], $profile->flags());
    }

    public function testViesInvalidFlagIsNotRaisedForAValidVatId(): void
    {
        $profile = self::profile(vies: self::viesResult(true), statuses: [Section::Vies->name => SectionStatus::Ok]);

        self::assertFalse($profile->hasFlag(RiskFlag::ViesInvalid));
    }

    /**
     * @return iterable<string, array{SectionStatus}>
     */
    public static function provideStatusesOfSectionsThatAreNotOk(): iterable
    {
        yield 'unavailable' => [SectionStatus::Unavailable];
        yield 'rejected' => [SectionStatus::Rejected];
        yield 'not requested' => [SectionStatus::NotRequested];
    }

    #[DataProvider('provideStatusesOfSectionsThatAreNotOk')]
    public function testSectionDerivedFlagsAreNotRaisedFromASectionThatIsNotOk(SectionStatus $status): void
    {
        $profile = self::profile(
            vat: self::subject(SubjectType::UnreliablePerson, unreliable: true),
            vies: self::viesResult(false),
            statuses: [Section::Vat->name => $status, Section::Vies->name => $status],
        );

        self::assertSame([], $profile->flags());
    }

    public function testNoSectionDerivedFlagIsRaisedFromAnUnavailableSectionWithoutData(): void
    {
        $profile = self::profile(statuses: [
            Section::Vat->name => SectionStatus::Unavailable,
            Section::Vies->name => SectionStatus::Unavailable,
        ]);

        self::assertSame([], $profile->flags());
    }

    public function testAresDerivedFlagsStayRaisedWhileSectionsAreUnavailable(): void
    {
        $company = CompanyFactory::create(dissolvedOn: new \DateTimeImmutable('2020-01-31'));
        $profile = self::profile($company, statuses: [Section::Vat->name => SectionStatus::Unavailable]);

        self::assertSame([RiskFlag::Dissolved], $profile->flags());
    }

    public function testFlagsAreListedInTheOrderOfTheEnumCases(): void
    {
        $company = CompanyFactory::create(
            name: 'Test s.r.o. v likvidaci',
            dissolvedOn: new \DateTimeImmutable('2020-01-31'),
            statuses: [AresRegister::Insolvency->value => RegistrationStatus::Active],
        );
        $profile = self::profile(
            $company,
            vat: self::subject(SubjectType::VatPayer, unreliable: true),
            vies: self::viesResult(false),
            statuses: [Section::Vat->name => SectionStatus::Ok, Section::Vies->name => SectionStatus::Ok],
        );

        self::assertSame(
            [
                RiskFlag::Dissolved,
                RiskFlag::InLiquidation,
                RiskFlag::InsolvencyRecord,
                RiskFlag::UnreliableVatPayer,
                RiskFlag::NoPublishedBankAccount,
                RiskFlag::ViesInvalid,
            ],
            $profile->flags(),
        );
    }

    public function testHasFlagIsFalseForAFlagThatIsNotRaised(): void
    {
        self::assertFalse(self::profile()->hasFlag(RiskFlag::Dissolved));
    }

    /**
     * @return iterable<string, array{SubjectType, bool}>
     */
    public static function provideSubjectTypesWithPayerAnswer(): iterable
    {
        yield 'vat payer' => [SubjectType::VatPayer, true];
        yield 'vat group' => [SubjectType::VatGroup, true];
        yield 'identified person' => [SubjectType::IdentifiedPerson, false];
        yield 'unreliable person' => [SubjectType::UnreliablePerson, false];
    }

    #[DataProvider('provideSubjectTypesWithPayerAnswer')]
    public function testIsVatPayerFollowsTheRegisterSubjectTypeWhenTheSectionIsOk(SubjectType $type, bool $expected): void
    {
        $profile = self::profile(vat: self::subject($type), statuses: self::vatOk());

        self::assertSame($expected, $profile->isVatPayer());
    }

    /**
     * @return iterable<string, array{SectionStatus}>
     */
    public static function provideDefinitiveNegativeStatuses(): iterable
    {
        yield 'not found in the register' => [SectionStatus::NotFound];
        yield 'company has no vat id' => [SectionStatus::NotApplicable];
    }

    #[DataProvider('provideDefinitiveNegativeStatuses')]
    public function testIsVatPayerIsDefinitelyFalseWhenTheRegisterHasNoSubject(SectionStatus $status): void
    {
        $profile = self::profile(statuses: [Section::Vat->name => $status]);

        self::assertFalse($profile->isVatPayer());
    }

    #[DataProvider('provideDefinitiveNegativeStatuses')]
    public function testHasPublishedAccountIsDefinitelyFalseWhenTheRegisterHasNoSubject(SectionStatus $status): void
    {
        $profile = self::profile(statuses: [Section::Vat->name => $status]);

        self::assertFalse($profile->hasPublishedAccount('71504011/0100'));
    }

    public function testIsVatPayerIsUnknownWhenTheVatSectionIsUnavailable(): void
    {
        $profile = self::profile(
            statuses: [Section::Vat->name => SectionStatus::Unavailable],
            errors: [Section::Vat->name => self::unavailable()],
        );

        self::assertNull($profile->isVatPayer());
    }

    public function testHasPublishedAccountIsUnknownWhenTheVatSectionIsUnavailable(): void
    {
        $profile = self::profile(
            statuses: [Section::Vat->name => SectionStatus::Unavailable],
            errors: [Section::Vat->name => self::unavailable()],
        );

        self::assertNull($profile->hasPublishedAccount('71504011/0100'));
    }

    public function testIsVatPayerIsUnknownWhenTheVatSectionIsRejected(): void
    {
        $profile = self::profile(
            statuses: [Section::Vat->name => SectionStatus::Rejected],
            errors: [Section::Vat->name => new InvalidInput('Only Czech VAT ids are accepted')],
        );

        self::assertNull($profile->isVatPayer());
    }

    public function testHasPublishedAccountIsUnknownWhenTheVatSectionIsRejected(): void
    {
        $profile = self::profile(
            statuses: [Section::Vat->name => SectionStatus::Rejected],
            errors: [Section::Vat->name => new InvalidInput('Only Czech VAT ids are accepted')],
        );

        self::assertNull($profile->hasPublishedAccount('71504011/0100'));
    }

    public function testIsVatPayerThrowsWhenTheVatSectionWasNotRequested(): void
    {
        $this->expectException(\LogicException::class);

        self::profile()->isVatPayer();
    }

    public function testHasPublishedAccountThrowsWhenTheVatSectionWasNotRequested(): void
    {
        $this->expectException(\LogicException::class);

        self::profile()->hasPublishedAccount('71504011/0100');
    }

    public function testIsVatPayerThrowsForAnOkVatSectionWithoutASubject(): void
    {
        $this->expectException(\LogicException::class);

        self::profile(statuses: self::vatOk())->isVatPayer();
    }

    public function testHasPublishedAccountThrowsForAnOkVatSectionWithoutASubject(): void
    {
        $this->expectException(\LogicException::class);

        self::profile(statuses: self::vatOk())->hasPublishedAccount('71504011/0100');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideAccountsToCheck(): iterable
    {
        yield 'domestic form' => ['71504011/0100', true];
        yield 'leading zeros' => ['0071504011/0100', true];
        yield 'zero padded prefix' => ['000000-0071504011/0100', true];
        yield 'with spaces' => ['71504011 / 0100', true];
        yield 'czech iban' => ['CZ00 0100 0000 0000 7150 4011', true];
        yield 'another account number' => ['71504012/0100', false];
        yield 'another bank' => ['71504011/0300', false];
        yield 'unrecognisable input' => ['not an account', false];
    }

    #[DataProvider('provideAccountsToCheck')]
    public function testHasPublishedAccountComparesAgainstTheActiveAccountsOfAnOkSubject(string $account, bool $expected): void
    {
        $profile = self::profile(vat: self::subject(SubjectType::VatPayer, [self::account()]), statuses: self::vatOk());

        self::assertSame($expected, $profile->hasPublishedAccount($account));
    }

    public function testHasPublishedAccountIsFalseForAnEndedAccount(): void
    {
        $ended = self::account(new \DateTimeImmutable('2021-01-01'));
        $profile = self::profile(vat: self::subject(SubjectType::VatPayer, [$ended]), statuses: self::vatOk());

        self::assertFalse($profile->hasPublishedAccount('71504011/0100'));
    }
}
