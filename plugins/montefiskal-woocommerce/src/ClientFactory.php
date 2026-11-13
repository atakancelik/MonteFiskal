<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce;

use MonteFiskal\Archive\FileArchiveStore;
use MonteFiskal\FiscalClient;
use MonteFiskal\Security\CertificateProvider;
use MonteFiskal\Security\FiscalConfigurationException;
use MonteFiskal\Security\Pkcs12CertificateProvider;
use MonteFiskal\Transport\CurlTransport;
use MonteFiskal\Transport\FiscalEndpoints;
use MonteFiskal\Transport\FiscalTransport;
use MonteFiskal\WooCommerce\Storage\WpdbOutboxStore;

defined('ABSPATH') || exit;

/**
 * Builds the PHP core client from the settings. Filters let tests and integrators replace the transport and the
 * certificate source: `montefiskal_transport`, `montefiskal_certificate_provider`, `montefiskal_clock`.
 */
final class ClientFactory
{
    private static ?WpdbOutboxStore $store = null;
    private static ?FiscalClient $client = null;

    public static function store(): WpdbOutboxStore
    {
        return self::$store ??= new WpdbOutboxStore();
    }

    public static function client(): FiscalClient
    {
        if (self::$client !== null) {
            return self::$client;
        }
        $options = Settings::options();
        $profile = Settings::profile();
        $endpoint = FiscalEndpoints::for($profile->environment);
        /** @var FiscalTransport $transport */
        $transport = apply_filters('montefiskal_transport', null, $profile->environment) ?? new CurlTransport($endpoint, $options);
        $archiveDir = Settings::archiveDirectory();
        self::protectDirectory($archiveDir);
        return self::$client = new FiscalClient(
            $profile,
            self::certificates(),
            $transport,
            self::store(),
            $options,
            apply_filters('montefiskal_clock', null),
            null,
            null,
            new FileArchiveStore($archiveDir),
        );
    }

    /** Forgets the cached client, for example after the settings changed. */
    public static function reset(): void
    {
        self::$client = null;
        self::$store = null;
    }

    /**
     * Certificate source, in order: the filter, `MONTEFISKAL_CERT_PATH`/`MONTEFISKAL_CERT_PASSWORD` constants in
     * wp-config.php, the same names as environment variables, and last the path and encrypted password saved in the
     * settings. The certificate itself is never stored in the database or the media library.
     */
    public static function certificates(): CertificateProvider
    {
        $filtered = apply_filters('montefiskal_certificate_provider', null);
        if ($filtered instanceof CertificateProvider) {
            return $filtered;
        }
        if (defined('MONTEFISKAL_CERT_PATH')) {
            return new Pkcs12CertificateProvider((string) constant('MONTEFISKAL_CERT_PATH'), defined('MONTEFISKAL_CERT_PASSWORD') ? (string) constant('MONTEFISKAL_CERT_PASSWORD') : '');
        }
        if (getenv('MONTEFISKAL_CERT_PATH') !== false) {
            return Pkcs12CertificateProvider::fromEnvironment();
        }
        $path = (string) get_option('montefiskal_cert_path', '');
        if ($path === '') {
            return new class implements CertificateProvider {
                public function certificate(): \MonteFiskal\Security\Certificate
                {
                    throw new FiscalConfigurationException('No certificate configured. Define MONTEFISKAL_CERT_PATH and MONTEFISKAL_CERT_PASSWORD in wp-config.php.');
                }
            };
        }
        return new Pkcs12CertificateProvider($path, Secrets::decrypt((string) get_option('montefiskal_cert_password', '')));
    }

    /** Where the certificate comes from, for the status page (never the password). */
    public static function certificateSource(): string
    {
        if (has_filter('montefiskal_certificate_provider')) {
            return 'filter';
        }
        if (defined('MONTEFISKAL_CERT_PATH')) {
            return 'wp-config.php';
        }
        if (getenv('MONTEFISKAL_CERT_PATH') !== false) {
            return 'environment';
        }
        return get_option('montefiskal_cert_path', '') !== '' ? 'settings' : 'none';
    }

    /** Keeps the archive out of directory listings and web access where the server honours these files. */
    public static function protectDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (is_dir($dir) && !file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            @file_put_contents($dir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
        }
    }
}
