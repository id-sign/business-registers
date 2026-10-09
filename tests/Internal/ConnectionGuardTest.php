<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Internal;

use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Internal\ConnectionGuard;
use IdSign\BusinessRegisters\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionGuard::class)]
final class ConnectionGuardTest extends TestCase
{
    public function testAnswerIsPassedThrough(): void
    {
        $answer = new \stdClass();

        self::assertSame($answer, (new ConnectionGuard(Source::Isir, 1))->call(static fn (): \stdClass => $answer, 'company id 45274649'));
    }

    public function testRequestIsNotSentOnceTheLimitIsReached(): void
    {
        $guard = new ConnectionGuard(Source::Isir, 1);
        $failure = self::connectionFailure();
        self::assertSame($failure, self::thrownBy($guard, static fn (): never => throw $failure));

        $sent = false;
        $e = self::thrownBy($guard, static function () use (&$sent): string {
            $sent = true;

            return 'answer';
        });

        self::assertFalse($sent);
        self::assertInstanceOf(ServiceUnavailable::class, $e);
        self::assertSame(Source::Isir, $e->source);
        self::assertTrue($e->connectionFailed);
        self::assertNull($e->errorCode);
        self::assertSame($failure, $e->getPrevious());
        self::assertSame('ISIR request for company id 45274649 was not sent after 1 connection failures in a row', $e->getMessage());
    }

    public function testAnyOtherOutcomeResetsTheCount(): void
    {
        $guard = new ConnectionGuard(Source::Vies, 2);
        $outcomes = [
            static fn (): string => 'answer',
            static fn (): never => throw new ServiceUnavailable('VIES is busy', Source::Vies, 'MS_MAX_CONCURRENT_REQ'),
            static fn (): never => throw new InvalidResponse('VIES: missing s:Body', Source::Vies),
            static fn (): never => throw new \RuntimeException('consumer failure'),
        ];

        foreach ($outcomes as $outcome) {
            self::thrownBy($guard, static fn (): never => throw self::connectionFailure());
            self::thrownBy($guard, $outcome);
        }

        $sent = false;
        $guard->call(static function () use (&$sent): void {
            $sent = true;
        }, 'VAT id CZ45274649');

        self::assertTrue($sent);
    }

    private static function connectionFailure(): ServiceUnavailable
    {
        return new ServiceUnavailable('request failed', Source::Isir, connectionFailed: true);
    }

    /**
     * @param \Closure(): mixed $request
     */
    private static function thrownBy(ConnectionGuard $guard, \Closure $request): ?\Throwable
    {
        try {
            $guard->call($request, 'company id 45274649');
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}
