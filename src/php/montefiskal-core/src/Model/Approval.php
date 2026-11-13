<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** One approval of a credit note (BR-K18). */
final readonly class Approval
{
    public function __construct(
        public string $totalAmount,
        public ?string $discountAmount = null,
        public ?string $returnAmount = null,
        public ?string $vatRate = null,
        public ?string $exemptAmount = null,
        public ?string $vatAmount = null,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            totalAmount: Arr::dec($a['totalAmount']),
            discountAmount: Arr::dec($a['discountAmount'] ?? null),
            returnAmount: Arr::dec($a['returnAmount'] ?? null),
            vatRate: Arr::dec($a['vatRate'] ?? null),
            exemptAmount: Arr::dec($a['exemptAmount'] ?? null),
            vatAmount: Arr::dec($a['vatAmount'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'totalAmount' => $this->totalAmount,
            'discountAmount' => $this->discountAmount,
            'returnAmount' => $this->returnAmount,
            'vatRate' => $this->vatRate,
            'exemptAmount' => $this->exemptAmount,
            'vatAmount' => $this->vatAmount,
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
