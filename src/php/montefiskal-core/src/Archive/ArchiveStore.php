<?php

declare(strict_types=1);

namespace MonteFiskal\Archive;

/** Durable copy of everything sent and received (BR-X1, BR-X2). */
interface ArchiveStore
{
    public function save(string $requestId, string $kind, string $content): void;

    /** @return list<array{kind: string, content: string}> */
    public function load(string $requestId): array;
}
