<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

use DateTimeImmutable;

/** Reference to an invoice declared uncollectible (BadDebtInv, BR-K13). */
final readonly class BadDebtInfo
{
    public function __construct(
        public string $iicRef,
        public DateTimeImmutable $issuedAt,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            iicRef: (string) $a['iicRef'],
            issuedAt: Arr::time($a['issuedAt']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'iicRef' => $this->iicRef,
            'issuedAt' => Arr::iso($this->issuedAt),
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
