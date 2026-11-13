<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Admin;

use MonteFiskal\WooCommerce\Settings;

defined('ABSPATH') || exit;

/** Persistent admin notices for shop managers, keyed so that the same problem is shown once. */
final class Notices
{
    private const OPTION = 'montefiskal_notices';

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
        add_action('admin_post_montefiskal_dismiss', [self::class, 'dismiss']);
    }

    public static function add(string $key, string $message, string $type = 'warning'): void
    {
        $all = self::all();
        $all[$key] = ['message' => $message, 'type' => $type, 'at' => time()];
        update_option(self::OPTION, $all, false);
    }

    public static function remove(string $key): void
    {
        $all = self::all();
        if (isset($all[$key])) {
            unset($all[$key]);
            update_option(self::OPTION, $all, false);
        }
    }

    /** @return array<string, array{message: string, type: string, at: int}> */
    public static function all(): array
    {
        $v = get_option(self::OPTION, []);
        return is_array($v) ? $v : [];
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        foreach (self::all() as $key => $n) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=montefiskal_dismiss&key=' . rawurlencode($key)), 'montefiskal_dismiss');
            printf(
                '<div class="notice notice-%1$s"><p><strong>MonteFiskal:</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
                esc_attr(in_array($n['type'], ['error', 'warning', 'info', 'success'], true) ? $n['type'] : 'warning'),
                esc_html($n['message']),
                esc_url($url),
                esc_html__('Dismiss', 'montefiskal-woocommerce')
            );
        }
        // BR-R7, Q-26: every installation registers the software version and its maintainer on the SEP.
        if (!(bool) get_option('montefiskal_sep_confirmed', false)) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=montefiskal_dismiss&key=sep'), 'montefiskal_dismiss');
            printf(
                '<div class="notice notice-info"><p><strong>MonteFiskal:</strong> %1$s <a href="%2$s">%3$s</a></p></div>',
                esc_html__('Before going live, register this software version and its maintainer on the Tax Administration portal (SEP) and enter the software and maintainer codes you receive. Every release note says whether fiscal behaviour changed; when it did, the new version must be registered too (BR-R7).', 'montefiskal-woocommerce'),
                esc_url($url),
                esc_html__('I have registered it', 'montefiskal-woocommerce')
            );
        }
        if (!Settings::isProduction() && Settings::isConfigured()) {
            echo '<div class="notice notice-info"><p><strong>MonteFiskal:</strong> ' . esc_html__('Test environment: invoices go to efitest and are not legally valid.', 'montefiskal-woocommerce') . '</p></div>';
        }
    }

    public static function dismiss(): void
    {
        check_admin_referer('montefiskal_dismiss');
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'montefiskal-woocommerce'), 403);
        }
        $key = sanitize_text_field(wp_unslash((string) ($_GET['key'] ?? '')));
        if ($key === 'sep') {
            update_option('montefiskal_sep_confirmed', true, false);
        } else {
            self::remove($key);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
