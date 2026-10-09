<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Isir;

use IdSign\BusinessRegisters\CompanyId;
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;

/**
 * The insolvency register ISIR, queried by company id, birth number or name and birth date.
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

    /**
     * Every proceeding the register lists for the natural person with this birth number, as find().
     *
     * @param string $birthNumber six digits, an optional slash and three or four digits; surrounding whitespace is
     *                            ignored
     *
     * @throws InvalidInput       blank or malformed birth number (before any request)
     * @throws ServiceUnavailable transport, timeout, HTTP ≠ 200, SOAP Fault, service error codes WS4/SQL1/SERVER1
     * @throws InvalidResponse    unreadable or truncated answer, unknown error code, a weaker or missing match kind
     */
    public function findByBirthNumber(string $birthNumber): InsolvencyProceedings;

    /**
     * Every proceeding the register lists for the natural person matching surname, first name and birth date, as
     * find(); names match exactly, ignoring case, so a name spelled otherwise than in the register gives an empty
     * collection. White space around the names is ignored.
     *
     * @throws InvalidInput       surname or first name without a letter, not valid UTF-8, with a character XML 1.0
     *                            forbids or with decomposed diacritics (NFD), a birth year outside 1–9999 (before any
     *                            request)
     * @throws ServiceUnavailable transport, timeout, HTTP ≠ 200, SOAP Fault, service error codes WS4/SQL1/SERVER1
     * @throws InvalidResponse    unreadable or truncated answer, unknown error code, a weaker or missing match kind
     */
    public function findByNameAndBirthDate(string $surname, string $firstName, \DateTimeImmutable $bornOn): InsolvencyProceedings;
}
