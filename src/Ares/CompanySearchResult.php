<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares;

/**
 * One page of an ARES search.
 */
final readonly class CompanySearchResult
{
    /**
     * @param int           $total     number of all matching subjects, not only those on this page
     * @param list<Company> $companies
     */
    public function __construct(
        public int $total,
        public array $companies,
    ) {
    }
}
