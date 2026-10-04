<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\Ares\AresRegister;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\RegistrationStatus;
use IdSign\BusinessRegisters\Exception\ExceptionInterface;
use IdSign\BusinessRegisters\Vies\ViesResult;

/**
 * Company assembled by CompanyLookup from ARES and the requested sections.
 *
 * A null section property ($vat, $vies) has four meanings; status() tells them apart:
 * NotRequested (the section was not asked for), NotFound (the source does not hold the subject),
 * NotApplicable (the subject has no VAT id) and Unavailable (the source could not answer; error()
 * holds the exception). Never read a null section as a negative answer without checking status().
 *
 * flags() are computed from ARES and only from sections whose status is Ok. An absent flag
 * therefore means "clean" only when isComplete() is true and the section the flag comes from
 * was requested.
 */
final readonly class CompanyProfile
{
    /**
     * Built by CompanyLookup; public so consumers can build profiles in their tests.
     *
     * @param array<string, SectionStatus>      $statuses keyed by Section::name; a missing key is NotRequested
     * @param array<string, ExceptionInterface> $errors   keyed by Section::name, for Unavailable sections
     */
    public function __construct(
        public Company $company,
        public ?VatSubject $vat,
        public ?ViesResult $vies,
        public array $statuses,
        public array $errors,
    ) {
    }

    public function status(Section $section): SectionStatus
    {
        return $this->statuses[$section->name] ?? SectionStatus::NotRequested;
    }

    /**
     * The exception that made the section Unavailable, otherwise null.
     */
    public function error(Section $section): ?ExceptionInterface
    {
        return $this->errors[$section->name] ?? null;
    }

    /**
     * True when no requested section is Unavailable.
     */
    public function isComplete(): bool
    {
        return !\in_array(SectionStatus::Unavailable, $this->statuses, true);
    }

    /**
     * @return list<RiskFlag> in the order of the enum cases
     */
    public function flags(): array
    {
        return array_values(array_filter(RiskFlag::cases(), $this->isRaised(...)));
    }

    public function hasFlag(RiskFlag $flag): bool
    {
        return \in_array($flag, $this->flags(), true);
    }

    /**
     * Whether the subject is a VAT payer (a VAT payer or a VAT group) according to the VAT register.
     *
     * The answer is tri-state, because a missing answer of the register must not look like "no":
     * true or false is definitive (false also when the register does not hold the subject or the
     * subject has no VAT id); null means the register could not answer (Section::Vat is Unavailable)
     * and must never be read as "not a VAT payer" — ask again later.
     *
     * @throws \LogicException Section::Vat was not requested
     */
    public function isVatPayer(): ?bool
    {
        $subject = $this->vatAnswer();

        return $subject instanceof VatSubject ? $subject->isVatPayer() : $subject;
    }

    /**
     * Whether $account is among the active bank accounts published in the VAT register (see
     * VatSubject::hasPublishedAccount() for the accepted forms).
     *
     * The answer is tri-state like isVatPayer(): true or false is definitive (false also when the
     * register does not hold the subject or the subject has no VAT id); null means the register could
     * not answer and must never be read as "the account is not published" — ask again later.
     *
     * @throws \LogicException Section::Vat was not requested
     */
    public function hasPublishedAccount(string $account): ?bool
    {
        $subject = $this->vatAnswer();

        return $subject instanceof VatSubject ? $subject->hasPublishedAccount($account) : $subject;
    }

    /**
     * The register subject for an Ok section, false for a definitive "not in the register", null for unknown.
     */
    private function vatAnswer(): VatSubject|false|null
    {
        return match ($this->status(Section::Vat)) {
            SectionStatus::Ok => $this->vat ?? throw new \LogicException('Section Vat is Ok but the profile holds no VAT subject'),
            SectionStatus::NotFound, SectionStatus::NotApplicable => false,
            SectionStatus::Unavailable => null,
            SectionStatus::NotRequested => throw new \LogicException('Section Vat was not requested; pass Section::Vat to CompanyLookup::byCompanyId()'),
        };
    }

    private function isRaised(RiskFlag $flag): bool
    {
        $registrations = $this->company->registrations;
        $vat = SectionStatus::Ok === $this->status(Section::Vat) ? $this->vat : null;
        $vies = SectionStatus::Ok === $this->status(Section::Vies) ? $this->vies : null;

        return match ($flag) {
            RiskFlag::Dissolved => null !== $this->company->dissolvedOn,
            // Real ARES names carry the suffix in quotes, with a trailing dot or with extra spaces.
            RiskFlag::InLiquidation => 1 === preg_match('/v\s+likvidaci["\'\s.]*$/iu', $this->company->name),
            RiskFlag::InsolvencyRecord => $registrations->isActive(AresRegister::Insolvency),
            RiskFlag::UnreliableVatPayer => true === $vat?->unreliable,
            RiskFlag::UnreliablePerson => SubjectType::UnreliablePerson === $vat?->type,
            RiskFlag::VatRegistrationEnded => \in_array($registrations->status(AresRegister::Vat), [RegistrationStatus::Dissolved, RegistrationStatus::Historical], true)
                && !$registrations->isActive(AresRegister::VatGroup),
            RiskFlag::NoPublishedBankAccount => null !== $vat && $vat->isVatPayer() && [] === $vat->activeBankAccounts(),
            RiskFlag::ViesInvalid => false === $vies?->valid,
        };
    }
}
