<?php

declare(strict_types=1);

namespace MonteFiskal;

/** Generates header UUIDs (version 4, as the schema requires). */
interface UuidGenerator
{
    public function newUuid(): string;
}
