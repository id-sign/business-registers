<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Adis;

use IdSign\BusinessRegisters\Adis\BankAccount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BankAccount::class)]
final class BankAccountTest extends TestCase
{
    private static function account(?string $prefix, string $number, ?string $bankCode, ?\DateTimeImmutable $publishedUntil = null): BankAccount
    {
        return new BankAccount(
            prefix: $prefix,
            number: $number,
            bankCode: $bankCode,
            publishedFrom: new \DateTimeImmutable('2013-04-01'),
            publishedUntil: $publishedUntil,
        );
    }

    /**
     * @return iterable<string, array{?string, string, ?string, string}>
     */
    public static function provideStringForms(): iterable
    {
        yield 'standard with prefix' => ['27', '5868650297', '0100', '27-5868650297/0100'];
        yield 'standard without prefix' => [null, '71504011', '0100', '71504011/0100'];
        yield 'non-standard iban unchanged' => [null, 'CZ6426000000002001268200', null, 'CZ6426000000002001268200'];
        yield 'non-standard foreign unchanged' => [null, 'DE89 3704 0044 0532 0130 00', null, 'DE89 3704 0044 0532 0130 00'];
    }

    #[DataProvider('provideStringForms')]
    public function testStringFormDependsOnPrefixAndBankCode(?string $prefix, string $number, ?string $bankCode, string $expected): void
    {
        self::assertSame($expected, (string) self::account($prefix, $number, $bankCode));
    }

    public function testAccountWithBankCodeIsStandard(): void
    {
        self::assertTrue(self::account(null, '71504011', '0100')->isStandard());
    }

    public function testAccountWithoutBankCodeIsNotStandard(): void
    {
        self::assertFalse(self::account(null, 'CZ6426000000002001268200', null)->isStandard());
    }

    public function testAccountWithoutPublicationEndIsActive(): void
    {
        self::assertTrue(self::account(null, '71504011', '0100')->isActive());
    }

    public function testAccountWithPublicationEndIsNotActive(): void
    {
        $account = self::account(null, '71504011', '0100', new \DateTimeImmutable('2020-12-31'));

        self::assertFalse($account->isActive());
    }
}
