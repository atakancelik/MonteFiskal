<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** State of a stored request (architecture section 2.5). */
enum InvoiceState: string
{
    case Issued = 'Issued';
    case Pending = 'Pending';
    case Overdue = 'Overdue';
    case Fiscalized = 'Fiscalized';
    case Rejected = 'Rejected';
}
