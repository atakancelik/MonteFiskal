<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** A fee (Fees/Fee). */
final readonly class Fee
{
    public function __construct(
        public FeeType $type,
        public string $amount,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            type: FeeType::from($a['type']),
            amount: Arr::dec($a['amount']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'type' => $this->type->value,
            'amount' => $this->amount,
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
