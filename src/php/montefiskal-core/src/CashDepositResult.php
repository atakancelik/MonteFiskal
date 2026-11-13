<?php

declare(strict_types=1);

namespace MonteFiskal;

use MonteFiskal\Errors\FiscalError;
use MonteFiskal\Model\InvoiceState;

/** Result of RegisterCashDeposit. */
final readonly class CashDepositResult
{
    public function __construct(
        public string $id,
        public InvoiceState $state,
        public ?string $fcdc,
        public ?FiscalError $error,
        public ?string $problem = null,
    ) {
    }
}
