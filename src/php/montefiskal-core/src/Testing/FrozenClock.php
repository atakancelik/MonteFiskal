<?php

declare(strict_types=1);

namespace MonteFiskal\Testing;

use DateInterval;
use DateTimeImmutable;
use MonteFiskal\Clock;

/** A clock for tests that only moves when told to. */
final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $isoDuration): void
    {
        $this->now = $this->now->add(new DateInterval($isoDuration));
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
