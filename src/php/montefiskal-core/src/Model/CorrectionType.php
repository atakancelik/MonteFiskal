<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Corrective invoice type (CorrectiveInv/@Type, BR-C4). */
enum CorrectionType: string
{
    case Corrective = 'CORRECTIVE';
    case ErrorCorrective = 'ERROR_CORRECTIVE';
}
