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
     * @param ?string             $taxOfficeCode three digits, e.g. "013"
     */
    public function __construct(
        public VatId $vatId,
        public ?\DateTimeImmutable $since,
        public ?string $taxOfficeCode,
    ) {
    }
}
