<?php

declare(strict_types=1);

namespace MonteFiskal\Validation;

/** One finding, keyed by the rule ID of docs/analysis/business-rules.md or SCHEMA. */
final readonly class Finding
{
    public function __construct(public string $rule, public Severity $severity, public string $path, public string $message)
    {
    }

    public function __toString(): string
    {
        return "{$this->rule} {$this->severity->value} at {$this->path}: {$this->message}";
    }
}
