<?php

declare(strict_types=1);

namespace MonteFiskal;

/** Limits of one resend run (BR-O8). */
final readonly class ResendOptions
{
    public function __construct(
        public int $maxItems = 50,
        public ?int $budgetSeconds = null,
    ) {
    }
}
