<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

/** Result of computing or re-computing an IKOF (BR-I6, BR-X4). */
final readonly class IkofCheck
{
    public function __construct(public string $concatenation, public string $iic, public string $iicSignature)
    {
    }
}
