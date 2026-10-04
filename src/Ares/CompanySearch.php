<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares;

/**
 * Criteria of an ARES search; at least one criterion must be filled, the limit must be 1–1 000 and the offset 0 or
 * greater.
 */
final readonly class CompanySearch
{
    /**
     * @param ?string      $name             obchodniJmeno
     * @param ?string      $address          sidlo.textovaAdresa
     * @param ?int         $municipalityCode sidlo.kodObce
     * @param list<string> $legalFormCodes   pravniForma
     * @param list<string> $naceCodes        czNace
     * @param list<string> $taxOfficeCodes   financniUrad
     * @param int          $limit            pocet
     * @param int          $offset           start (0 or greater)
     * @param list<string> $orderBy          razeni, e.g. ['obchodniJmeno'] or ['-ico']
     */
    public function __construct(
        public ?string $name = null,
        public ?string $address = null,
        public ?int $municipalityCode = null,
        public array $legalFormCodes = [],
        public array $naceCodes = [],
        public array $taxOfficeCodes = [],
        public int $limit = 20,
        public int $offset = 0,
        public array $orderBy = [],
    ) {
    }
}
