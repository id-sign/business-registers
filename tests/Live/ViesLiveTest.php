<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Live;

use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Vies\ViesClient;
use IdSign\BusinessRegisters\Vies\ViesResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

#[Group('live')]
#[CoversNothing]
final class ViesLiveTest extends TestCase
{
    private const string TEST_SERVICE = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service';

    private static function production(): ViesClient
    {
        return new ViesClient(HttpClient::create(), timeout: 30.0);
    }

    private static function testService(): ViesClient
    {
        return new ViesClient(HttpClient::create(), self::TEST_SERVICE, 30.0);
    }

    public function testCezIsValidWithAName(): void
    {
        $result = $this->unlessThrottled(static fn (): ViesResult => self::production()->check('CZ45274649'));

        self::assertTrue($result->valid);
        self::assertNotNull($result->name);
        self::assertNotSame('', $result->name);
    }

    public function testUnknownVatIdIsInvalid(): void
    {
        $result = $this->unlessThrottled(static fn (): ViesResult => self::production()->check('CZ11111111'));

        self::assertFalse($result->valid);
    }

    public function testTestServiceNumber100IsValid(): void
    {
        $result = self::testService()->check('DE100');

        self::assertTrue($result->valid);
    }

    public function testTestServiceNumber200IsInvalid(): void
    {
        $result = self::testService()->check('DE200');

        self::assertFalse($result->valid);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidInputNumbers(): iterable
    {
        yield 'INVALID_INPUT' => ['DE201', 'INVALID_INPUT'];
        yield 'INVALID_REQUESTER_INFO' => ['DE202', 'INVALID_REQUESTER_INFO'];
    }

    #[DataProvider('provideInvalidInputNumbers')]
    public function testTestServiceInputErrorsAreInvalidInput(string $vatId, string $code): void
    {
        try {
            self::testService()->check($vatId);
        } catch (InvalidInput $e) {
            self::assertSame($code, $e->errorCode);

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideServiceUnavailableNumbers(): iterable
    {
        yield 'SERVICE_UNAVAILABLE' => ['DE300', 'SERVICE_UNAVAILABLE'];
        yield 'MS_UNAVAILABLE' => ['DE301', 'MS_UNAVAILABLE'];
        yield 'TIMEOUT' => ['DE302', 'TIMEOUT'];
        yield 'VAT_BLOCKED' => ['DE400', 'VAT_BLOCKED'];
        yield 'IP_BLOCKED' => ['DE401', 'IP_BLOCKED'];
        yield 'GLOBAL_MAX_CONCURRENT_REQ' => ['DE500', 'GLOBAL_MAX_CONCURRENT_REQ'];
        yield 'GLOBAL_MAX_CONCURRENT_REQ_TIME' => ['DE501', 'GLOBAL_MAX_CONCURRENT_REQ_TIME'];
        yield 'MS_MAX_CONCURRENT_REQ' => ['DE600', 'MS_MAX_CONCURRENT_REQ'];
        yield 'MS_MAX_CONCURRENT_REQ_TIME' => ['DE601', 'MS_MAX_CONCURRENT_REQ_TIME'];
    }

    #[DataProvider('provideServiceUnavailableNumbers')]
    public function testTestServiceOutageCodesAreServiceUnavailable(string $vatId, string $code): void
    {
        try {
            self::testService()->check($vatId);
        } catch (ServiceUnavailable $e) {
            self::assertSame($code, $e->errorCode);

            return;
        }

        self::fail('Expected ServiceUnavailable was not thrown.');
    }

    /**
     * Production VIES throttles; an outage says nothing about the VAT id, so the test is skipped.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function unlessThrottled(callable $call): mixed
    {
        try {
            return $call();
        } catch (ServiceUnavailable $e) {
            self::markTestSkipped(\sprintf('VIES is unavailable (%s).', $e->errorCode ?? 'no error code'));
        }
    }
}
