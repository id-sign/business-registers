<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Adis;

use IdSign\BusinessRegisters\VatId;

/**
 * Entry of the list of unreliable VAT payers.
 */
final readonly class UnreliablePayer
{
    /**
     * @param ?\DateTimeImmutable $since         date the unreliability was published
     * @param ?string             $taxOfficeCode ADIS cisloFu: regional office 451–464 (e.g. "461" Finanční úřad pro Jihomoravský kraj) or "013" Specialised Tax Office (code list FinancniUrad); equal to Company::$taxOfficeCode only for Specialised Tax Office subjects, do not compare
     */
    public function __construct(
        public VatId $vatId,
        public ?\DateTimeImmutable $since,
        public ?string $taxOfficeCode,
    ) {
    }
}
