<?php

declare(strict_types=1);

namespace MonteFiskal;

/** What happens to text outside printable ASCII (Q-07, BR-T8). Default Unchanged. */
enum TextPolicy: string
{
    case Unchanged = 'unchanged';
    case Transliterate = 'transliterate';
}
