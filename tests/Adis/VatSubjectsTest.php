<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Adis;

use IdSign\BusinessRegisters\Adis\SubjectType;
use IdSign\BusinessRegisters\Adis\VatSubject;
use IdSign\BusinessRegisters\Adis\VatSubjects;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\VatId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VatSubjects::class)]
final class VatSubjectsTest extends TestCase
{
    private static function subject(string $vatId, ?string $name = null): VatSubject
    {
        return new VatSubject(
            vatId: VatId::parse($vatId),
            type: SubjectType::VatPayer,
            unreliable: false,
            unreliableSince: null,
            taxOfficeCode: '013',
            name: $name,
            address: null,
            bankAccounts: [],
            checkedAt: new \DateTimeImmutable('2026-10-03'),
        );
    }

    private static function subjects(): VatSubjects
    {
        return new VatSubjects([self::subject('CZ45274649', 'ČEZ'), self::subject('CZ00121100', 'LIDRU')]);
    }

    public function testCollectionBuiltFromAListCountsItsSubjectsAndKeepsInsertionOrder(): void
    {
        $first = self::subject('CZ45274649');
        $second = self::subject('CZ00121100');

        $subjects = new VatSubjects([$first, $second]);

        self::assertCount(2, $subjects);
        self::assertSame([$first, $second], $subjects->all());
    }

    public function testIterationYieldsTheSubjectsAsAListInInsertionOrder(): void
    {
        $first = self::subject('CZ45274649');
        $second = self::subject('CZ00121100');

        $subjects = new VatSubjects([$first, $second]);

        self::assertSame([$first, $second], iterator_to_array($subjects));
    }

    public function testEmptyCollectionHasNoSubjectsAndReportsEveryRequestedVatIdAsMissing(): void
    {
        $subjects = new VatSubjects([]);

        self::assertCount(0, $subjects);
        self::assertSame([], $subjects->all());
        self::assertEquals([VatId::parse('CZ45274649')], $subjects->missing(['CZ45274649']));
    }

    public function testTwoSubjectsWithTheSameVatIdAreRejected(): void
    {
        $this->expectException(InvalidInput::class);

        new VatSubjects([self::subject('CZ45274649'), self::subject('CZ45274649', 'Other')]);
    }

    /**
     * @return iterable<string, array{VatId|string}>
     */
    public static function provideVatIdForms(): iterable
    {
        yield 'canonical' => ['CZ45274649'];
        yield 'without country code' => ['45274649'];
        yield 'lower case with spaces' => ['cz 4527 4649'];
        yield 'VatId' => [VatId::parse('CZ45274649')];
    }

    #[DataProvider('provideVatIdForms')]
    public function testGetAndHasFindTheSubjectWhateverFormTheVatIdIsGivenIn(VatId|string $vatId): void
    {
        $subjects = self::subjects();

        self::assertTrue($subjects->has($vatId));
        self::assertSame('ČEZ', $subjects->get($vatId)?->name);
    }

    /**
     * @return iterable<string, array{VatId|string}>
     */
    public static function provideUnknownVatIds(): iterable
    {
        yield 'string' => ['CZ11111111'];
        yield 'string without country code' => ['11111111'];
        yield 'VatId' => [VatId::parse('CZ11111111')];
    }

    #[DataProvider('provideUnknownVatIds')]
    public function testUnknownVatIdIsNullAndNotPresent(VatId|string $vatId): void
    {
        $subjects = self::subjects();

        self::assertNull($subjects->get($vatId));
        self::assertFalse($subjects->has($vatId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectedVatIds(): iterable
    {
        yield 'foreign country' => ['DE811115368'];
        yield 'too short' => ['CZ123'];
        yield 'empty' => [''];
    }

    #[DataProvider('provideRejectedVatIds')]
    public function testGetRejectsAnUnusableVatIdInsteadOfReportingItMissing(string $vatId): void
    {
        $this->expectException(InvalidInput::class);

        self::subjects()->get($vatId);
    }

    #[DataProvider('provideRejectedVatIds')]
    public function testHasRejectsAnUnusableVatIdInsteadOfReportingItMissing(string $vatId): void
    {
        $this->expectException(InvalidInput::class);

        self::subjects()->has($vatId);
    }

    #[DataProvider('provideRejectedVatIds')]
    public function testMissingRejectsAnUnusableVatIdInsteadOfReportingItMissing(string $vatId): void
    {
        $this->expectException(InvalidInput::class);

        self::subjects()->missing(['CZ45274649', $vatId]);
    }

    public function testLookupRejectsAForeignVatIdGivenAsAnObjectToo(): void
    {
        $subjects = self::subjects();
        $foreign = VatId::parse('DE811115368');

        $rejected = 0;
        foreach ([
            static fn () => $subjects->get($foreign),
            static fn () => $subjects->has($foreign),
            static fn () => $subjects->missing([$foreign]),
        ] as $lookup) {
            try {
                $lookup();
            } catch (InvalidInput) {
                ++$rejected;
            }
        }

        self::assertSame(3, $rejected);
    }

    public function testMissingReturnsNormalisedDistinctVatIdsInRequestOrder(): void
    {
        $missing = self::subjects()->missing(['CZ45274649', '11111111', 'CZ11111111', 'cz 22222222', '00121100']);

        self::assertEquals([VatId::parse('CZ11111111'), VatId::parse('CZ22222222')], $missing);
    }

    public function testMissingOfNoVatIdsIsEmpty(): void
    {
        self::assertSame([], self::subjects()->missing([]));
    }

    public function testMissingIsEmptyWhenEveryRequestedSubjectIsPresent(): void
    {
        self::assertSame([], self::subjects()->missing(['45274649', 'CZ 00121100']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideNonStringVatIds(): iterable
    {
        yield 'int' => [45274649];
        yield 'float' => [45274649.0];
        yield 'null' => [null];
        yield 'bool' => [true];
        yield 'array' => [['45274649']];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('provideNonStringVatIds')]
    public function testMissingRejectsANonStringElementNamingItsIndexAndTheExpectedType(mixed $element): void
    {
        $requested = ['CZ45274649', '00121100', $element];

        try {
            // reflection: PHPStan rejects a non-string element in the typed list; the runtime check is what non-analysed callers hit
            $subjects = self::subjects();
            new \ReflectionMethod($subjects, 'missing')->invoke($subjects, $requested);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('2', $e->getMessage());
            self::assertStringContainsString('string', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testMissingAcceptsAStringKeyedArrayOfValidVatIds(): void
    {
        $subjects = self::subjects();

        // reflection: PHPStan requires a list; non-analysed callers may pass string keys
        $missing = new \ReflectionMethod($subjects, 'missing')->invoke($subjects, ['a' => 'CZ45274649', 'b' => 'CZ11111111']);

        self::assertEquals([VatId::parse('CZ11111111')], $missing);
    }

    public function testMissingRejectsAWrongTypedElementUnderAStringKeyNamingThatKey(): void
    {
        $subjects = self::subjects();

        try {
            // reflection: PHPStan requires a list; non-analysed callers may pass string keys
            new \ReflectionMethod($subjects, 'missing')->invoke($subjects, ['a' => 'CZ45274649', 'name' => 45274649]);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('name', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }
}
