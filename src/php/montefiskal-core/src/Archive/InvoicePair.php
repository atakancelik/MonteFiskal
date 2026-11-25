<?php

declare(strict_types=1);

namespace MonteFiskal\Archive;

/**
 * The signed request and the response of the exchange that received the JIKR (BR-X7), as archived.
 * A source is "archive:<index>" (position in ArchiveStore::load()) or "last" (the outbox's last request or response).
 */
final readonly class InvoicePair
{
    public function __construct(
        public string $requestFile,
        public string $request,
        public string $requestSource,
        public string $responseFile,
        public string $response,
        public string $responseSource,
    ) {
    }
}
