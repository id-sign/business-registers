<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Isir;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;

/**
 * The insolvency register ISIR, queried by company id.
 */
interface InsolvencyRegister
{
    /**
     * Every proceeding the register currently lists for the subject, ended ones included (§ 425 of Act 182/2006 Sb.
     * removes them 5 years after the end); an empty collection when the subject is not on the list of debtors.
     *
     * @throws InvalidInput       invalid company id (before any request)
     * @throws ServiceUnavailable transport, timeout, HTTP ≠ 200, SOAP Fault, service error codes WS4/SQL1/SERVER1
     * @throws InvalidResponse    unreadable or truncated answer, unknown error code
     */
    public function find(CompanyId|string $id): InsolvencyProceedings;
}
