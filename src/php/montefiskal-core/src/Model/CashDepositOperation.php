<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Cash deposit operation (BR-D1, BR-D2). */
enum CashDepositOperation: string
{
    case Initial = 'INITIAL';
    case Withdraw = 'WITHDRAW';
}
