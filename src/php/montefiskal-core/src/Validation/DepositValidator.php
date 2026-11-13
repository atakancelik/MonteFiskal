<?php

declare(strict_types=1);

namespace MonteFiskal\Validation;

use MonteFiskal\Calculation\Dec;
use MonteFiskal\Model\CashDeposit;
use MonteFiskal\Model\CashDepositOperation;

/** Business rules for cash deposits (BR-D1, BR-D2). */
final class DepositValidator
{
    public static function validate(CashDeposit $deposit): ValidationResult
    {
        $f = new FindingList();
        // BR-D2: a withdrawal cannot be zero; amounts are never negative. BR-D1 allows an INITIAL of 0.00.
        if (Dec::cmp($deposit->amount, '0') < 0) {
            $f->error('BR-D2', 'CashDeposit/@CashAmt', 'The amount of a cash deposit cannot be negative.');
        } elseif ($deposit->operation === CashDepositOperation::Withdraw && Dec::isZero($deposit->amount)) {
            $f->error('BR-D2', 'CashDeposit/@CashAmt', 'A WITHDRAW of 0.00 is refused.');
        }
        return $f->result();
    }
}
