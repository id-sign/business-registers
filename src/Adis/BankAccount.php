<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Adis;

/**
 * Bank account published in the VAT register.
 */
final readonly class BankAccount implements \Stringable
{
    /**
     * @param ?string $prefix   standard accounts only, as published
     * @param string  $number   standard account: the number without prefix; non-standard: the whole account (usually an IBAN)
     * @param ?string $bankCode null for a non-standard account
     */
    public function __construct(
        public ?string $prefix,
        public string $number,
        public ?string $bankCode,
        public \DateTimeImmutable $publishedFrom,
        public ?\DateTimeImmutable $publishedUntil,
    ) {
    }

    public function isStandard(): bool
    {
        return null !== $this->bankCode;
    }

    public function isActive(): bool
    {
        return null === $this->publishedUntil;
    }

    /**
     * "27-5868650297/0100", "71504011/0100", or the non-standard number unchanged.
     */
    public function __toString(): string
    {
        if (null === $this->bankCode) {
            return $this->number;
        }

        $prefix = null === $this->prefix ? '' : $this->prefix.'-';

        return $prefix.$this->number.'/'.$this->bankCode;
    }
}
