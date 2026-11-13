<?php

declare(strict_types=1);

namespace MonteFiskal\Rendering;

/** One VAT group of the printed summary. */
final readonly class ReceiptVatGroup
{
    public function __construct(public string $key, public string $base, public ?string $vat)
    {
    }
}
