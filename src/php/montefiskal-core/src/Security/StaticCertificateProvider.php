<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

final class StaticCertificateProvider implements CertificateProvider
{
    public function __construct(private readonly Certificate $certificate)
    {
    }

    public function certificate(): Certificate
    {
        return $this->certificate;
    }
}
