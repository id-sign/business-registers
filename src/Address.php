<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

/**
 * Postal address shared by all sources; each source fills what it has.
 */
final readonly class Address
{
    /**
     * @param ?string $street            street name with house and orientation numbers, e.g. "Duhová 1444/2"
     * @param ?int    $houseNumberType   1 descriptive (popisné), 2 registration (evidenční), 3 substitute (náhradní)
     * @param ?string $orientationNumber orientation number including its letter
     * @param ?int    $addressPointId    RÚIAN address point code
     * @param ?int    $municipalityCode  RÚIAN municipality code
     */
    public function __construct(
        public ?string $text = null,
        public ?string $street = null,
        public ?string $streetName = null,
        public ?string $houseNumber = null,
        public ?int $houseNumberType = null,
        public ?string $orientationNumber = null,
        public ?string $district = null,
        public ?string $cityDistrict = null,
        public ?string $city = null,
        public ?string $postalCode = null,
        public ?string $county = null,
        public ?string $region = null,
        public ?string $countryCode = null,
        public ?string $countryName = null,
        public ?int $addressPointId = null,
        public ?int $municipalityCode = null,
    ) {
    }

    /**
     * Returns a five-digit postal code as "140 00"; any other value unchanged.
     */
    public function postalCodeFormatted(): ?string
    {
        if (null === $this->postalCode || 1 !== preg_match('/^\d{5}$/D', $this->postalCode)) {
            return $this->postalCode;
        }

        return substr($this->postalCode, 0, 3).' '.substr($this->postalCode, 3);
    }
}
