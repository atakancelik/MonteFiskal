<?php

declare(strict_types=1);

namespace MonteFiskal;

use MonteFiskal\Errors\FiscalError;
use MonteFiskal\Model\InvoiceState;

/** Result of one send attempt. Never an exception for a fiscal fault. */
final readonly class SendResult
{
    public function __construct(
        public InvoiceState $state,
        public ?string $fic,
        public ?FiscalError $error,
        public ?string $problem = null,
    ) {
    }
}
