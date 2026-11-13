<?php

declare(strict_types=1);

namespace MonteFiskal;

use DateTimeImmutable;

/** Clock with the method of PSR-20 (Psr\Clock\ClockInterface); any PSR-20 clock can be wrapped in one line. */
interface Clock
{
    public function now(): DateTimeImmutable;
}
