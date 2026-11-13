<?php

declare(strict_types=1);

namespace MonteFiskal\Validation;

/** @internal */
final class FindingList
{
    /** @var list<Finding> */
    private array $items = [];

    public function error(string $rule, string $path, string $message): void
    {
        $this->items[] = new Finding($rule, Severity::Error, $path, $message);
    }

    public function warn(string $rule, string $path, string $message): void
    {
        $this->items[] = new Finding($rule, Severity::Warning, $path, $message);
    }

    public function hasErrors(): bool
    {
        foreach ($this->items as $f) {
            if ($f->severity === Severity::Error) {
                return true;
            }
        }
        return false;
    }

    public function result(): ValidationResult
    {
        return new ValidationResult($this->items);
    }
}
