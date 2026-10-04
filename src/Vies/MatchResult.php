<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Vies;

/**
 * Outcome of comparing one trader field with the register of the member state.
 *
 * Many member states, the Czech Republic and Ireland among them, always answer NotProcessed. VIES may answer it
 * also for a field that was not sent.
 */
enum MatchResult: string
{
    case Valid = 'VALID';
    case Invalid = 'INVALID';
    case NotProcessed = 'NOT_PROCESSED';
}
