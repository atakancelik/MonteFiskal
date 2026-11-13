<?php

declare(strict_types=1);

namespace MonteFiskal\Calculation;

/** Result of the calculation: items, totals and VAT groups (SameTaxes). */
final readonly class CalculationResult
{
    /**
     * @param list<CalculatedLine> $lines
     * @param list<array<string, string>> $sameTaxes
     */
    public function __construct(
        public array $lines,
        public CalculatedTotals $totals,
        public array $sameTaxes,
    ) {
    }

    /** @param list<array<string, string>> $sameTaxes */
    public function withTotals(CalculatedTotals $totals, array $sameTaxes): self
    {
        return new self($this->lines, $totals, $sameTaxes);
    }
}
