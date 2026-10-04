<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

use IdSign\BusinessRegisters\Exception\InvalidInput;

/**
 * VAT identification number (DIČ) split into the VIES country code and the number.
 */
final readonly class VatId implements \Stringable
{
    private const string CZECH = 'CZ';

    /**
     * @param string $countryCode two upper-case letters as used by VIES (Greece = EL, Northern Ireland = XI)
     * @param string $number      number without the country prefix
     */
    private function __construct(
        public string $countryCode,
        public string $number,
    ) {
    }

    /**
     * Strips whitespace and upper-cases the input; a leading pair of letters is the country code,
     * otherwise $defaultCountry is used.
     *
     * @throws InvalidInput
     */
    public static function parse(string $input, ?string $defaultCountry = null): self
    {
        $normalised = preg_replace('/\s+/u', '', $input);

        if (null === $normalised) {
            throw new InvalidInput(\sprintf('Invalid VAT id (DIČ) "%s".', $input));
        }

        $normalised = strtoupper($normalised);
        $defaultCountry = null === $defaultCountry ? null : strtoupper($defaultCountry);

        if (1 === preg_match('/^([A-Z]{2})(.*)$/', $normalised, $matches)) {
            [, $country, $number] = $matches;
        } elseif (null !== $defaultCountry && 1 === preg_match('/^[A-Z]{2}$/D', $defaultCountry)) {
            $country = $defaultCountry;
            $number = $normalised;
        } elseif (null !== $defaultCountry) {
            throw new InvalidInput(\sprintf('Invalid default country "%s" for VAT id (DIČ) "%s": expected two letters.', $defaultCountry, $input));
        } else {
            throw new InvalidInput(\sprintf('Invalid VAT id (DIČ) "%s": missing country code.', $input));
        }

        if ('GR' === $country) {
            $country = 'EL';
        }

        $pattern = self::CZECH === $country ? '/^\d{8,10}$/' : '/^[0-9A-Z+*]{2,12}$/';
        if (1 !== preg_match($pattern, $number)) {
            throw new InvalidInput(\sprintf('Invalid VAT id (DIČ) "%s": invalid number for country %s.', $input, $country));
        }

        return new self($country, $number);
    }

    public static function tryParse(string $input, ?string $defaultCountry = null): ?self
    {
        try {
            return self::parse($input, $defaultCountry);
        } catch (InvalidInput) {
            return null;
        }
    }

    public function isCzech(): bool
    {
        return self::CZECH === $this->countryCode;
    }

    public function equals(self $other): bool
    {
        return $this->countryCode === $other->countryCode && $this->number === $other->number;
    }

    public function __toString(): string
    {
        return $this->countryCode.$this->number;
    }
}
