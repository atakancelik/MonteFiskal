<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

/** The seven values of the IKOF (BR-I1 to BR-I4), already formatted as sent. */
final readonly class IkofParameters
{
    public function __construct(
        public string $issuerTin,
        public string $issueDateTime,
        public string $ordinal,
        public string $businessUnit,
        public string $tcr,
        public string $software,
        public string $totalPrice,
    ) {
    }
}
