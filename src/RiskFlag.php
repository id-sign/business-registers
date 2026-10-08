<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

/**
 * Risk signal computed by CompanyProfile::flags() from ARES and from the sections whose status is Ok.
 */
enum RiskFlag
{
    /**
     * ARES datumZaniku (the day the subject ceased to exist or its registration ended — zánik, NOZ § 185: deletion from
     * the register; for a natural person the end of the recorded authorisation) is not after today (midnight
     * Europe/Prague); see Company::isDissolved(). It is not the dissolution (zrušení, NOZ § 168): a dissolved company
     * in liquidation stays active here, see InLiquidation. ARES carries a future datumZaniku for some active subjects;
     * the flag is raised only once the date has come.
     */
    case Dissolved;

    /**
     * The name contains the standalone phrase "v likvidaci" anywhere (case-insensitive, any whitespace between the words),
     * bounded by the start or end, whitespace, quotes (also ´ and ` that ARES writes for quotes), a comma, a dot,
     * parentheses, a slash or a dash.
     *
     * The suffix is mandatory for every legal person in liquidation (NOZ § 187 odst. 2). The flag never applies to a
     * natural person, a foreign person or branch (its name follows foreign law) or a legal person dissolved without
     * liquidation (NOZ § 169 odst. 1, § 173 odst. 2); ARES does not carry the public register's entry of the entry
     * into liquidation.
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
