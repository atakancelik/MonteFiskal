<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

use DateTimeImmutable;

/** Reference to another invoice (IICRefs/IICRef, BR-K6, BR-K7, BR-K18). */
final readonly class InvoiceReference
{
    public function __construct(
        public string $iic,
        public DateTimeImmutable $issuedAt,
        public string $amount,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            iic: (string) $a['iic'],
            issuedAt: Arr::time($a['issuedAt']),
            amount: Arr::dec($a['amount']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'iic' => $this->iic,
            'issuedAt' => Arr::iso($this->issuedAt),
            'amount' => $this->amount,
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
