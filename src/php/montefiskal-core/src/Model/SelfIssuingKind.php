<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Self-issuing kind (TypeOfSelfIss, BR-K10). */
enum SelfIssuingKind: string
{
    case Agreement = 'AGREEMENT';
    case Domestic = 'DOMESTIC';
    case Abroad = 'ABROAD';
    case Self = 'SELF';
    case Other = 'OTHER';
}
