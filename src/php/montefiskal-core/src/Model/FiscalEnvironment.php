<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Target environment (BR-T1). */
enum FiscalEnvironment: string
{
    case Test = 'test';
    case Production = 'production';
}
