<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

use IdSign\BusinessRegisters\Adis\VatRegister;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\Adis\VatSubjects;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\CompanyDirectory;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\Identifiers;
use IdSign\BusinessRegisters\Internal\ListElement;
use IdSign\BusinessRegisters\Vies\Vies;

/**
 * Assembles a company profile from ARES and the requested sections; an outage of a section source
 * makes that section Unavailable and a rejected section request makes it Rejected, instead of
 * failing the whole lookup.
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
     * @throws InvalidInput       from ARES only (the id or an ARES rejection); a section's is recorded as Rejected instead
     * @throws ServiceUnavailable from ARES; a section outage is recorded as Unavailable instead
     * @throws InvalidResponse    from ARES; a section's invalid response is recorded as Unavailable instead
     */
    public function byCompanyId(CompanyId|string $id, Section ...$sections): ?CompanyProfile
    {
        $requested = $this->requestedSections(...$sections);

        $company = $this->directory->find($id);
        if (null === $company) {
            return null;
        }

        return $this->profile($company, $requested, fn (VatId $vatId): ?VatSubject => $this->vatRegister()->find($vatId));
    }

    /**
     * Profiles of the companies ARES holds, in the order ARES returns them; ids ARES does not hold are
     * absent and reported by CompanyProfiles::missing(). The statuses mean the same as in byCompanyId().
     *
     * ARES is asked with one findMany() and section Vat with one findMany() over the distinct
     * Company::vatLookupId() values, so 100 ids with Vat are one ARES and one ADIS request. An ADIS
     * outage or invalid response makes Vat Unavailable, an ADIS rejection makes it Rejected, for every
     * company of that call; a non-Czech lookup id is not sent and makes that company's Vat Rejected.
     * Section Vies is asked sequentially, one check() per company with a lookup id, so 100 companies
     * with Vies take minutes.
     *
     * @param list<CompanyId|string> $ids
     *
     * @throws \LogicException    a section was requested whose client was not configured (before any request)
     * @throws InvalidInput       an invalid company id or an element that is neither a CompanyId nor a string
     *                            (before any request), or ARES rejected the request; a section's is recorded as
     *                            Rejected instead
     * @throws ServiceUnavailable from ARES; a section outage is recorded as Unavailable instead
     * @throws InvalidResponse    from ARES; a section's invalid response is recorded as Unavailable instead
     */
    public function byCompanyIds(array $ids, Section ...$sections): CompanyProfiles
    {
        $requested = $this->requestedSections(...$sections);

        $companyIds = [];
        foreach ($ids as $index => $id) {
            $companyIds[] = Identifiers::companyId(ListElement::idOrString($id, $index, CompanyId::class));
        }

        $companies = $this->directory->findMany($companyIds);

        $subjects = new VatSubjects([]);
        $failure = null;
        if (isset($requested[Section::Vat->name])) {
            $lookupIds = [];
            foreach ($companies as $company) {
                $vatLookupId = $company->vatLookupId();
                if (null !== $vatLookupId && $vatLookupId->isCzech()) {
                    $lookupIds[(string) $vatLookupId] = $vatLookupId;
                }
            }

            if ([] !== $lookupIds) {
                try {
                    $subjects = $this->vatRegister()->findMany(array_values($lookupIds));
                } catch (ServiceUnavailable|InvalidResponse|InvalidInput $e) {
                    $failure = $e;
                }
            }
        }

        $findVat = static function (VatId $vatId) use ($subjects, $failure): ?VatSubject {
            // A non-Czech VAT id was not sent, so it is rejected whatever the call's outcome.
            $vatId = Identifiers::czechVatId($vatId);
            if (null !== $failure) {
                throw $failure;
            }

            return $subjects->get($vatId);
        };

        $profiles = [];
        foreach ($companies as $company) {
            $profiles[] = $this->profile($company, $requested, $findVat);
        }

        return new CompanyProfiles($profiles);
    }

    /**
     * De-duplicated sections; a missing client is a programming error, so it fails before any request is made.
     *
     * @return array<string, Section> keyed by Section::name
     *
     * @throws \LogicException a section whose client was not configured
     */
    private function requestedSections(Section ...$sections): array
    {
        $requested = [];
        foreach ($sections as $section) {
            $requested[$section->name] = $section;
        }

        foreach ($requested as $section) {
            match ($section) {
                Section::Vat => $this->vatRegister(),
                Section::Vies => $this->vies(),
            };
        }

        return $requested;
    }

    /**
     * @param array<string, Section>       $requested
     * @param \Closure(VatId): ?VatSubject $findVat   answers section Vat for a lookup id
     */
    private function profile(Company $company, array $requested, \Closure $findVat): CompanyProfile
    {
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
                        $vat = $findVat($vatLookupId);
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
            } catch (InvalidInput $e) {
                $statuses[$name] = SectionStatus::Rejected;
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
