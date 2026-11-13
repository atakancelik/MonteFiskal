<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Fee type (Fee/@Type). */
enum FeeType: string
{
    case Pack = 'PACK';
    case Commission = 'COMMISSION';
    case Other = 'OTHER';
}
