<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\Ares\AresRegister;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\RegistrationStatus;
use IdSign\BusinessRegisters\Exception\ExceptionInterface;
use IdSign\BusinessRegisters\Isir\InsolvencyProceedings;
use IdSign\BusinessRegisters\Vies\ViesResult;

/**
 * Company assembled by CompanyLookup from ARES and the requested sections.
 *
 * A null section property ($vat, $vies, $insolvencies) has five meanings; status() tells them apart:
 * NotRequested (the section was not asked for), NotFound (the source does not hold the subject),
 * NotApplicable (the subject has no id the section is queried under: no VAT id for Vat and Vies, no
 * IČO for Insolvency), Unavailable (the source could not answer; ask again later) and Rejected (the
 * source rejected the request; fix the input or configuration). For the last two error() holds the
 * exception. Never read a null section as a negative answer without checking status().
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
     * @param array<string, ExceptionInterface> $errors   keyed by Section::name, for Unavailable and Rejected sections
     */
    public function __construct(
        public Company $company,
        public ?VatSubject $vat,
        public ?ViesResult $vies,
        public ?InsolvencyProceedings $insolvencies,
        public array $statuses,
        public array $errors,
    ) {
    }

    public function status(Section $section): SectionStatus
    {
        return $this->statuses[$section->name] ?? SectionStatus::NotRequested;
    }

    /**
     * The exception that made the section Unavailable or Rejected, otherwise null.
     */
    public function error(Section $section): ?ExceptionInterface
    {
        return $this->errors[$section->name] ?? null;
    }

    /**
     * True when no requested section is Unavailable or Rejected, and Section::Insolvency is not NotApplicable: without
     * an IČO the register was not asked, and a subject can be listed under its birth number alone, so the answer is
     * unknown (see isInInsolvency()).
     */
    public function isComplete(): bool
    {
        return !\in_array(SectionStatus::Unavailable, $this->statuses, true)
            && !\in_array(SectionStatus::Rejected, $this->statuses, true)
            && SectionStatus::NotApplicable !== $this->status(Section::Insolvency);
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
        return $this->isRaised($flag);
    }

    /**
     * Whether the subject is a VAT payer (a VAT payer or a VAT group) according to the VAT register.
     *
     * The answer is tri-state, because a missing answer of the register must not look like "no":
     * true or false is definitive (false also when the register does not hold the subject or the
     * subject has no VAT id); null means the answer is unknown and must never be read as "not a VAT
     * payer" — Section::Vat is Unavailable (ask again later) or Rejected (fix the input or configuration).
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
     * register does not hold the subject or the subject has no VAT id); null means the answer is unknown
     * (Section::Vat is Unavailable or Rejected) and must never be read as "the account is not published".
     *
     * @throws \LogicException Section::Vat was not requested
     */
    public function hasPublishedAccount(string $account): ?bool
    {
        $subject = $this->vatAnswer();

        return $subject instanceof VatSubject ? $subject->hasPublishedAccount($account) : $subject;
    }

    /**
     * Whether the insolvency register lists an ongoing proceeding for the subject, a filed petition included
     * (see InsolvencyProceeding::isOngoing()).
     *
     * The answer is tri-state: true or false is definitive; null means the answer is unknown and must never be read
     * as "not in insolvency" — Section::Insolvency is Unavailable or Rejected, or NotApplicable: the register was
     * not asked because the subject has no IČO, and a subject can be listed under its birth number alone.
     *
     * @throws \LogicException Section::Insolvency was not requested
     */
    public function isInInsolvency(): ?bool
    {
        return match ($this->status(Section::Insolvency)) {
            SectionStatus::Ok => ($this->insolvencies ?? throw new \LogicException('Section Insolvency is Ok but the profile holds no proceedings'))->hasOngoing(),
            SectionStatus::NotFound => false,
            SectionStatus::NotApplicable, SectionStatus::Unavailable, SectionStatus::Rejected => null,
            SectionStatus::NotRequested => throw new \LogicException('Section Insolvency was not requested; pass Section::Insolvency to CompanyLookup::byCompanyId()'),
        };
    }

    /**
     * The register subject for an Ok section, false for a definitive "not in the register", null for unknown.
     */
    private function vatAnswer(): VatSubject|false|null
    {
        return match ($this->status(Section::Vat)) {
            SectionStatus::Ok => $this->vat ?? throw new \LogicException('Section Vat is Ok but the profile holds no VAT subject'),
            SectionStatus::NotFound, SectionStatus::NotApplicable => false,
            SectionStatus::Unavailable, SectionStatus::Rejected => null,
            SectionStatus::NotRequested => throw new \LogicException('Section Vat was not requested; pass Section::Vat to CompanyLookup::byCompanyId()'),
        };
    }

    private function isRaised(RiskFlag $flag): bool
    {
        $registrations = $this->company->registrations;
        // through the shortcuts, so a profile Ok without its data fails here as it does there
        $vatAnswer = SectionStatus::Ok === $this->status(Section::Vat) ? $this->vatAnswer() : null;
        $vat = $vatAnswer instanceof VatSubject ? $vatAnswer : null;
        $vies = SectionStatus::Ok === $this->status(Section::Vies) ? $this->vies : null;

        return match ($flag) {
            RiskFlag::Ceased => $this->company->hasCeased(),
            // The phrase stands anywhere in the name; on each side only the start/end, whitespace, a quote (with ARES's
            // ´ and ` substitutes), a comma, a dot, a parenthesis, a slash or a dash may touch it, so "vlikvidaci" or
            // "Kov likvidaci" do not match.
            RiskFlag::InLiquidation => 1 === preg_match('/(?<![^\s"\'„“”‘’‚‛‟«»‹›´`,.()\/\p{Pd}])v\s+likvidaci(?![^\s"\'„“”‘’‚‛‟«»‹›´`,.()\/\p{Pd}])/iu', $this->company->name),
            RiskFlag::InsolvencyRecord => $registrations->isActive(AresRegister::Insolvency),
            RiskFlag::Insolvency => SectionStatus::Ok === $this->status(Section::Insolvency) && true === $this->isInInsolvency(),
            RiskFlag::UnreliableVatPayer => null !== $vat && $vat->unreliable && $vat->isVatPayer(),
            RiskFlag::UnreliablePerson => SubjectType::UnreliablePerson === $vat?->type
                || (null !== $vat && $vat->unreliable && SubjectType::IdentifiedPerson === $vat->type),
            RiskFlag::VatRegistrationEnded => \in_array($registrations->status(AresRegister::Vat), [RegistrationStatus::Ended, RegistrationStatus::Historical], true)
                && !$registrations->isActive(AresRegister::VatGroup)
                && true !== $vat?->isVatPayer(),
            RiskFlag::NoPublishedBankAccount => null !== $vat && $vat->isVatPayer() && [] === $vat->activeBankAccounts(),
            RiskFlag::ViesInvalid => false === $vies?->valid,
        };
    }
}
