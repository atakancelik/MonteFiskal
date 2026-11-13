<?php

declare(strict_types=1);

namespace MonteFiskal\Calculation;

use MonteFiskal\Model\InvoiceLine;

/** Rounding and calculation policy (BR-A5 to BR-A7, Q-14). The default is InvoiceCalculator. */
interface RoundingPolicy
{
    /** @param list<InvoiceLine> $lines */
    public function calculate(array $lines, bool $issuerInVat, int $itemDecimals = 4): CalculationResult;
}
