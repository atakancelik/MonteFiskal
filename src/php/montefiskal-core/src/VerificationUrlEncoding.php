<?php

declare(strict_types=1);

namespace MonteFiskal;

/** How values of the verification URL are written (Q-06, BR-Q6). Default Percent. */
enum VerificationUrlEncoding: string
{
    case Percent = 'percent';
    case None = 'none';
}
