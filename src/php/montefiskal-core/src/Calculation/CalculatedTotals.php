<?php

declare(strict_types=1);

namespace MonteFiskal\Calculation;

/** Invoice totals as wire strings. */
final readonly class CalculatedTotals
{
    public function __construct(
        public string $totPriceWoVat,
        public ?string $totVatAmt,
        public string $totPrice,
    ) {
    }

    public function total(): string
    {
        return $this->totPrice;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $d = ['TotPriceWoVAT' => $this->totPriceWoVat, 'TotPrice' => $this->totPrice];
        if ($this->totVatAmt !== null) {
            $d['TotVATAmt'] = $this->totVatAmt;
        }
        return $d;
    }
}
