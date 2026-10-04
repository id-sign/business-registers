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
     * With trader details VIES compares each given field with the register and answers per field in the *Match
     * properties of the result; many member states, the Czech Republic and Ireland among them, always answer
     * NOT_PROCESSED.
     *
     * @param VatId|string|null $requester own VAT id of the asking party; only with it VIES issues a consultation number
     * @param ?TraderDetails    $trader    trader data to compare; null and blank fields are not sent
     *
     * @throws InvalidInput       an unparsable VAT id or requester, trader details that are not valid UTF-8 (before any
     *                            request), or a request VIES rejected (errorCode carries its code)
     * @throws ServiceUnavailable VIES or the member state could not answer (errorCode carries the VIES code, if any)
     * @throws InvalidResponse
     */
    public function check(VatId|string $vatId, VatId|string|null $requester = null, ?TraderDetails $trader = null): ViesResult;
}
