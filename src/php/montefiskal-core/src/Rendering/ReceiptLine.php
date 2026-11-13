<?php

declare(strict_types=1);

namespace MonteFiskal\Rendering;

/** One printed item. */
final readonly class ReceiptLine
{
    public function __construct(
        public string $name,
        public string $unit,
        public string $quantity,
        public string $unitPriceWithVat,
        public string $amountWithVat,
        public ?string $vatRate,
        public ?string $exemption,
    ) {
    }
}
