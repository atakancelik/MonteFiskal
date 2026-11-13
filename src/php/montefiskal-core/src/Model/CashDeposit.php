<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

use DateTimeImmutable;

/** A cash deposit (BR-D1, BR-D2). */
final readonly class CashDeposit
{
    public function __construct(
        public string $issuerTin,
        public string $tcr,
        public CashDepositOperation $operation,
        public string $amount,
        public ?DateTimeImmutable $changeDateTime = null,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            issuerTin: (string) $a['issuerTin'],
            tcr: (string) $a['tcr'],
            operation: CashDepositOperation::from($a['operation']),
            amount: Arr::dec($a['amount']),
            changeDateTime: Arr::time($a['changeDateTime'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'issuerTin' => $this->issuerTin,
            'tcr' => $this->tcr,
            'operation' => $this->operation->value,
            'amount' => $this->amount,
            'changeDateTime' => Arr::iso($this->changeDateTime),
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
