<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests;

use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\CompanyProfile;
use IdSign\BusinessRegisters\CompanyProfiles;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Tests\Double\CompanyFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompanyProfiles::class)]
final class CompanyProfilesTest extends TestCase
{
    private static function profile(Company $company): CompanyProfile
    {
        return new CompanyProfile($company, null, null, null, [], []);
    }

    private static function bank(): CompanyProfiles
    {
        return new CompanyProfiles([
            self::profile(CompanyFactory::create(id: '45317054', name: 'Komerční banka, a.s.')),
            self::profile(CompanyFactory::create(id: '00064581', name: 'Hlavní město Praha')),
        ]);
    }

    public function testCollectionBuiltFromAListCountsItsProfilesAndKeepsConstructorOrder(): void
    {
        $first = self::profile(CompanyFactory::create(id: '45317054'));
        $second = self::profile(CompanyFactory::create(id: '00064581'));

        $profiles = new CompanyProfiles([$first, $second]);

        self::assertCount(2, $profiles);
        self::assertSame([$first, $second], $profiles->all());
    }

    public function testIterationYieldsTheProfilesAsAListInConstructorOrder(): void
    {
        $first = self::profile(CompanyFactory::create(id: '45317054'));
        $second = self::profile(CompanyFactory::create(id: '00064581'));

        $profiles = new CompanyProfiles([$first, $second]);

        self::assertSame([$first, $second], iterator_to_array($profiles));
    }

    public function testEmptyCollectionHasNoProfilesAndReportsEveryRequestedIdAsMissing(): void
    {
        $profiles = new CompanyProfiles([]);

        self::assertCount(0, $profiles);
        self::assertSame([], $profiles->all());
        self::assertEquals([CompanyId::parse('45317054')], $profiles->missing(['45317054']));
    }

    public function testCollectionExposesNeitherAnArrayConversionNorArrayAccess(): void
    {
        $class = new \ReflectionClass(CompanyProfiles::class);

        self::assertFalse($class->hasMethod('toArray'));
        self::assertFalse($class->implementsInterface(\ArrayAccess::class));
    }

    public function testProfileOfACompanyWithoutCompanyIdIsRejectedNamingItsAresIdButNotItsName(): void
    {
        $subject = self::profile(CompanyFactory::create(id: null, name: 'SENTINEL-NAME', aresId: 'ARES_00369838'));

        try {
            new CompanyProfiles([$subject]);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('ARES_00369838', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL-NAME', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }

    public function testTwoProfilesOfTheSameCompanyIdAreRejected(): void
    {
        $this->expectException(InvalidInput::class);

        new CompanyProfiles([
            self::profile(CompanyFactory::create(id: '45317054')),
            self::profile(CompanyFactory::create(id: '45317054', name: 'Other')),
        ]);
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
    public function testGetAndHasFindTheProfileWhateverFormTheIdIsGivenIn(CompanyId|string $id, string $expectedId): void
    {
        $profiles = self::bank();

        self::assertTrue($profiles->has($id));
        self::assertSame($expectedId, $profiles->get($id)?->company->id?->value);
    }

    public function testGetAndHasFindAProfileWhoseCompanyIdFailsTheCheckDigitByItsCompanyId(): void
    {
        $profiles = new CompanyProfiles([
            self::profile(CompanyFactory::create(id: CompanyId::fromRegister('00123562'), name: 'Zemědělské družstvo')),
        ]);
        $id = CompanyId::fromRegister('00123562');

        self::assertTrue($profiles->has($id));
        self::assertSame('Zemědělské družstvo', $profiles->get($id)?->company->name);
        self::assertSame([], $profiles->missing([$id]));
    }

    public function testStringLookupOfAnIdThatFailsTheCheckDigitStaysStrict(): void
    {
        $profiles = new CompanyProfiles([
            self::profile(CompanyFactory::create(id: CompanyId::fromRegister('00123562'))),
        ]);

        $lookups = [
            static fn () => $profiles->get('00123562'),
            static fn () => $profiles->has('00123562'),
            static fn () => $profiles->missing(['00123562']),
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
        $profiles = self::bank();

        self::assertNull($profiles->get($id));
        self::assertFalse($profiles->has($id));
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
        $profiles = self::bank();

        $missing = $profiles->missing(['45317054', '04957423', '04957423', '4957423', '28255933', ' 64581']);

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
        yield 'null' => [null];
        yield 'array' => [['45317054']];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('provideNonStringIds')]
    public function testMissingRejectsANonStringElementNamingItsIndexAndTheExpectedType(mixed $element): void
    {
        $requested = ['45317054', '64581', $element];

        try {
            // reflection: PHPStan rejects a non-string element in the typed list; the runtime check is what non-analysed callers hit
            $profiles = self::bank();
            new \ReflectionMethod($profiles, 'missing')->invoke($profiles, $requested);
        } catch (InvalidInput $e) {
            self::assertStringContainsString('2', $e->getMessage());
            self::assertStringContainsString('string', $e->getMessage());

            return;
        }

        self::fail('Expected InvalidInput was not thrown.');
    }
}
