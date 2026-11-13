<?php

declare(strict_types=1);

namespace MonteFiskal\Validation;

enum Severity: string
{
    case Warning = 'warning';
    case Error = 'error';
}
