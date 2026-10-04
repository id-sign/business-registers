<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

use IdSign\BusinessRegisters\Adis\VatRegister;
use IdSign\BusinessRegisters\Ares\CompanyDirectory;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Vies\Vies;

/**
 * Assembles a company profile from ARES and the requested sections; an outage of a section source
 * makes that section Unavailable instead of failing the whole lookup.
 */
final readonly class CompanyLookup
{
    /**
     * @param ?VatId $viesRequester own VAT id passed to VIES, so that it issues a consultation number
     */
    public function __construct(
        private CompanyDirectory $directory,
        private ?VatRegister $vatRegister = null,
        private ?Vies $vies = null,
        private ?VatId $viesRequester = null,
    ) {
    }

    /**
     * Null when ARES does not hold the subject. Without sections only ARES is asked (one request).
     * Each section is asked once, in the given order; a section is looked up under
     * Company::vatLookupId(), so a VAT group member is looked up under the group VAT id.
     *
     * @throws \LogicException    a section was requested whose client was not configured (before any request)
     * @throws InvalidInput       from ARES or from a section
     * @throws ServiceUnavailable from ARES; a section outage is recorded as Unavailable instead
     * @throws InvalidResponse    from ARES; a section's invalid response is recorded as Unavailable instead
     */
    public function byCompanyId(CompanyId|string $id, Section ...$sections): ?CompanyProfile
    {
        $requested = [];
        foreach ($sections as $section) {
            $requested[$section->name] = $section;
        }

        // A missing client is a programming error, so it fails before any request is made.
        foreach ($requested as $section) {
            match ($section) {
                Section::Vat => $this->vatRegister(),
                Section::Vies => $this->vies(),
            };
        }

        $company = $this->directory->find($id);
        if (null === $company) {
            return null;
        }

        $vatLookupId = $company->vatLookupId();
        $vat = null;
        $vies = null;
        $statuses = [];
        $errors = [];
        foreach ($requested as $name => $section) {
            if (null === $vatLookupId) {
                $statuses[$name] = SectionStatus::NotApplicable;
                continue;
            }

            try {
                switch ($section) {
                    case Section::Vat:
                        $vat = $this->vatRegister()->find($vatLookupId);
                        $statuses[$name] = null === $vat ? SectionStatus::NotFound : SectionStatus::Ok;
                        break;
                    case Section::Vies:
                        $vies = $this->vies()->check($vatLookupId, $this->viesRequester);
                        $statuses[$name] = SectionStatus::Ok;
                        break;
                }
            } catch (ServiceUnavailable|InvalidResponse $e) {
                $statuses[$name] = SectionStatus::Unavailable;
                $errors[$name] = $e;
            }
        }

        return new CompanyProfile($company, $vat, $vies, $statuses, $errors);
    }

    private function vatRegister(): VatRegister
    {
        return $this->vatRegister ?? throw new \LogicException('Section Vat requested but no VatRegister was configured');
    }

    private function vies(): Vies
    {
        return $this->vies ?? throw new \LogicException('Section Vies requested but no Vies was configured');
    }
}
