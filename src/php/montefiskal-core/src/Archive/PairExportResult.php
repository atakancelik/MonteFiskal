<?php

declare(strict_types=1);

namespace MonteFiskal\Archive;

/** Outcome of InvoicePairs::export(): the files written and the invoices left out, with the reason (BR-X7). */
final readonly class PairExportResult
{
    /**
     * @param list<string> $written Paths of the files written, request then response for each invoice.
     * @param list<array{id: string, iic: ?string, reason: string}> $skipped
     */
    public function __construct(public array $written, public array $skipped)
    {
    }

    public function pairs(): int
    {
        return intdiv(count($this->written), 2);
    }
}
