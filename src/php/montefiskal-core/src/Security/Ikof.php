<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

use OpenSSLAsymmetricKey;

/**
 * IKOF (protective code of the issuer): RSA-SHA256 PKCS#1 v1.5 signature of the concatenated values, hex upper
 * case (IICSignature), and the MD5 of the signature bytes, hex upper case (IIC). D-01 section 4.3.2.
 */
final class Ikof
{
    /** BR-I1, BR-I2: the values joined with | in the fixed order. */
    public static function concatenate(string $tin, string $issueDateTime, string $ordinal, string $businessUnit, string $tcr, string $software, string $totalPrice): string
    {
        return implode('|', [$tin, $issueDateTime, $ordinal, $businessUnit, $tcr, $software, $totalPrice]);
    }

    public static function concatenateParameters(IkofParameters $p): string
    {
        return self::concatenate($p->issuerTin, $p->issueDateTime, $p->ordinal, $p->businessUnit, $p->tcr, $p->software, $p->totalPrice);
    }

    /** Signs the concatenation with the taxpayer's key. Deterministic (BR-I7). */
    public static function compute(string $concatenation, OpenSSLAsymmetricKey $key): IkofCheck
    {
        if (!openssl_sign($concatenation, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new FiscalConfigurationException('IKOF signing failed: ' . (openssl_error_string() ?: 'unknown OpenSSL error'));
        }
        return new IkofCheck($concatenation, self::iicFromSignature($signature), strtoupper(bin2hex($signature)));
    }

    public static function computeParameters(IkofParameters $p, OpenSSLAsymmetricKey $key): IkofCheck
    {
        return self::compute(self::concatenateParameters($p), $key);
    }

    /** BR-I3: MD5 of the signature bytes, upper-case hex. */
    public static function iicFromSignature(string $signatureBytes): string
    {
        return strtoupper(md5($signatureBytes));
    }

    /** Verifies an IICSignature with a public key. */
    public static function verify(string $concatenation, string $signatureHex, OpenSSLAsymmetricKey|string $publicKey): bool
    {
        if (!ctype_xdigit($signatureHex) || strlen($signatureHex) % 2 !== 0) {
            return false;
        }
        return openssl_verify($concatenation, (string) hex2bin($signatureHex), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
