<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares;

/**
 * Status of a subject in one ARES source register.
 *
 * Unknown stands for a value ARES started sending after this library was released.
 */
enum RegistrationStatus: string
{
    case Active = 'AKTIVNI';
    case Historical = 'HISTORICKY';
    /** ZANIKLY: the registration in that register ended (zánik). */
    case Ended = 'ZANIKLY';
    case Nonexistent = 'NEEXISTUJICI';
    case Suspended = 'POZASTAVENY';
    case Future = 'BUDOUCI';
    case LogicallyDeleted = 'LOGICKY_SMAZANY';
    case Unknown = 'UNKNOWN';
}
