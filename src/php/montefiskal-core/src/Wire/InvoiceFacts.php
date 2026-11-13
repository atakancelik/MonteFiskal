<?php

declare(strict_types=1);

namespace MonteFiskal\Wire;

use MonteFiskal\Calculation\CalculatedTotals;
use MonteFiskal\Calculation\CalculationResult;
use MonteFiskal\Model\TypeOfInvoice;

/** Formatted values derived while building an invoice; the same strings go into IKOF and QR (BR-I4). */
final readonly class InvoiceFacts
{
    public function __construct(
        public string $invNum,
        public string $issueDateTime,
        public string $iicConcat,
        public TypeOfInvoice $typeOfInv,
        public CalculatedTotals $totals,
        public CalculationResult $calculation,
    ) {
    }
}
