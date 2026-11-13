<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

/** Supplies the signing certificate (architecture section 2.3). */
interface CertificateProvider
{
    public function certificate(): Certificate;
}
