<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

use IdSign\BusinessRegisters\Exception\InvalidInput;

/**
 * Czech company identification number (IČO), always eight digits.
 */
final readonly class CompanyId implements \Stringable
{
    private const array WEIGHTS = [8, 7, 6, 5, 4, 3, 2];

    private function __construct(
        public string $value,
    ) {
    }

    /**
     * Strips whitespace, left-pads to eight digits and verifies the check digit.
     *
     * @throws InvalidInput
     */
    public static function parse(string $input): self
    {
        $digits = preg_replace('/\s+/u', '', $input);

        if (null === $digits || 1 !== preg_match('/^\d{1,8}$/', $digits)) {
            throw new InvalidInput(\sprintf('Invalid company id (IČO) "%s": expected up to 8 digits.', $input));
        }

        $value = str_pad($digits, 8, '0', \STR_PAD_LEFT);

        if (!self::hasValidCheckDigit($value)) {
            throw new InvalidInput(\sprintf('Invalid company id (IČO) "%s": check digit does not match.', $input));
        }

        return new self($value);
    }

    public static function tryParse(string $input): ?self
    {
        try {
            return self::parse($input);
        } catch (InvalidInput) {
            return null;
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function hasValidCheckDigit(string $value): bool
    {
        $sum = 0;
        foreach (self::WEIGHTS as $position => $weight) {
            $sum += (int) $value[$position] * $weight;
        }

        return (int) $value[7] === (11 - $sum % 11) % 10;
    }
}
