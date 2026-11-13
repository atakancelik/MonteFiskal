<?php

declare(strict_types=1);

namespace MonteFiskal\Wire;

use MonteFiskal\Errors\FiscalError;
use MonteFiskal\Security\SignatureCheck;

/** Parsed answer of CIS. */
final readonly class ParsedResponse
{
    public function __construct(
        public ExchangeOutcome $outcome,
        public bool $retryable,
        public ?string $element = null,
        public ?string $result = null,
        public ?string $requestUuid = null,
        public ?string $responseUuid = null,
        public ?string $sendDateTime = null,
        public ?SignatureCheck $signature = null,
        public ?FiscalError $error = null,
        public ?string $problem = null,
    ) {
    }
}
