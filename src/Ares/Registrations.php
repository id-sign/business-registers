<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares;

/**
 * Status of the subject in the 16 source registers of ARES.
 *
 * What the statuses mean and do not mean:
 *
 * - Vat is Active for VAT payers and for identified persons alike (Knihovna Jiřího Mahena,
 *   CZ00101494, is Active here but an identified person in ADIS). Only the VAT register (ADIS)
 *   decides whether a subject is a VAT payer.
 * - Company::$vatId stays filled after the VAT registration ended (26863154 has a VAT id with
 *   Vat = Dissolved). A filled VAT id does not mean a VAT payer.
 * - A member of a VAT group has a group VAT id; its own VAT id is either null (Komerční banka, 45317054:
 *   no VAT id, group VAT id CZ699001182, Vat = Nonexistent, VatGroup = Active) or its former own VAT id
 *   (21985685, Vat = Dissolved). The VAT register answers for the group under the group VAT id; in rare
 *   cases it still answers the member's own VAT id as a VAT payer as well. Query it with
 *   Company::vatLookupId(), never with Company::$vatId; a VAT id derived from the company id is usually
 *   not found there.
 * - Natural persons have VAT ids in ARES too (nine or ten digits): the birth number (nine digits for births
 *   before 1954) or a nine-digit number assigned by the tax administrator (starts with 6).
 *   Never derive a VAT id from the company id; always take it from ARES.
 * - Insolvency stays Active after the proceedings ended (České aerolinie, 45795908, proceedings
 *   closed on 1 July 2022). Only the insolvency register (ISIR) tells whether an insolvency is current.
 * - Bankruptcy (CEÚ) does not reflect insolvency (Sberbank CZ, 25083325, in bankruptcy: Nonexistent).
 *   Do not use it.
 */
final readonly class Registrations
{
    /**
     * @param array<string, RegistrationStatus> $statuses keyed by AresRegister::value, all 16 registers
     */
    public function __construct(
        public array $statuses,
    ) {
    }

    public function status(AresRegister $register): RegistrationStatus
    {
        return $this->statuses[$register->value] ?? RegistrationStatus::Nonexistent;
    }

    public function isActive(AresRegister $register): bool
    {
        return RegistrationStatus::Active === $this->status($register);
    }

    /**
     * @return list<AresRegister> in enum order
     */
    public function active(): array
    {
        return array_values(array_filter(AresRegister::cases(), $this->isActive(...)));
    }
}
