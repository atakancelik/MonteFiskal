<?php

declare(strict_types=1);

namespace MonteFiskal\Errors;

/** Class of a CIS fault code (error-codes.md section 3). */
enum FiscalErrorClass: string
{
    case Unknown = 'unknown';
    case Malformed = 'malformed';
    case Schema = 'schema';
    case Signature = 'signature';
    case Certificate = 'certificate';
    case Registration = 'registration';
    case Business = 'business';
    case Tcr = 'tcr';
    case CashDeposit = 'cashDeposit';
    case Clock = 'clock';
    case Server = 'server';
}
