<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

/**
 * Risk signal computed by CompanyProfile::flags() from ARES and from the sections whose status is Ok.
 */
enum RiskFlag
{
    /**
     * ARES records a dissolution date that is not after today (midnight Europe/Prague); see Company::isDissolved().
     * A future date is a scheduled dissolution and raises nothing.
     */
    case Dissolved;

    /**
     * The name contains the standalone phrase "v likvidaci" anywhere (case-insensitive, any whitespace between the words),
     * bounded by the start or end, whitespace, quotes (also ´ and ` that ARES writes for quotes), a comma, a dot,
     * parentheses, a slash or a dash.
     */
    case InLiquidation;

    /** ARES Insolvency is Active: the subject has a record in the insolvency register, possibly a closed one. */
    case InsolvencyRecord;

    /** The VAT register marks a VAT payer or VAT group as unreliable (nespolehlivyPlatce): an unreliable VAT payer. */
    case UnreliableVatPayer;

    /**
     * The subject is an unreliable person under §106aa of the VAT Act: the VAT register keeps it as an unreliable person,
     * or marks a non-payer identified person as unreliable (nespolehlivyPlatce).
     */
    case UnreliablePerson;

    /**
     * ARES Vat is Dissolved or Historical, the subject is not in an active VAT group, and an Ok VAT section does not say
     * the subject is a VAT payer (ADIS decides; ARES can lag behind it).
     */
    case VatRegistrationEnded;

    /** A VAT payer or VAT group without any active published bank account. */
    case NoPublishedBankAccount;

    /** VIES answered that the VAT id is not valid. */
    case ViesInvalid;
}
