<?php

declare(strict_types=1);

namespace MonteFiskal\Wire;

/** The values of an issued invoice that enter the XML. */
final readonly class IssueValues
{
    public function __construct(public int $ordinal, public string $iic, public string $iicSignature)
    {
    }
}
