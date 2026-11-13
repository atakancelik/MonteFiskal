<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

/** Loads a PKCS#12 file from a path outside the web root; the password comes from the environment or a file. */
final class Pkcs12CertificateProvider implements CertificateProvider
{
    private ?Certificate $loaded = null;

    public function __construct(private readonly string $path, #[\SensitiveParameter] private readonly string $password)
    {
    }

    /** MONTEFISKAL_CERT_PATH and MONTEFISKAL_CERT_PASSWORD (or the given names). */
    public static function fromEnvironment(string $pathVariable = 'MONTEFISKAL_CERT_PATH', string $passwordVariable = 'MONTEFISKAL_CERT_PASSWORD'): self
    {
        $path = getenv($pathVariable);
        $password = getenv($passwordVariable);
        if ($path === false || $path === '' || $password === false) {
            throw new FiscalConfigurationException("Set {$pathVariable} and {$passwordVariable}.");
        }
        return new self($path, $password);
    }

    public function certificate(): Certificate
    {
        return $this->loaded ??= Certificate::fromPkcs12File($this->path, $this->password);
    }
}
