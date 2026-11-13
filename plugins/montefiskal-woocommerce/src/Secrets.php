<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Fallback encryption of the certificate password when it cannot be put in wp-config.php (architecture section 4,
 * "Certificate"). The key is derived from the site's secret salts, so a database dump alone does not reveal it.
 */
final class Secrets
{
    private static function key(): string
    {
        return sodium_crypto_generichash(wp_salt('auth') . '|montefiskal-certificate', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(#[\SensitiveParameter] string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, 'v1:')) {
            return '';
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? '' : $plain;
    }
}
