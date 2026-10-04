<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Ares;

/**
 * Source register tracked by ARES; the value is the suffix of the key seznamRegistraci.stavZdroje{X}.
 */
enum AresRegister: string
{
    case PersonsRegister = 'Ros';
    case PublicRegister = 'Vr';
    case Statistical = 'Res';
    case Trade = 'Rzp';
    case Healthcare = 'Nrpzs';
    case PoliticalParties = 'Rpsh';
    case Churches = 'Rcns';
    case Agriculture = 'Szr';
    case Vat = 'Dph';
    case VatGroup = 'SkDph';
    case ExciseTax = 'Sd';
    case Insolvency = 'Ir';
    case Bankruptcy = 'Ceu';
    case Schools = 'Rs';
    case Subsidies = 'Red';
    case StateAccounting = 'Monitor';
}
