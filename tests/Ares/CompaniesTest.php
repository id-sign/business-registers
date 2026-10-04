<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Ares;

use IdSign\BusinessRegisters\Ares\Companies;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Tests\Double\CompanyFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Companies::class)]
final class CompaniesTest extends TestCase
{
    private static function bank(): Companies
    {
        return new Companies([
            CompanyFactory::create(id: '45317054', name: 'Komerční banka, a.s.'),
            CompanyFactory::create(id: '00064581', name: 'Hlavní město Praha'),
        ]);
    }

    public function testCollectionBuiltFromAListCountsItsCompaniesAndKeepsInsertionOrder(): void
    {
        $first = CompanyFactory::create(id: '45317054');
        $second = CompanyFactory::create(id: '00064581');

        $companies = new Companies([$first, $second]);

        self::assertCount(2, $companies);
        self::assertSame([$first, $second], $companies->all());
    }

    public function testIterationYieldsTheCompaniesAsAListInInsertionOrder(): void
    {
        $first = CompanyFactory::create(id: '45317054');
        $second = CompanyFactory::create(id: '00064581');

        $companies = new Companies([$first, $second]);

        self::assertSame([$first, $second], iterator_to_array($companies));
    }

    public function testEmptyCollectionHasNoCompaniesAndReportsEveryRequestedIdAsMissing(): void
    {
        $companies = new Companies([]);

        self::assertCount(0, $companies);
        self::assertSame([], $companies->all());
        self::assertEquals([CompanyId::parse('45317054')], $companies->missing(['45317054']));
    }

