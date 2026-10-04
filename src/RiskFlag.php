<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

/**
 * Risk signal computed by CompanyProfile::flags() from ARES and from the sections whose status is Ok.
 */
enum RiskFlag
{
    /** ARES records a dissolution date. */
    case Dissolved;

    /**
     * The name contains the standalone phrase "v likvidaci" anywhere (case-insensitive, any whitespace between the words),
     * bounded by the start or end, whitespace, quotes (also ´ and ` that ARES writes for quotes), a comma, a dot,
     * parentheses, a slash or a dash.
     */
    case InLiquidation;

    /** ARES Insolvency is Active: the subject has a record in the insolvency register, possibly a closed one. */
    case InsolvencyRecord;

    /** The VAT register marks a VAT payer or VAT group as unreliable; an unreliable person gets UnreliablePerson only. */
    case UnreliableVatPayer;

    /** The VAT register keeps the subject as an unreliable person. */
    case UnreliablePerson;

    /** ARES Vat is Dissolved or Historical and the subject is not in an active VAT group. */
    case VatRegistrationEnded;

    /** A VAT payer or VAT group without any active published bank account. */
    case NoPublishedBankAccount;

    /** VIES answered that the VAT id is not valid. */
    case ViesInvalid;
}
