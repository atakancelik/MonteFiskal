<?php

declare(strict_types=1);

namespace MonteFiskal\Validation;

/** A blocking local rule failed; nothing was issued and no ordinal was consumed (architecture section 2.4). */
final class FiscalValidationException extends \RuntimeException
{
    public function __construct(public readonly ValidationResult $result)
    {
        parent::__construct('Validation failed: ' . implode('; ', array_map('strval', $result->errors())));
    }
}
