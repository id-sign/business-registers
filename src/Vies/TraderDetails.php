<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Vies;

/**
 * Trader data VIES compares against the register of the member state; null and blank (whitespace-only) fields are
 * not sent, the others are sent unchanged.
 */
final readonly class TraderDetails
{
    public function __construct(
        public ?string $name = null,
        public ?string $street = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $companyType = null,
    ) {
    }
}
