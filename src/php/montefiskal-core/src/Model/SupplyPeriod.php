<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Supply period (SupplyDateOrPeriod). */
final readonly class SupplyPeriod
{
    public function __construct(
        public string $start,
        public string $end,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            start: (string) $a['start'],
            end: (string) $a['end'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'start' => $this->start,
            'end' => $this->end,
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
