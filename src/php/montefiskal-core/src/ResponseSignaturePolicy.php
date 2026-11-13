<?php

declare(strict_types=1);

namespace MonteFiskal;

/** How the signature of a CIS response is handled (Q-12, BR-S6). Default Verify. */
enum ResponseSignaturePolicy: string
{
    case Verify = 'verify';
    case Require = 'require';
    case Ignore = 'ignore';
}
