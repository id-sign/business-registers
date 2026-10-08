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
     * @param ?MatchResult       $nameMatch          comparison of TraderDetails::$name; null when not returned
     * @param ?MatchResult       $streetMatch        comparison of TraderDetails::$street; null when not returned
     * @param ?MatchResult       $postalCodeMatch    comparison of TraderDetails::$postalCode; null when not returned
     * @param ?MatchResult       $cityMatch          comparison of TraderDetails::$city; null when not returned
     * @param ?MatchResult       $companyTypeMatch   comparison of TraderDetails::$companyType; null when not returned
     * @param ?string            $consultationNumber evidence that the check was made at that time (VIES calls it only one of the elements of evidence; keep it); issued only when a requester was given
     * @param \DateTimeImmutable $checkedAt          time of the check in UTC
     */
    public function __construct(
        public VatId $vatId,
        public bool $valid,
        public ?string $name,
        public ?string $address,
        public ?MatchResult $nameMatch,
        public ?MatchResult $streetMatch,
        public ?MatchResult $postalCodeMatch,
        public ?MatchResult $cityMatch,
        public ?MatchResult $companyTypeMatch,
        public ?string $consultationNumber,
        public \DateTimeImmutable $checkedAt,
    ) {
    }
}
