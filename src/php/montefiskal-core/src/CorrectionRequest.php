<?php

declare(strict_types=1);

namespace MonteFiskal;

use MonteFiskal\Model\CorrectionType;
use MonteFiskal\Model\InvoiceLine;
use MonteFiskal\Model\Payment;

/** Full reversal of a stored invoice (BR-C2) or a partial correction with explicit lines (BR-C5). */
final readonly class CorrectionRequest
{
    /**
     * @param list<InvoiceLine>|null $lines
     * @param list<Payment>|null $payments
     */
    public function __construct(
        public string $originalId,
        public CorrectionType $type = CorrectionType::Corrective,
        public ?array $lines = null,
        public ?array $payments = null,
    ) {
    }
}
