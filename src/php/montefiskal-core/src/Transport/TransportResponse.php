<?php

declare(strict_types=1);

namespace MonteFiskal\Transport;

/** Raw HTTP answer. */
final readonly class TransportResponse
{
    public function __construct(public int $statusCode, public string $body)
    {
    }
}
