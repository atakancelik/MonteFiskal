<?php

declare(strict_types=1);

namespace MonteFiskal\Wire;

use DateTimeImmutable;
use MonteFiskal\Model\SubsequentDelivery;

/** Per-request values that are not part of the draft. */
final readonly class RequestHeader
{
    public function __construct(
        public string $uuid,
        public DateTimeImmutable $sendDateTime,
        public ?SubsequentDelivery $subsequentDelivery = null,
    ) {
    }
}
