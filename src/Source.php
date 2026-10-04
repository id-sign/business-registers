<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters;

/**
 * The register a response or an error comes from.
 */
enum Source: string
{
    case Ares = 'ares';
    case Adis = 'adis';
    case Vies = 'vies';
    case Isir = 'isir';
}
