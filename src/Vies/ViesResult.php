<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Vies;

use IdSign\BusinessRegisters\VatId;

/**
 * Answer of VIES for one VAT id.
 */
final readonly class ViesResult
{
    /**
     * @param ?string            $name               null when the member state does not disclose it (e.g. Germany)
     * @param ?string            $address            multi-line; null when the member state does not disclose it
     * @param ?string            $consultationNumber proof of the check; VIES issues it only when a requester was given
     * @param \DateTimeImmutable $checkedAt          time of the check in UTC
     */
    public function __construct(
        public VatId $vatId,
        public bool $valid,
        public ?string $name,
        public ?string $address,
        public ?string $consultationNumber,
        public \DateTimeImmutable $checkedAt,
    ) {
    }
}
