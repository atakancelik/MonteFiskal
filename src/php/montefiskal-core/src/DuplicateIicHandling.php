<?php

declare(strict_types=1);

namespace MonteFiskal;

/** What to do when CIS answers that an IKOF was already registered (Q-09). Default Reject. */
enum DuplicateIicHandling: string
{
    case Reject = 'reject';
    case AcceptWithoutFic = 'acceptWithoutFic';
}
