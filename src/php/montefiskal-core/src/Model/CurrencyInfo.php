<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Foreign currency; exchangeRate is EUR per one unit of the foreign currency (BR-K12). */
final readonly class CurrencyInfo
{
    public function __construct(
        public string $code,
        public string $exchangeRate,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            code: (string) $a['code'],
            exchangeRate: Arr::dec($a['exchangeRate']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'code' => $this->code,
            'exchangeRate' => $this->exchangeRate,
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
