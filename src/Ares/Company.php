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
    private const array NATURAL_PERSON_LEGAL_FORMS = ['101', '102', '103', '104', '105', '106', '107', '108'];

    /**
     * @param string       $aresId               company id, or ARES_######## for a subject without one
     * @param ?CompanyId   $id                   null for a subject without a company id
     * @param ?string      $legalFormCode        ARES code list value, e.g. "121"
     * @param ?VatId       $vatId                own VAT id; a filled value does not mean a VAT payer
     * @param ?VatId       $groupVatId           VAT id of the VAT group the subject is a member of
     * @param ?string      $taxOfficeCode        three digits, e.g. "013"
     * @param list<string> $deliveryAddressLines filled lines of the delivery address only
     * @param list<string> $naceCodes            CZ-NACE 2025
     * @param list<string> $naceCodes2008        CZ-NACE 2008
     * @param ?string      $fileNumber           file number in the public register, e.g. "B 1581/MSPH"
     * @param ?string      $primarySource        e.g. "ros", "vr"
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
        public ?\DateTimeImmutable $dissolvedOn,
        public ?\DateTimeImmutable $updatedOn,
        public array $naceCodes,
        public array $naceCodes2008,
        public ?string $fileNumber,
        public ?string $primarySource,
        public Registrations $registrations,
    ) {
    }

    /**
     * Legal forms 101–108 (self-employed natural persons).
     */
    public function isNaturalPerson(): bool
    {
        return \in_array($this->legalFormCode, self::NATURAL_PERSON_LEGAL_FORMS, true);
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
