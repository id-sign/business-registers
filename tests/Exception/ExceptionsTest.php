<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Exception;

use IdSign\BusinessRegisters\Exception\ExceptionInterface;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvalidInput::class)]
#[CoversClass(ServiceUnavailable::class)]
#[CoversClass(InvalidResponse::class)]
#[CoversClass(Source::class)]
final class ExceptionsTest extends TestCase
{
    public function testAllExceptionsShareTheLibraryMarkerInterface(): void
    {
        self::assertTrue((new \ReflectionClass(InvalidInput::class))->implementsInterface(ExceptionInterface::class));
        self::assertTrue((new \ReflectionClass(ServiceUnavailable::class))->implementsInterface(ExceptionInterface::class));
        self::assertTrue((new \ReflectionClass(InvalidResponse::class))->implementsInterface(ExceptionInterface::class));
    }

    public function testMarkerInterfaceExtendsThrowable(): void
    {
        self::assertTrue((new \ReflectionClass(ExceptionInterface::class))->isSubclassOf(\Throwable::class));
    }

    public function testInvalidInputIsAnInvalidArgumentException(): void
    {
        self::assertTrue((new \ReflectionClass(InvalidInput::class))->isSubclassOf(\InvalidArgumentException::class));
    }

    public function testServiceUnavailableAndInvalidResponseAreRuntimeExceptions(): void
    {
        self::assertTrue((new \ReflectionClass(ServiceUnavailable::class))->isSubclassOf(\RuntimeException::class));
        self::assertTrue((new \ReflectionClass(InvalidResponse::class))->isSubclassOf(\RuntimeException::class));
    }

    public function testInvalidInputAppendsTheErrorCodeToTheMessageWhenGiven(): void
    {
        self::assertSame('Bad input (error code X1)', (new InvalidInput('Bad input', 'X1'))->getMessage());
    }

    public function testInvalidInputLeavesTheMessageUntouchedWithoutErrorCode(): void
    {
        self::assertSame('Bad input', (new InvalidInput('Bad input'))->getMessage());
    }

    public function testServiceUnavailableAppendsTheErrorCodeToTheMessageWhenGiven(): void
    {
        self::assertSame('Try later (error code X1)', (new ServiceUnavailable('Try later', Source::Vies, 'X1'))->getMessage());
    }

    public function testServiceUnavailableIsNotAConnectionFailureUnlessSaidSo(): void
    {
        self::assertFalse((new ServiceUnavailable('Try later', Source::Isir, 'WS4'))->connectionFailed);
        self::assertTrue((new ServiceUnavailable('Try later', Source::Isir, connectionFailed: true))->connectionFailed);
    }

