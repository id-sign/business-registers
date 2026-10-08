<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares;

use IdSign\BusinessRegisters\Address;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\VatId;

/**
 * Economic subject as recorded in ARES (schema EkonomickySubjekt).
 */
final readonly class Company
{
    /**
     * Legal forms of natural persons per the ARES code list PravniForma: 101–108, 424 and 425 (source `res`) and 100
     * (sources `com`, `rzp`). 425, a branch of a foreign natural person, is included because its name carries a
     * person's name and is treated as a natural person for personal-data protection.
     */
    private const array NATURAL_PERSON_LEGAL_FORMS = ['100', '101', '102', '103', '104', '105', '106', '107', '108', '424', '425'];

    /**
     * @param string              $aresId               company id, or ARES_######## for a subject without one
     * @param ?CompanyId          $id                   null for a subject without a company id
     * @param ?string             $legalFormCode        ARES code list value, e.g. "121"
     * @param ?VatId              $vatId                own VAT id; a filled value does not mean a VAT payer
     * @param ?VatId              $groupVatId           VAT id of the VAT group the subject is a member of
     * @param ?string             $taxOfficeCode        ARES financniUrad: workplace (code list FinancniUrad, three digits, e.g. "293" Územní pracoviště Brno-venkov) or "013" Specialised Tax Office; equal to the ADIS code of VatSubject only for Specialised Tax Office subjects, do not compare
     * @param list<string>        $deliveryAddressLines filled lines of the delivery address only
     * @param ?\DateTimeImmutable $ceasedOn             ARES datumZaniku: end of existence (deletion from the register, NOZ § 185) or of the registration; not the dissolution decision
     * @param list<string>        $naceCodes            CZ-NACE 2025
     * @param list<string>        $naceCodes2008        CZ-NACE 2008
     * @param ?string             $fileNumber           file number in the public register, e.g. "B 1581/MSPH"
     * @param ?string             $primarySource        e.g. "ros", "vr"
     */
    public function __construct(
        public string $aresId,
        public ?CompanyId $id,
        public string $name,
        public ?string $legalFormCode,
        public ?VatId $vatId,
        public ?VatId $groupVatId,
        public ?string $taxOfficeCode,
        public ?Address $seat,
        public array $deliveryAddressLines,
        public ?\DateTimeImmutable $establishedOn,
        public ?\DateTimeImmutable $ceasedOn,
        public ?\DateTimeImmutable $updatedOn,
        public array $naceCodes,
        public array $naceCodes2008,
        public ?string $fileNumber,
        public ?string $primarySource,
        public Registrations $registrations,
    ) {
    }

    /**
     * Whether the legal form is one of a natural person: the self-employed forms 101–108, the domestic self-employed
     * natural person 100 (in source rzp also a non-entrepreneur natural person), or an enterprise or branch of a
     * foreign natural person (424, 425).
     */
    public function isNaturalPerson(): bool
    {
        return \in_array($this->legalFormCode, self::NATURAL_PERSON_LEGAL_FORMS, true);
    }

    /**
     * Whether the subject has ceased on $on (default: today in Europe/Prague): ceasedOn is set and not after $on,
     * both compared as calendar days (each date in its own time zone). ARES carries a future datumZaniku for
     * some active subjects (72396067, a natural person whose authorisation is recorded until 2035-12-10) and usually
     * answers 404 for a subject that has really ceased to exist.
     */
    public function hasCeased(?\DateTimeImmutable $on = null): bool
    {
        $on ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague'));

        return null !== $this->ceasedOn && $this->ceasedOn->format('Y-m-d') <= $on->format('Y-m-d');
    }

    /**
     * The VAT id under which the subject is kept in the VAT register: the group VAT id for a VAT
     * group member, otherwise the own VAT id.
     */
    public function vatLookupId(): ?VatId
    {
        return $this->groupVatId ?? $this->vatId;
    }
}
