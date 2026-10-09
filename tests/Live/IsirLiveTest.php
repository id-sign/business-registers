<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Live;

use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Isir\InsolvencyClient;
use IdSign\BusinessRegisters\Isir\InsolvencyProceeding;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

#[Group('live')]
#[CoversNothing]
final class IsirLiveTest extends TestCase
{
    private static function client(): InsolvencyClient
    {
        return new InsolvencyClient(HttpClient::create(), timeout: 30.0);
    }

    public function testSberbankHasAnOngoingProceeding(): void
    {
        $proceedings = self::client()->find('25083325');

        self::assertGreaterThanOrEqual(1, \count($proceedings));
        self::assertTrue($proceedings->hasOngoing());
    }

    /**
     * The register answers an empty result without a synchronisation time, so only the emptiness is asserted.
     */
    public function testCezHasNoProceedingListed(): void
    {
        $proceedings = self::client()->find('45274649');

        self::assertCount(0, $proceedings);
        self::assertFalse($proceedings->hasOngoing());
    }

    public function testCeskeAerolinieHaveAnEndedProceeding(): void
    {
        $proceedings = self::client()->find('45795908');

        // the response order is undocumented, so the company's own ended row is searched for
        $ended = array_filter(
            $proceedings->proceedings,
            static fn (InsolvencyProceeding $p): bool => '45795908' === $p->companyId?->value && null !== $p->endedOn,
        );
        self::assertGreaterThanOrEqual(1, \count($ended));
        foreach ($ended as $proceeding) {
            self::assertFalse($proceeding->isOngoing());
        }
    }

    public function testCompanyIdWithoutLeadingZerosIsFound(): void
    {
        $proceedings = self::client()->find('121100');

        self::assertTrue(array_any(
            $proceedings->proceedings,
            static fn (InsolvencyProceeding $p): bool => '00121100' === $p->companyId?->value,
        ));
    }

    public function testCompanyIdWithAWrongCheckDigitIsRejectedBeforeAnyRequest(): void
    {
        $this->expectException(InvalidInput::class);

        self::client()->find('12345678');
    }
}
