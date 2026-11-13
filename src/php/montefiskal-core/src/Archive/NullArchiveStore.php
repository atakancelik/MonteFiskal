<?php

declare(strict_types=1);

namespace MonteFiskal\Archive;

/** Archive that keeps nothing; the outbox still holds the last request and response. */
final class NullArchiveStore implements ArchiveStore
{
    public function save(string $requestId, string $kind, string $content): void
    {
    }

    public function load(string $requestId): array
    {
        return [];
    }
}