    public function testServiceUnavailableLeavesTheMessageUntouchedWithoutErrorCode(): void
    {
        self::assertSame('Try later', (new ServiceUnavailable('Try later', Source::Vies))->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTokenLikeErrorCodes(): iterable
    {
        yield 'ares' => ['VSTUP_NEVALIDNI_FORMAT_ICO'];
        yield 'numeric' => ['2'];
        yield 'soap fault' => ['soapenv:Server'];
        yield 'dash' => ['VOW-ERR-1'];
        yield 'vies' => ['MS_MAX_CONCURRENT_REQ'];
        yield '64 chars' => [str_repeat('A', 64)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonTokenErrorCodes(): iterable
    {
        yield 'space' => ['bad code'];
        yield 'newline' => ["A\nB"];
        yield 'quote' => ['A"B'];
        yield 'apostrophe' => ["A'B"];
        yield 'markup' => ['<script>'];
        yield 'empty' => [''];
        yield '65 chars' => [str_repeat('A', 65)];
        yield 'trailing newline' => ["ABC\n"];
    }

    #[DataProvider('provideTokenLikeErrorCodes')]
    public function testTokenLikeErrorCodeIsAppendedToTheMessage(string $code): void
    {
        self::assertSame(\sprintf('Msg (error code %s)', $code), (new InvalidInput('Msg', $code))->getMessage());
        self::assertSame(\sprintf('Msg (error code %s)', $code), (new ServiceUnavailable('Msg', Source::Vies, $code))->getMessage());
    }

    #[DataProvider('provideNonTokenErrorCodes')]
    public function testNonTokenErrorCodeIsKeptRawButNeverReachesTheMessage(string $code): void
    {
        $invalidInput = new InvalidInput('Msg', $code);
        $unavailable = new ServiceUnavailable('Msg', Source::Vies, $code);

        self::assertSame('Msg', $invalidInput->getMessage());
        self::assertSame($code, $invalidInput->errorCode);
        self::assertSame('Msg', $unavailable->getMessage());
        self::assertSame($code, $unavailable->errorCode);
    }

    public function testInvalidInputCarriesMessageErrorCodeAndPrevious(): void
    {
        $previous = new \LogicException('cause');

        $exception = new InvalidInput('Bad input', 'VSTUP_NEVALIDNI_FORMAT_ICO', $previous);

        self::assertSame('Bad input (error code VSTUP_NEVALIDNI_FORMAT_ICO)', $exception->getMessage());
        self::assertSame('VSTUP_NEVALIDNI_FORMAT_ICO', $exception->errorCode);
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testInvalidInputErrorCodeAndPreviousAreOptional(): void
    {
        $exception = new InvalidInput('Bad input');

        self::assertNull($exception->errorCode);
        self::assertNull($exception->getPrevious());
    }

    public function testServiceUnavailableCarriesMessageSourceErrorCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('cause');

        $exception = new ServiceUnavailable('Try later', Source::Vies, 'MS_UNAVAILABLE', $previous);

        self::assertSame('Try later (error code MS_UNAVAILABLE)', $exception->getMessage());
        self::assertSame(Source::Vies, $exception->source);
        self::assertSame('MS_UNAVAILABLE', $exception->errorCode);
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testServiceUnavailableErrorCodeAndPreviousAreOptional(): void
    {
        $exception = new ServiceUnavailable('Try later', Source::Ares);

        self::assertSame(Source::Ares, $exception->source);
        self::assertNull($exception->errorCode);
        self::assertNull($exception->getPrevious());
    }

    public function testInvalidResponseCarriesMessageSourceAndPrevious(): void
    {
        $previous = new \JsonException('cause');

        $exception = new InvalidResponse('ARES: missing obchodniJmeno', Source::Ares, $previous);

        self::assertSame('ARES: missing obchodniJmeno', $exception->getMessage());
        self::assertSame(Source::Ares, $exception->source);
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testInvalidResponsePreviousIsOptional(): void
    {
        self::assertNull((new InvalidResponse('x', Source::Isir))->getPrevious());
    }

    public function testExceptionPropertiesAreReadOnly(): void
    {
        self::assertTrue((new \ReflectionProperty(InvalidInput::class, 'errorCode'))->isReadOnly());
        self::assertTrue((new \ReflectionProperty(ServiceUnavailable::class, 'source'))->isReadOnly());
        self::assertTrue((new \ReflectionProperty(ServiceUnavailable::class, 'errorCode'))->isReadOnly());
        self::assertTrue((new \ReflectionProperty(InvalidResponse::class, 'source'))->isReadOnly());
    }

    public function testSourceHasExactlyTheFourDocumentedCasesWithLowercaseValues(): void
    {
        $actual = [];
        foreach ((new \ReflectionEnum(Source::class))->getCases() as $case) {
            $actual[$case->getName()] = $case->getBackingValue();
        }

        self::assertSame(
            ['Ares' => 'ares', 'Adis' => 'adis', 'Vies' => 'vies', 'Isir' => 'isir'],
            $actual,
        );
    }

    /**
     * @return iterable<string, array{string, Source}>
     */
    public static function provideSourceValues(): iterable
    {
        yield 'ares' => ['ares', Source::Ares];
        yield 'adis' => ['adis', Source::Adis];
        yield 'vies' => ['vies', Source::Vies];
        yield 'isir' => ['isir', Source::Isir];
    }

    #[DataProvider('provideSourceValues')]
    public function testSourceIsResolvableFromItsValue(string $value, Source $expected): void
    {
        self::assertSame($expected, Source::from($value));
    }
}
