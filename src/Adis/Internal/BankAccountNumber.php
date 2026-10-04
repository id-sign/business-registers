<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Adis\Internal;

/**
 * Czech bank account in the canonical domestic form, for comparing accounts written differently.
 *
 * @internal
 */
final readonly class BankAccountNumber
{
    /**
     * @param string $prefix   without leading zeros, '' when there is none
     * @param string $number   without leading zeros
     * @param string $bankCode four digits
     */
    private function __construct(
        public string $prefix,
        public string $number,
        public string $bankCode,
    ) {
    }

    /**
     * Accepts "000019-0002808601/0100", "19-2808601/0100", "2808601/0100" and a Czech IBAN
     * (check digits are not verified), with any whitespace including Unicode spaces, any letter case
     * and any dash variant as the prefix separator.
     */
    public static function tryParse(string $input): ?self
    {
        $normalised = self::literal($input);

        if (1 === preg_match('~^(?:(\d{1,6})-)?(\d{1,10})/(\d{4})$~D', $normalised, $matches)) {
            return new self(ltrim($matches[1], '0'), ltrim($matches[2], '0'), $matches[3]);
        }

        if (1 === preg_match('~^CZ\d{2}(\d{4})(\d{6})(\d{10})$~D', $normalised, $matches)) {
            return new self(ltrim($matches[2], '0'), ltrim($matches[3], '0'), $matches[1]);
        }

        return null;
    }

    /**
     * Whitespace removed, dash variants unified to '-' and upper-cased; the normalisation behind every
     * account comparison, and the whole comparison for accounts that are not Czech.
     */
    public static function literal(string $input): string
    {
        // Copied account numbers often carry Unicode spaces (U+00A0, U+202F, ...), same rule as CompanyId.
        $input = preg_replace('/\s+/u', '', $input) ?? $input;
        // Word processors turn the prefix hyphen into an en dash or a minus sign (U+2212 is not \p{Pd}).
        $input = preg_replace('/[\p{Pd}\x{2212}]/u', '-', $input) ?? $input;

        return strtoupper($input);
    }

    public function equals(self $other): bool
    {
        return $this->prefix === $other->prefix && $this->number === $other->number && $this->bankCode === $other->bankCode;
    }
}
