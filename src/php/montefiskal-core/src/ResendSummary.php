<?php

declare(strict_types=1);

namespace MonteFiskal;

/** Counts of one resend run. */
final readonly class ResendSummary
{
    public function __construct(
        public int $attempted,
        public int $fiscalized,
        public int $stillPending,
        public int $rejected,
    ) {
    }
}