    public function testCompanyWithoutCompanyIdIsRejectedNamingItsAresIdButNotItsName(): void
    {
        $subject = CompanyFactory::create(id: null, name: 'SENTINEL-NAME', aresId: 'ARES_00369838');

        try {
            new Companies([$subject]);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('ARES_00369838', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL-NAME', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testTwoCompaniesWithTheSameCompanyIdAreRejected(): void
    {
        $this->expectException(InvalidInput::class);

        new Companies([CompanyFactory::create(id: '45317054'), CompanyFactory::create(id: '45317054', name: 'Other')]);
    }

    /**
     * @return iterable<string, array{CompanyId|string, string}>
     */
    public static function provideIdForms(): iterable
    {
        yield 'eight digit string' => ['45317054', '45317054'];
        yield 'string with spaces' => ['45 317 054', '45317054'];
        yield 'CompanyId' => [CompanyId::parse('45317054'), '45317054'];
        yield 'short string' => ['64581', '00064581'];
        yield 'zero padded string' => ['00064581', '00064581'];
    }

    #[DataProvider('provideIdForms')]
    public function testGetAndHasFindTheCompanyWhateverFormTheIdIsGivenIn(CompanyId|string $id, string $expectedId): void
    {
        $companies = self::bank();

        self::assertTrue($companies->has($id));
        self::assertSame($expectedId, $companies->get($id)?->id?->value);
    }

    private static function withCheckDigitMismatch(): Companies
    {
        return new Companies([
            CompanyFactory::create(id: CompanyId::fromRegister('00123562'), name: 'Zemědělské družstvo'),
            CompanyFactory::create(id: '45317054', name: 'Komerční banka, a.s.'),
        ]);
    }

    public function testGetAndHasFindACompanyWhoseIdFailsTheCheckDigitByItsCompanyId(): void
    {
        $companies = self::withCheckDigitMismatch();
        $id = CompanyId::fromRegister('00123562');

        self::assertTrue($companies->has($id));
        self::assertSame('Zemědělské družstvo', $companies->get($id)?->name);
    }

    public function testMissingTakesACompanyIdThatFailsTheCheckDigitAsItIs(): void
    {
        $companies = self::withCheckDigitMismatch();
        $unknown = CompanyId::fromRegister('29340042');

        self::assertSame([], $companies->missing([CompanyId::fromRegister('123562')]));
        self::assertEquals([$unknown], $companies->missing([CompanyId::fromRegister('00123562'), $unknown]));
    }

    public function testStringLookupOfAnIdThatFailsTheCheckDigitStaysStrict(): void
    {
        $companies = self::withCheckDigitMismatch();

        $lookups = [
            static fn () => $companies->get('00123562'),
            static fn () => $companies->has('00123562'),
            static fn () => $companies->missing(['00123562']),
        ];

        $rejected = 0;
        foreach ($lookups as $lookup) {
            try {
                $lookup();
            } catch (InvalidInput) {
                ++$rejected;
            }
        }

        self::assertSame(\count($lookups), $rejected);
    }

    /**
     * @return iterable<string, array{CompanyId|string}>
     */
    public static function provideUnknownIds(): iterable
    {
        yield 'string' => ['04957423'];
        yield 'CompanyId' => [CompanyId::parse('04957423')];
    }

    #[DataProvider('provideUnknownIds')]
    public function testUnknownIdIsNullAndNotPresent(CompanyId|string $id): void
    {
        $companies = self::bank();

        self::assertNull($companies->get($id));
        self::assertFalse($companies->has($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidIds(): iterable
    {
        yield 'wrong check digit' => ['12345678'];
        yield 'letters' => ['abc'];
        yield 'empty' => [''];
    }

    #[DataProvider('provideInvalidIds')]
    public function testGetRejectsAnInvalidIdInsteadOfReportingItMissing(string $id): void
    {
        $this->expectException(InvalidInput::class);

        self::bank()->get($id);
    }

    #[DataProvider('provideInvalidIds')]
    public function testHasRejectsAnInvalidIdInsteadOfReportingItMissing(string $id): void
    {
        $this->expectException(InvalidInput::class);

        self::bank()->has($id);
    }

    #[DataProvider('provideInvalidIds')]
    public function testMissingRejectsAnInvalidIdInsteadOfReportingItMissing(string $id): void
    {
        $this->expectException(InvalidInput::class);

        self::bank()->missing(['45317054', $id]);
    }

    public function testMissingReturnsNormalisedDistinctIdsInRequestOrder(): void
    {
        $companies = self::bank();

        $missing = $companies->missing(['45317054', '04957423', '04957423', '4957423', '28255933', ' 64581']);

        self::assertEquals([CompanyId::parse('04957423'), CompanyId::parse('28255933')], $missing);
    }

    public function testMissingOfNoIdsIsEmpty(): void
    {
        self::assertSame([], self::bank()->missing([]));
    }

    public function testMissingIsEmptyWhenEveryRequestedCompanyIsPresent(): void
    {
        self::assertSame([], self::bank()->missing(['64581', '45 317 054']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideNonStringIds(): iterable
    {
        yield 'int' => [45317054];
        yield 'float' => [45317054.0];
        yield 'null' => [null];
        yield 'bool' => [true];
        yield 'array' => [['45317054']];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('provideNonStringIds')]
    public function testMissingRejectsANonStringElementNamingItsIndexAndTheExpectedType(mixed $element): void
    {
        $requested = ['45317054', '64581', $element];

        try {
            // reflection: PHPStan rejects a non-string element in the typed list; the runtime check is what non-analysed callers hit
            $companies = self::bank();
            new \ReflectionMethod($companies, 'missing')->invoke($companies, $requested);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('2', $e->getMessage());
            self::assertStringContainsString('string', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testMissingAcceptsAStringKeyedArrayOfValidIds(): void
    {
        $companies = self::bank();
        $requested = ['a' => '45317054', 'b' => '04957423'];

        // reflection: PHPStan requires a list; non-analysed callers may pass string keys
        $missing = new \ReflectionMethod($companies, 'missing')->invoke($companies, $requested);

        self::assertEquals([CompanyId::parse('04957423')], $missing);
    }

    public function testMissingRejectsAWrongTypedElementUnderAStringKeyNamingThatKey(): void
    {
        $companies = self::bank();

        try {
            // reflection: PHPStan requires a list; non-analysed callers may pass string keys
            new \ReflectionMethod($companies, 'missing')->invoke($companies, ['a' => '45317054', 'name' => 45317054]);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('name', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }
}
