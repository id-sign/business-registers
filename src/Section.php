<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

/**
 * Optional part of a company profile, fetched from a source other than ARES.
 */
enum Section
{
    /** VAT register (ADIS), queried under Company::vatLookupId(). */
    case Vat;

    /** EU VAT id validation (VIES), queried under Company::vatLookupId(). */
    case Vies;
}
