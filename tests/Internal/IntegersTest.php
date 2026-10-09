<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Internal;

use IdSign\BusinessRegisters\Internal\Integers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Integers::class)]
final class IntegersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideDigits(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'plain' => ['12575', 12575];
        yield 'leading zeros' => ['0095', 95];
        yield 'largest integer' => [(string) \PHP_INT_MAX, \PHP_INT_MAX];
    }

    #[DataProvider('provideDigits')]
    public function testDigitsAreReadAsAnInteger(string $text, int $expected): void
    {
        self::assertSame($expected, Integers::fromDigits($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNotDigits(): iterable
    {
        yield 'empty' => [''];
        yield 'sign' => ['-5'];
        yield 'plus sign' => ['+5'];
        yield 'decimal' => ['1.5'];
        yield 'letters' => ['12a'];
        yield 'inner space' => ['1 2'];
        yield 'beyond the largest integer' => ['9223372036854775808'];
    }

    #[DataProvider('provideNotDigits')]
    public function testAnythingButDigitsWithinRangeIsNull(string $text): void
    {
        self::assertNull(Integers::fromDigits($text));
    }
}
