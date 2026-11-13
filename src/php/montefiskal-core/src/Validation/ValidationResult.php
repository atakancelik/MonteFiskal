<?php

declare(strict_types=1);

namespace MonteFiskal\Validation;

/** Findings of a validation run. Blocking only when an error is present (architecture section 1, principle 5). */
final readonly class ValidationResult
{
    /** @param list<Finding> $findings */
    public function __construct(public array $findings)
    {
    }

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /** @return list<Finding> */
    public function errors(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->severity === Severity::Error));
    }

    /** @return list<Finding> */
    public function warnings(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->severity === Severity::Warning));
    }
}
