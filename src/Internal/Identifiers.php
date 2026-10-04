<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Internal;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\VatId;

/**
 * Turns a caller's company id or VAT id into the value object a client or collection works with.
 *
 * A string is parsed strictly, so a company id string must pass the check digit; an object is taken
 * as it is, so a register id built with CompanyId::fromRegister() that fails the check digit still
 * gets through.
 *
 * @internal
 */
final class Identifiers
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidInput an invalid company id string
     */
    public static function companyId(CompanyId|string $id): CompanyId
    {
        return $id instanceof CompanyId ? $id : CompanyId::parse($id);
    }

    /**
     * Reads a string with CZ as the default country and rejects a non-Czech VAT id in either form.
     *
     * @throws InvalidInput an invalid or non-Czech VAT id
     */
    public static function czechVatId(VatId|string $vatId): VatId
    {
        $vatId = $vatId instanceof VatId ? $vatId : VatId::parse($vatId, 'CZ');
        if (!$vatId->isCzech()) {
            throw new InvalidInput(\sprintf('VAT id %s is not Czech; the VAT register holds Czech VAT ids only.', $vatId));
        }

        return $vatId;
    }
}
