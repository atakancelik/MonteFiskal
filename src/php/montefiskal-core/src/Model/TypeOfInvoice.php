<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Cash or non-cash invoice (TypeOfInv, BR-K1, BR-K3). */
enum TypeOfInvoice: string
{
    case Cash = 'CASH';
    case NonCash = 'NONCASH';
}
