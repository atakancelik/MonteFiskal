<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Reason for a late delivery (SubseqDelivType, BR-O1 to BR-O6). */
enum SubsequentDelivery: string
{
    case NoInternet = 'NOINTERNET';
    case BoundBook = 'BOUNDBOOK';
    case Service = 'SERVICE';
    case TechnicalError = 'TECHNICALERROR';
    case BusinessNeeds = 'BUSINESSNEEDS';
}
