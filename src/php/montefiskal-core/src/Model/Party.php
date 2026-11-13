<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Buyer (or, for a self-issued invoice, the seller on whose behalf the buyer issues). */
final readonly class Party
{
    public function __construct(
        public string $idNum,
        public string $name,
        public IdType $idType = IdType::Tin,
        public ?string $address = null,
        public ?string $town = null,
        public ?string $country = null,
        public ?string $tic = null,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) $a['idNum'],
            (string) $a['name'],
            isset($a['idType']) ? IdType::from($a['idType']) : IdType::Tin,
            $a['address'] ?? null,
            $a['town'] ?? null,
            $a['country'] ?? null,
            $a['tic'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact(['idType' => $this->idType->value, 'idNum' => $this->idNum, 'name' => $this->name,
            'address' => $this->address, 'town' => $this->town, 'country' => $this->country, 'tic' => $this->tic]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
