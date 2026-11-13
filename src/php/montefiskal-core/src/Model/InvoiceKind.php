<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Invoice type (InvType, BR-K6). */
enum InvoiceKind: string
{
    case Invoice = 'INVOICE';
    case Corrective = 'CORRECTIVE';
    case Summary = 'SUMMARY';
    case Periodical = 'PERIODICAL';
    case Advance = 'ADVANCE';
    case CreditNote = 'CREDIT_NOTE';
}
