<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** One invoice line. $unitPrice is net unless $priceIncludesVat is true (BR-A7). Decimals are numeric strings. */
final readonly class InvoiceLine
{
    public function __construct(
        public string $name,
        public string $unit,
        public string $quantity,
        public string $unitPrice,
        public bool $priceIncludesVat = false,
        /** VAT rate in percent, for example "21". Null for exempt lines and for sellers outside VAT. */
        public ?string $vatRate = null,
        /** Exemption code such as VAT_CL20 (BR-V5). */
        public ?string $exemption = null,
        public ?string $code = null,
        /** Rebate in percent (0 to 100) that reduces the base (RR="true", BR-A5). */
        public ?string $rebate = null,
        public ?bool $investment = null,
        public ?string $voucherExpiry = null,
        public ?string $voucherSerial = null,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) $a['name'],
            (string) $a['unit'],
            Arr::dec($a['quantity'] ?? '0'),
            Arr::dec($a['unitPrice'] ?? '0'),
            (bool) ($a['priceIncludesVat'] ?? false),
            Arr::dec($a['vatRate'] ?? null),
            $a['exemption'] ?? null,
            $a['code'] ?? null,
            Arr::dec($a['rebate'] ?? null),
            Arr::bool($a['investment'] ?? null),
            $a['voucherExpiry'] ?? null,
            $a['voucherSerial'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::compact(['name' => $this->name, 'code' => $this->code, 'unit' => $this->unit, 'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice, 'priceIncludesVat' => $this->priceIncludesVat ?: null, 'vatRate' => $this->vatRate,
            'exemption' => $this->exemption, 'rebate' => $this->rebate, 'investment' => $this->investment,
            'voucherExpiry' => $this->voucherExpiry, 'voucherSerial' => $this->voucherSerial]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
