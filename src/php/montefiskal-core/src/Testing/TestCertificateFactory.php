<?php

declare(strict_types=1);

namespace MonteFiskal\Testing;

use MonteFiskal\Security\Certificate;

/**
 * Self-signed test certificates generated at run time. Nothing is stored in the repository and no shared test
 * certificate from another project is used (test-strategy.md). Never valid for the real service.
 */
final class TestCertificateFactory
{
    public static function create(string $tin = '12345678', string $commonName = 'MonteFiskal Test Only', int $bits = 2048, int $days = 365): Certificate
    {
        [$certPem, $keyPem] = self::createPem($tin, $commonName, $bits, $days);
        return Certificate::fromPem($certPem, $keyPem);
    }

    /** @return array{0: string, 1: string} certificate PEM and unencrypted private key PEM */
    public static function createPem(string $tin = '12345678', string $commonName = 'MonteFiskal Test Only', int $bits = 2048, int $days = 365): array
    {
        $config = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($config);
        if ($key === false) {
            throw new \RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
        }
        $dn = ['commonName' => $commonName, 'serialNumber' => $tin, 'organizationName' => 'Test Only', 'countryName' => 'ME'];
        $csr = openssl_csr_new($dn, $key, $config);
        $cert = openssl_csr_sign($csr, null, $key, $days, $config, random_int(1, PHP_INT_MAX));
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        return [$certPem, $keyPem];
    }
}
