<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

use DateTimeImmutable;

/** Reference to the corrected invoice (CorrectiveInv, BR-C4). */
final readonly class CorrectionInfo
{
    public function __construct(
        public string $iicRef,
        public DateTimeImmutable $issuedAt,
        public CorrectionType $type = CorrectionType::Corrective,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            iicRef: (string) $a['iicRef'],
            issuedAt: Arr::time($a['issuedAt']),
            type: isset($a['type']) ? CorrectionType::from($a['type']) : CorrectionType::Corrective,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'iicRef' => $this->iicRef,
            'issuedAt' => Arr::iso($this->issuedAt),
            'type' => $this->type->value,
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
