<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Adis;

use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\VatId;

/**
 * VAT register of the Czech financial administration (ADIS): VAT status, unreliable payers and published bank accounts.
 * Only Czech VAT ids are accepted; a string without a country code is read as Czech.
 */
interface VatRegister
{
    /**
     * Null when the register does not hold the VAT id.
     *
     * @throws InvalidInput       an invalid or non-Czech VAT id
     * @throws ServiceUnavailable
     * @throws InvalidResponse
     */
    public function find(VatId|string $vatId): ?VatSubject;

    /**
     * Duplicates are removed and the VAT ids are sent in batches of 100; an empty list makes no request. Batches
     * may be sent concurrently, up to the client's `maxConcurrency`; the result order is the sequential order.
     * Returns a collection rather than an array keyed by VAT id, so that has()/get()/missing() normalise
     * any VAT id form. A VAT id is always a string, never an int.
     *
     * @param list<VatId|string> $vatIds
     *
     * @return VatSubjects found subjects; VAT ids not found are absent
     *
     * @throws InvalidInput       an invalid or non-Czech VAT id, or an element that is neither a VatId nor a string
     *                            (before any request)
     * @throws ServiceUnavailable
     * @throws InvalidResponse
     */
    public function findMany(array $vatIds): VatSubjects;

    /**
     * The whole list of unreliable VAT payers. It holds only some unreliable persons and not reliably the
     * identified persons the register marks unreliable, and it does not carry the subject type. To screen for
     * unreliable persons use find() / findMany() or the facade.
     *
     * @return list<UnreliablePayer>
     *
     * @throws ServiceUnavailable
     * @throws InvalidResponse
     */
    public function unreliablePayers(): array;
}
