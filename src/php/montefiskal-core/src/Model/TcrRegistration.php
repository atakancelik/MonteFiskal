<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** A TCR registration or change (BR-R1 to BR-R4). */
final readonly class TcrRegistration
{
    public function __construct(
        public string $issuerTin,
        public string $businessUnit,
        public string $internalId,
        public ?string $software = null,
        public ?string $maintainer = null,
        public ?string $validFrom = null,
        public ?string $validTo = null,
        public ?TcrType $type = null,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            issuerTin: (string) $a['issuerTin'],
            businessUnit: (string) $a['businessUnit'],
            internalId: (string) $a['internalId'],
            software: $a['software'] ?? null,
            maintainer: $a['maintainer'] ?? null,
            validFrom: $a['validFrom'] ?? null,
            validTo: $a['validTo'] ?? null,
            type: isset($a['type']) ? TcrType::from($a['type']) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact([
            'issuerTin' => $this->issuerTin,
            'businessUnit' => $this->businessUnit,
            'internalId' => $this->internalId,
            'software' => $this->software,
            'maintainer' => $this->maintainer,
            'validFrom' => $this->validFrom,
            'validTo' => $this->validTo,
            'type' => $this->type?->value,
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
