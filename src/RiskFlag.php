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
     * The name ends with "v likvidaci" (case-insensitive, any whitespace between the words, trailing quotes, dots or spaces ignored).
     * The phrase before the legal form ("… v likvidaci, s.r.o.") is not recognised.
     */
    case InLiquidation;

    /** ARES Insolvency is Active: the subject has a record in the insolvency register, possibly a closed one. */
    case InsolvencyRecord;

    /** The VAT register marks the subject as an unreliable payer. */
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
