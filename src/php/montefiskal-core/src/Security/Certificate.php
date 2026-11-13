<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/** A signing certificate with its private key. Never logged, never serialized. */
final class Certificate
{
    private function __construct(
        public readonly OpenSSLCertificate $certificate,
        private readonly OpenSSLAsymmetricKey $privateKey,
    ) {
    }

    public static function fromPem(string $certificatePem, string $privateKeyPem, ?string $keyPassword = null): self
    {
        $cert = openssl_x509_read($certificatePem);
        $key = openssl_pkey_get_private($privateKeyPem, $keyPassword);
        if ($cert === false || $key === false) {
            throw new FiscalConfigurationException('The certificate or private key could not be read.');
        }
        return new self($cert, $key);
    }

    /** Reads a PKCS#12 (.pfx, .p12) file. Legacy RC2-40 files need OpenSSL's legacy provider (Q-21). */
    public static function fromPkcs12(string $pkcs12, string $password): self
    {
        $out = [];
        if (!openssl_pkcs12_read($pkcs12, $out, $password)) {
            $errors = [];
            while (($e = openssl_error_string()) !== false) {
                $errors[] = $e;
            }
            $text = implode('; ', $errors);
            if (str_contains($text, 'unsupported') || str_contains($text, 'RC2') || str_contains($text, '0308010C')) {
                throw new FiscalConfigurationException('Q-21: the PKCS#12 file uses a legacy algorithm (RC2/3DES) that this OpenSSL does not load. '
                    . 'Re-export it with AES, for example: openssl pkcs12 -legacy -in old.pfx -nodes | openssl pkcs12 -export -out new.pfx');
            }
            throw new FiscalConfigurationException('The PKCS#12 file could not be read (wrong password or damaged file).');
        }
        return self::fromPem($out['cert'], $out['pkey']);
    }

    public static function fromPkcs12File(string $path, string $password): self
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new FiscalConfigurationException('The certificate file is not readable.');
        }
        return self::fromPkcs12($data, $password);
    }

    public function privateKey(): OpenSSLAsymmetricKey
    {
        return $this->privateKey;
    }

    /** DER bytes of the certificate. */
    public function der(): string
    {
        openssl_x509_export($this->certificate, $pem);
        return self::pemToDer($pem);
    }

    public static function pemToDer(string $pem): string
    {
        $b64 = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
        return (string) base64_decode((string) $b64, true);
    }

    public static function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n"; // secret-scan: allow (PEM armour of a public certificate, no data)
    }

    /** Upper-case SHA-1 thumbprint, as shown by Windows and .NET. */
    public function thumbprint(): string
    {
        return strtoupper(sha1($this->der()));
    }

    /** @return array<string, mixed> */
    public function info(): array
    {
        return openssl_x509_parse($this->certificate) ?: [];
    }
}
