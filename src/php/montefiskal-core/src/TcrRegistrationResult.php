<?php

declare(strict_types=1);

namespace MonteFiskal;

use MonteFiskal\Errors\FiscalError;

/** Result of RegisterTCR (BR-R5). */
final readonly class TcrRegistrationResult
{
    public function __construct(
        public bool $success,
        public ?string $tcrCode,
        public ?FiscalError $error,
        public ?string $problem = null,
    ) {
    }
}
