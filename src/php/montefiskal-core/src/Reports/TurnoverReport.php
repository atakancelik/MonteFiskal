<?php

declare(strict_types=1);

namespace MonteFiskal\Reports;

/** Aggregates of a turnover report (BR-X3 items 1 to 3). Dates are yyyy-mm-dd, amounts decimal strings. */
final readonly class TurnoverReport
{
    /**
     * @param list<array{vatRate: string, priceBeforeVat: string, vatAmount: string}> $byVatRate
     * @param list<array{method: string, amount: string}> $byPaymentMethod
     * @param list<string> $operators
     */
    public function __construct(
        public string $title,
        public string $tcr,
        public string $businessUnit,
        public string $from,
        public string $to,
        public int $invoiceCount,
        public int $correctiveCount,
        public int $offlineCount,
        public string $totalWithoutVat,
        public string $vatAmount,
        public string $total,
        public array $byVatRate,
        public array $byPaymentMethod,
        public string $initialDeposit,
        public string $withdrawals,
        public string $expectedCash,
        public array $operators,
    ) {
    }
}
