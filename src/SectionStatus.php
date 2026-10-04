<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

/**
 * Outcome of one section of a company profile.
 *
 * The case values are part of the JSON form of a profile; do not change them.
 */
enum SectionStatus: string
{
    /** The section was not passed to CompanyLookup::byCompanyId(). */
    case NotRequested = 'not_requested';

    /** The source answered; the section property holds its answer. */
    case Ok = 'ok';

    /** The source answered that it does not hold the subject. */
    case NotFound = 'not_found';

    /** The section does not apply to the subject, e.g. a VAT section for a subject without a VAT id. */
    case NotApplicable = 'not_applicable';

    /** The source could not answer; CompanyProfile::error() holds the exception. The answer is unknown. */
    case Unavailable = 'unavailable';

    /**
     * The source rejected the request; CompanyProfile::error() holds the exception. Unlike Unavailable,
     * asking again will not help — the input or the configuration (e.g. the VIES requester) must be fixed.
     */
    case Rejected = 'rejected';
}
