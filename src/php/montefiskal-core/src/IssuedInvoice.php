<?php

declare(strict_types=1);

namespace MonteFiskal;

use MonteFiskal\Calculation\CalculatedTotals;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Model\TypeOfInvoice;
use MonteFiskal\Validation\Finding;

/** An issued invoice: everything needed to print it, with or without JIKR (BR-J2, BR-P1). */
final readonly class IssuedInvoice
{
    public function __construct(
        public string $id,
        public string $number,
        public int $ordinal,
        public string $iic,
        public string $iicSignature,
        public string $verificationUrl,
        public string $issueDateTime,
        public InvoiceState $state,
        public CalculatedTotals $totals,
        public TypeOfInvoice $typeOfInv,
        /** @var list<Finding> */
        public array $warnings = [],
    ) {
    }
}
