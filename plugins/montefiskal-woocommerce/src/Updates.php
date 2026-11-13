<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Optional update check against the GitHub releases API (no WordPress.org listing). Off by default; when enabled the
 * site asks api.github.com once every twelve hours and offers the release zip named montefiskal-woocommerce-*.zip.
 */
final class Updates
{
    public const RELEASES = 'https://api.github.com/repos/atakancelik/MonteFiskal/releases/latest';

    public static function register(): void
    {
        if (!(bool) Settings::get('update_check')) {
            return;
        }
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject']);
    }

    /** @return array{version: string, package: string, url: string}|null */
    public static function latest(): ?array
    {
        $cached = get_site_transient('montefiskal_latest_release');
        if (is_array($cached)) {
            return $cached === [] ? null : $cached;
        }
        $response = wp_remote_get(self::RELEASES, ['timeout' => 10, 'headers' => ['Accept' => 'application/vnd.github+json']]);
        $release = [];
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            foreach ((array) ($body['assets'] ?? []) as $asset) {
                if (preg_match('/^montefiskal-woocommerce-[\d.]+\.zip$/', (string) ($asset['name'] ?? ''))) {
                    $release = ['version' => ltrim((string) $body['tag_name'], 'v'), 'package' => (string) $asset['browser_download_url'], 'url' => (string) $body['html_url']];
                }
            }
        }
        set_site_transient('montefiskal_latest_release', $release, 12 * HOUR_IN_SECONDS);
        return $release === [] ? null : $release;
    }

    public static function inject(mixed $transient): mixed
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $latest = self::latest();
        $basename = plugin_basename(MONTEFISKAL_WC_FILE);
        if ($latest !== null && version_compare($latest['version'], MONTEFISKAL_WC_VERSION, '>')) {
            $transient->response[$basename] = (object) [
                'slug' => 'montefiskal-woocommerce',
                'plugin' => $basename,
                'new_version' => $latest['version'],
                'package' => $latest['package'],
                'url' => $latest['url'],
            ];
        }
        return $transient;
    }
}
