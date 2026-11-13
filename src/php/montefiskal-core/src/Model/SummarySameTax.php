<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** One VAT group given directly for invoices without items. */
final readonly class SummarySameTax
{
    public function __construct(
        public string $priceBeforeVat,
        public string $vatAmount = '0',
        public int $numberOfItems = 0,
        public ?string $vatRate = null,
        public ?string $exemption = null,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            priceBeforeVat: Arr::dec($a['priceBeforeVat']),
            vatAmount: Arr::dec($a['vatAmount'] ?? '0'),
            numberOfItems: (int) ($a['numberOfItems'] ?? 0),
            vatRate: Arr::dec($a['vatRate'] ?? null),
            exemption: $a['exemption'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'priceBeforeVat' => $this->priceBeforeVat,
            'vatAmount' => $this->vatAmount,
            'numberOfItems' => $this->numberOfItems,
            'vatRate' => $this->vatRate,
            'exemption' => $this->exemption,
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
