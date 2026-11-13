<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** TCR type (BR-R2). */
enum TcrType: string
{
    case Regular = 'REGULAR';
    case Vending = 'VENDING';
}
