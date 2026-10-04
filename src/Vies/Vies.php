<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Vies;

use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;
use IdSign\BusinessRegisters\VatId;

/**
 * EU VAT id validation (VIES). A string must start with its country code.
 */
interface Vies
{
    /**
     * An invalid VAT id is a result with valid false, never an exception.
     *
     * @param VatId|string|null $requester own VAT id of the asking party; only with it VIES issues a consultation number
     *
     * @throws InvalidInput       an unparsable VAT id or requester, or a request VIES rejected (errorCode carries its code)
     * @throws ServiceUnavailable VIES or the member state could not answer (errorCode carries the VIES code, if any)
     * @throws InvalidResponse
     */
    public function check(VatId|string $vatId, VatId|string|null $requester = null): ViesResult;
}
