<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Adis;

/**
 * Kind of subject in the VAT register.
 *
 * The register's NENALEZEN (not found) marker is not a case: such a subject is absent from the result.
 */
enum SubjectType: string
{
    case VatPayer = 'PLATCE_DPH';
    case IdentifiedPerson = 'IDENTIFIKOVANA_OSOBA';
    case VatGroup = 'SKUPINA_DPH';
    case UnreliablePerson = 'NESPOLEHLIVA_OSOBA';
}
