<?php

declare(strict_types=1);

namespace MonteFiskal;

/** Scope of the invoice ordinal sequence (BR-N3). Default TcrYear. */
enum SequenceScope: string
{
    case TcrYear = 'tcrYear';
    case BusinessUnitYear = 'businessUnitYear';
}
