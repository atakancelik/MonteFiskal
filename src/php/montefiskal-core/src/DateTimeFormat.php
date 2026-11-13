<?php

declare(strict_types=1);

namespace MonteFiskal;

/** Format of date-time values (Q-23). Default LocalOffset. */
enum DateTimeFormat: string
{
    case LocalOffset = 'localOffset';
    case Utc = 'utc';
}
