<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

/** Result of verifying an enveloped signature. $certificateDer is the signing certificate, when found. */
final readonly class SignatureCheck
{
    public function __construct(
        public bool $present,
        public bool $valid,
        public ?string $certificateDer,
        public ?string $problem,
    ) {
    }
}
