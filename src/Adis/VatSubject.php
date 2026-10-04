<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Adis;

use IdSign\BusinessRegisters\Address;
use IdSign\BusinessRegisters\Adis\Internal\BankAccountNumber;
use IdSign\BusinessRegisters\VatId;

/**
 * Subject found in the VAT register.
 */
final readonly class VatSubject
{
    /**
     * @param ?\DateTimeImmutable $unreliableSince date the unreliability was published
     * @param ?string             $taxOfficeCode   three digits, e.g. "013"
     * @param list<BankAccount>   $bankAccounts    all published accounts, including ended ones
     * @param \DateTimeImmutable  $checkedAt       date the register generated the answer
     */
    public function __construct(
        public VatId $vatId,
        public SubjectType $type,
        public bool $unreliable,
        public ?\DateTimeImmutable $unreliableSince,
        public ?string $taxOfficeCode,
        public ?string $name,
        public ?Address $address,
        public array $bankAccounts,
        public \DateTimeImmutable $checkedAt,
    ) {
    }

    /**
     * True for a VAT payer and a VAT group.
     */
    public function isVatPayer(): bool
    {
        return SubjectType::VatPayer === $this->type || SubjectType::VatGroup === $this->type;
    }

    /**
     * @return list<BankAccount>
     */
    public function activeBankAccounts(): array
    {
        return array_values(array_filter($this->bankAccounts, static fn (BankAccount $account): bool => $account->isActive()));
    }

    /**
     * Whether $account is among the active published accounts. Czech accounts match in any
     * common domestic or IBAN form; other accounts match literally, ignoring case. Both sides ignore
     * whitespace, Unicode spaces included, and treat any dash variant as '-'. Unrecognisable input is false.
     */
    public function hasPublishedAccount(string $account): bool
    {
        $needle = BankAccountNumber::tryParse($account);

        return array_any(
            $this->activeBankAccounts(),
            static fn (BankAccount $published): bool => self::matches($published, $needle, $account),
        );
    }

    private static function matches(BankAccount $published, ?BankAccountNumber $needle, string $account): bool
    {
        $canonical = BankAccountNumber::tryParse((string) $published);

        if (null !== $canonical) {
            return null !== $needle && $canonical->equals($needle);
        }

        return !$published->isStandard() && BankAccountNumber::literal($published->number) === BankAccountNumber::literal($account);
    }
}
