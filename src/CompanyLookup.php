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
use IdSign\BusinessRegisters\Isir\InsolvencyProceedings;
use IdSign\BusinessRegisters\Isir\InsolvencyRegister;
use IdSign\BusinessRegisters\Vies\Vies;
use IdSign\BusinessRegisters\Vies\ViesResult;

/**
 * Assembles a company profile from ARES and the requested sections; an outage of a section source
 * makes that section Unavailable and a rejected section request makes it Rejected, instead of
 * failing the whole lookup. Sections Vat and Vies are looked up under Company::vatLookupId(), section
 * Insolvency under Company::$id.
 */
final readonly class CompanyLookup
{
    private const string REQUESTER_REJECTED = 'INVALID_REQUESTER_INFO';

    /**
     * @param ?VatId $viesRequester own VAT id passed to VIES, so that it issues a consultation number
     */
    public function __construct(
        private CompanyDirectory $directory,
        private ?VatRegister $vatRegister = null,
        private ?Vies $vies = null,
        private ?InsolvencyRegister $insolvencyRegister = null,
        private ?VatId $viesRequester = null,
    ) {
    }

    /**
     * Null when ARES does not hold the subject. Without sections only ARES is asked (one request).
     * Each section is asked once, in the given order. Vat and Vies are looked up under
     * Company::vatLookupId(), so a VAT group member is looked up under the group VAT id; Insolvency
     * is looked up under Company::$id. A section whose id the company lacks is NotApplicable.
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

        return $this->profile(
            $company,
            $requested,
            fn (VatId $vatId): ?VatSubject => $this->vatRegister()->find($vatId),
            fn (VatId $vatId): ViesResult => $this->vies()->check($vatId, $this->viesRequester),
            fn (CompanyId $companyId): InsolvencyProceedings => $this->insolvencyRegister()->find($companyId),
        );
    }

    /**
     * Profiles of the companies ARES holds, in the order ARES returns them; ids ARES does not hold are
     * absent and reported by CompanyProfiles::missing(). The statuses mean the same as in byCompanyId().
     *
     * ARES is asked with one findMany() and section Vat with one findMany() over the distinct
     * Company::vatLookupId() values, so 100 ids with Vat are one ARES and one ADIS request. An ADIS
     * outage or invalid response makes Vat Unavailable, an ADIS rejection makes it Rejected, for every
     * company of that call; a non-Czech lookup id is not sent and makes that company's Vat Rejected.
     * Section Vies is asked sequentially, one check() per distinct Company::vatLookupId() (a VAT group is
     * checked once for all its members), and the outcome, result or exception, is shared by every company
     * with that lookup id, so 100 companies with Vies still take minutes. After VIES rejects the requester
     * (InvalidInput with errorCode INVALID_REQUESTER_INFO) no further check() is made in that call: every
     * company whose lookup id was not yet answered gets Vies Rejected with that same exception instance,
     * while a company whose lookup id was already answered keeps that answer. Any other VIES failure
     * affects only the companies with that lookup id.
     *
     * Section Insolvency is one find() per company, sequentially, in the order ARES returns them; a failure
     * affects only that company; typically 0.10–0.25 s per company (100 companies ≈ 10–25 s), worst case
     * $timeout per company when ISIR is down.
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

        /** @var array<string, ViesResult|InvalidInput|ServiceUnavailable|InvalidResponse> $answers */
        $answers = [];
        /** @var ?InvalidInput $rejection */
        $rejection = null;
        $checkVies = function (VatId $vatId) use (&$answers, &$rejection): ViesResult {
            $key = (string) $vatId;
            if (!isset($answers[$key])) {
                if (null !== $rejection) {
                    throw $rejection;
                }

                try {
                    $answers[$key] = $this->vies()->check($vatId, $this->viesRequester);
                } catch (ServiceUnavailable|InvalidResponse|InvalidInput $e) {
                    $answers[$key] = $e;
                    if ($e instanceof InvalidInput && self::REQUESTER_REJECTED === $e->errorCode) {
                        $rejection = $e;
                    }
                }
            }

            $answer = $answers[$key];
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        };

        $profiles = [];
        foreach ($companies as $company) {
            $profiles[] = $this->profile(
                $company,
                $requested,
                $findVat,
                $checkVies,
                fn (CompanyId $companyId): InsolvencyProceedings => $this->insolvencyRegister()->find($companyId),
            );
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
                Section::Insolvency => $this->insolvencyRegister(),
            };
        }

        return $requested;
    }

    /**
     * @param array<string, Section>                     $requested
     * @param \Closure(VatId): ?VatSubject               $findVat          answers section Vat for a lookup id
     * @param \Closure(VatId): ViesResult                $checkVies        answers section Vies for a lookup id
     * @param \Closure(CompanyId): InsolvencyProceedings $findInsolvencies answers section Insolvency for a company id
     */
    private function profile(Company $company, array $requested, \Closure $findVat, \Closure $checkVies, \Closure $findInsolvencies): CompanyProfile
    {
        $vatLookupId = $company->vatLookupId();
        $vat = null;
        $vies = null;
        $insolvencies = null;
        $statuses = [];
        $errors = [];
        foreach ($requested as $name => $section) {
            // Without the id the section is queried under, its client is not asked.
            $statuses[$name] = SectionStatus::NotApplicable;
            try {
                switch ($section) {
                    case Section::Vat:
                        if (null !== $vatLookupId) {
                            $vat = $findVat($vatLookupId);
                            $statuses[$name] = null === $vat ? SectionStatus::NotFound : SectionStatus::Ok;
                        }
                        break;
                    case Section::Vies:
                        if (null !== $vatLookupId) {
                            $vies = $checkVies($vatLookupId);
                            $statuses[$name] = SectionStatus::Ok;
                        }
                        break;
                    case Section::Insolvency:
                        if (null !== $company->id) {
                            $insolvencies = $findInsolvencies($company->id);
                            $statuses[$name] = SectionStatus::Ok;
                        }
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

        return new CompanyProfile($company, $vat, $vies, $insolvencies, $statuses, $errors);
    }

    private function vatRegister(): VatRegister
    {
        return $this->vatRegister ?? throw new \LogicException('Section Vat requested but no VatRegister was configured');
    }

    private function vies(): Vies
    {
        return $this->vies ?? throw new \LogicException('Section Vies requested but no Vies was configured');
    }

    private function insolvencyRegister(): InsolvencyRegister
    {
        return $this->insolvencyRegister ?? throw new \LogicException('Section Insolvency requested but no InsolvencyRegister was configured');
    }
}
