<?php

declare(strict_types=1);

namespace MonteFiskal;

/** An issued invoice with its JIKR. */
final readonly class FiscalizedInvoice
{
    public function __construct(
        public IssuedInvoice $invoice,
        public string $fic,
        public \DateTimeImmutable $registeredAt,
    ) {
    }
}
