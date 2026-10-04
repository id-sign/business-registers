<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Internal\ListElement;

/**
 * Companies found in ARES, one per company id, in insertion order.
 *
 * Not a keyed array because PHP turns digit-only string keys into ints; every lookup id goes
 * through CompanyId::parse(), so "45 317 054", "64581" and "00064581" find the same entry.
 * A company id is always a CompanyId or a string, never an int.
 *
 * @implements \IteratorAggregate<int, Company>
 */
final readonly class Companies implements \IteratorAggregate, \Countable
{
    /** @var array<string, Company> */
    private array $byId;

    /**
     * @param list<Company> $companies
     *
     * @throws InvalidInput a company without a company id, or two companies with the same one
     */
    public function __construct(array $companies)
    {
        $byId = [];
        foreach ($companies as $company) {
            $id = $company->id ?? throw new InvalidInput(\sprintf('Company "%s" has no company id (IČO) and cannot be stored in Companies.', $company->aresId));
            if (isset($byId[$id->value])) {
                throw new InvalidInput(\sprintf('Duplicate company id %s in Companies.', $id->value));
            }
            $byId[$id->value] = $company;
        }

        $this->byId = $byId;
    }

    /**
     * @throws InvalidInput an invalid company id
     */
    public function get(CompanyId|string $id): ?Company
    {
        return $this->byId[self::key($id)->value] ?? null;
    }

    /**
     * @throws InvalidInput an invalid company id
     */
    public function has(CompanyId|string $id): bool
    {
        return isset($this->byId[self::key($id)->value]);
    }

    /**
     * Requested ids not in the collection, normalised and de-duplicated, in request order.
     *
     * @param list<CompanyId|string> $requested
     *
     * @return list<CompanyId>
     *
     * @throws InvalidInput an invalid company id, or an element that is neither a CompanyId nor a string
     */
    public function missing(array $requested): array
    {
        $missing = [];
        foreach ($requested as $index => $id) {
            $key = self::key(ListElement::idOrString($id, $index, CompanyId::class));
            if (!isset($this->byId[$key->value])) {
                $missing[$key->value] ??= $key;
            }
        }

        return array_values($missing);
    }

    /**
     * @return list<Company>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    /**
     * @return \ArrayIterator<int, Company>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    public function count(): int
    {
        return \count($this->byId);
    }

    /**
     * @throws InvalidInput
     */
    private static function key(CompanyId|string $id): CompanyId
    {
        return $id instanceof CompanyId ? $id : CompanyId::parse($id);
    }
}
