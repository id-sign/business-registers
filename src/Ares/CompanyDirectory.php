<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;

/**
 * Company identity data from ARES.
 */
interface CompanyDirectory
{
    /**
     * Null when ARES does not hold the subject; deleted subjects are not returned either.
     *
     * @throws InvalidInput       invalid company id, or ARES rejected the request
     * @throws ServiceUnavailable
     * @throws InvalidResponse
     */
    public function find(CompanyId|string $id): ?Company;

    /**
     * Duplicates are removed and the ids are sent in batches of 100; an empty list makes no request.
     * Returns a collection rather than an array keyed by company id, because PHP casts digit-only keys
     * to int; its has()/get()/missing() normalise any id form. A company id is always a string, never an int.
     *
     * @param list<CompanyId|string> $ids
     *
     * @return Companies found companies; ids not found are absent
     *
     * @throws InvalidInput       an invalid company id or an element that is neither a CompanyId nor a string
     *                            (before any request), or ARES rejected the request
     * @throws ServiceUnavailable
     * @throws InvalidResponse
     */
    public function findMany(array $ids): Companies;

    /**
     * @throws InvalidInput       no criterion filled or limit outside 1–1 000 (before any request), or ARES
     *                            rejected the request, e.g. VYSTUP_PRILIS_MNOHO_VYSLEDKU above 1 000 results
     * @throws ServiceUnavailable
     * @throws InvalidResponse
     */
    public function search(CompanySearch $query): CompanySearchResult;
}
