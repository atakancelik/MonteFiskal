<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** A payment method with its amount; a null amount means the invoice total (or the remainder). */
final readonly class Payment
{
    /** @param list<string> $vouchers */
    public function __construct(
        public PaymentMethod $method,
        public ?string $amount = null,
        public ?string $companyCard = null,
        public array $vouchers = [],
        public ?string $advanceIic = null,
        public ?string $bankAccount = null,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            PaymentMethod::from($a['method']),
            Arr::dec($a['amount'] ?? null),
            $a['companyCard'] ?? null,
            array_values(array_map('strval', $a['vouchers'] ?? [])),
            $a['advanceIic'] ?? null,
            $a['bankAccount'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact(['method' => $this->method->value, 'amount' => $this->amount, 'companyCard' => $this->companyCard,
            'vouchers' => $this->vouchers, 'advanceIic' => $this->advanceIic, 'bankAccount' => $this->bankAccount]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
