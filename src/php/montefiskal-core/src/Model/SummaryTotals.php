<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Totals given directly for invoices without items. */
final readonly class SummaryTotals
{
    /** @param list<SummarySameTax> $sameTaxes */
    public function __construct(
        public string $totalWithoutVat,
        public string $vatAmount,
        public string $total,
        public array $sameTaxes = [],
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            totalWithoutVat: Arr::dec($a['totalWithoutVat']),
            vatAmount: Arr::dec($a['vatAmount']),
            total: Arr::dec($a['total']),
            sameTaxes: array_map(static fn (array $x): SummarySameTax => SummarySameTax::fromArray($x), $a['sameTaxes'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'totalWithoutVat' => $this->totalWithoutVat,
            'vatAmount' => $this->vatAmount,
            'total' => $this->total,
            'sameTaxes' => array_map(static fn (SummarySameTax $x): array => $x->toArray(), $this->sameTaxes),
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
