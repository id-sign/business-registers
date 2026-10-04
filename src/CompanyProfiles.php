<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Internal\Identifiers;
use IdSign\BusinessRegisters\Internal\ListElement;

/**
 * Company profiles assembled by CompanyLookup::byCompanyIds(), one per company id, in insertion order.
 *
 * Not a keyed array because PHP turns digit-only string keys into ints. A string lookup id goes
 * through the strict CompanyId::parse(), so "45 317 054", "64581" and "00064581" find the same entry;
 * a CompanyId is taken as it is, so a register id that fails the check digit is found too.
 * A company id is always a CompanyId or a string, never an int.
 *
 * @implements \IteratorAggregate<int, CompanyProfile>
 */
final readonly class CompanyProfiles implements \IteratorAggregate, \Countable
{
    /** @var array<string, CompanyProfile> */
    private array $byId;

    /**
     * Built by CompanyLookup; public so consumers can build collections in their tests.
     *
     * @param list<CompanyProfile> $profiles
     *
     * @throws InvalidInput a profile whose company has no company id, or two profiles with the same one
     */
    public function __construct(array $profiles)
    {
        $byId = [];
        foreach ($profiles as $profile) {
            $id = $profile->company->id ?? throw new InvalidInput(\sprintf('Company "%s" has no company id (IČO) and cannot be stored in CompanyProfiles.', $profile->company->aresId));
            if (isset($byId[$id->value])) {
                throw new InvalidInput(\sprintf('Duplicate company id %s in CompanyProfiles.', $id->value));
            }
            $byId[$id->value] = $profile;
        }

        $this->byId = $byId;
    }

    /**
     * @throws InvalidInput an invalid company id
     */
    public function get(CompanyId|string $id): ?CompanyProfile
    {
        return $this->byId[Identifiers::companyId($id)->value] ?? null;
    }

    /**
     * @throws InvalidInput an invalid company id
     */
    public function has(CompanyId|string $id): bool
    {
        return isset($this->byId[Identifiers::companyId($id)->value]);
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
            $key = Identifiers::companyId(ListElement::idOrString($id, $index, CompanyId::class));
            if (!isset($this->byId[$key->value])) {
                $missing[$key->value] ??= $key;
            }
        }

        return array_values($missing);
    }

    /**
     * @return list<CompanyProfile>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    /**
     * @return \ArrayIterator<int, CompanyProfile>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    public function count(): int
    {
        return \count($this->byId);
    }
}
