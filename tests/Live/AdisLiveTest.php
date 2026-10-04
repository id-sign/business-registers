<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Live;

use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\VatRegisterClient;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\VatId;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

#[Group('live')]
#[CoversNothing]
final class AdisLiveTest extends TestCase
{
    private static function client(): VatRegisterClient
    {
        return new VatRegisterClient(HttpClient::create(), timeout: 30.0);
    }

    public function testCezIsAVatPayerWithPublishedAccounts(): void
    {
        $subject = $this->unlessInMaintenance(static fn () => self::client()->find('CZ45274649'));

        self::assertNotNull($subject);
        self::assertSame('CZ45274649', (string) $subject->vatId);
        self::assertTrue($subject->isVatPayer());
        self::assertNotNull($subject->taxOfficeCode);
        self::assertMatchesRegularExpression('/^\d{3}$/', $subject->taxOfficeCode);
        $active = $subject->activeBankAccounts();
        self::assertNotSame([], $active);
        self::assertTrue($subject->hasPublishedAccount((string) $active[0]));
    }

    public function testKomercniBankaIsFoundAsAVatGroupUnderTheGroupVatId(): void
    {
        $subject = $this->unlessInMaintenance(static fn () => self::client()->find('CZ699001182'));

        self::assertNotNull($subject);
        self::assertSame(SubjectType::VatGroup, $subject->type);
    }

    public function testLibraryIsAnIdentifiedPerson(): void
    {
        $subject = $this->unlessInMaintenance(static fn () => self::client()->find('CZ00101494'));

        self::assertNotNull($subject);
        self::assertSame(SubjectType::IdentifiedPerson, $subject->type);
    }

    public function testLidruIsAnUnreliablePayer(): void
    {
        $subject = $this->unlessInMaintenance(static fn () => self::client()->find('CZ00121100'));

        self::assertNotNull($subject);
        self::assertTrue($subject->unreliable);
    }

    public function testUnknownVatIdIsNotFound(): void
    {
        self::assertNull($this->unlessInMaintenance(static fn () => self::client()->find('CZ11111111')));
    }

    public function testFindManyReturnsTheKnownSubjectsAndReportsTheUnknownOneAsMissing(): void
    {
        $requested = ['CZ45274649', 'CZ699001182', 'CZ00101494', 'CZ00121100', 'CZ11111111'];

        $subjects = $this->unlessInMaintenance(static fn () => self::client()->findMany($requested));

        self::assertCount(4, $subjects);
        foreach (\array_slice($requested, 0, 4) as $vatId) {
            self::assertTrue($subjects->has($vatId));
        }
        self::assertFalse($subjects->has('CZ11111111'));
        self::assertSame(['CZ11111111'], array_map(static fn (VatId $vatId): string => (string) $vatId, $subjects->missing($requested)));
    }

    public function testListOfUnreliablePayersIsNotEmpty(): void
    {
        $payers = $this->unlessInMaintenance(static fn () => self::client()->unreliablePayers());

        self::assertNotSame([], $payers);
        self::assertNotSame('', (string) $payers[0]->vatId);
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function unlessInMaintenance(callable $call): mixed
    {
        try {
            return $call();
        } catch (ServiceUnavailable $e) {
            if ('2' === $e->errorCode) {
                self::markTestSkipped('ADIS is in its nightly maintenance window.');
            }

            throw $e;
        }
    }
}
