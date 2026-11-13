<?php

declare(strict_types=1);

namespace MonteFiskal\Calculation;

/** Wire attributes of one item (Items/I) and the unformatted values behind them. */
final readonly class CalculatedLine
{
    /** @param array<string, string> $attributes */
    public function __construct(
        public array $attributes,
        public string $priceBeforeVat,
        public string $vatAmount,
        public string $priceAfterVat,
        public ?string $groupKey,
    ) {
    }
}
