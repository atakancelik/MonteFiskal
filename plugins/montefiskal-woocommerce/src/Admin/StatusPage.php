<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Admin;

use DateTimeImmutable;
use MonteFiskal\Security\CertificateChecks;
use MonteFiskal\Wire\SchemaSet;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Install;
use MonteFiskal\WooCommerce\Queue\Queue;
use MonteFiskal\WooCommerce\Settings;

defined('ABSPATH') || exit;

/**
 * Status: pending count, oldest pending age, certificate expiry, last error; and the explicit tool that deletes
 * fiscal data after a warning about the retention duty (BR-X1). Uninstalling never does that.
 */
final class StatusPage
{
    public const SLUG = 'montefiskal-status';
    public const ERASE_PHRASE = 'DELETE FISCAL RECORDS';

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_submenu_page('woocommerce', __('Fiscal status', 'montefiskal-woocommerce'), __('Fiscal status', 'montefiskal-woocommerce'), 'manage_woocommerce', self::SLUG, [self::class, 'render']);
        }, 62);
        add_action('admin_post_montefiskal_resend', [self::class, 'resend']);
        add_action('admin_post_montefiskal_erase', [self::class, 'erase']);
        add_filter('woocommerce_debug_tools', [self::class, 'debugTools']);
    }

    /** @return array<string, string> label => value */
    public static function facts(): array
    {
        $store = ClientFactory::store();
        $c = $store->counts();
        $facts = [
            __('Environment', 'montefiskal-woocommerce') => Settings::isProduction() ? __('Production', 'montefiskal-woocommerce') : __('Test (efitest)', 'montefiskal-woocommerce'),
            __('Configured', 'montefiskal-woocommerce') => Settings::isConfigured() ? __('yes', 'montefiskal-woocommerce') : __('no', 'montefiskal-woocommerce'),
            __('Schema revision', 'montefiskal-woocommerce') => SchemaSet::REVISION,
            __('Waiting to be sent', 'montefiskal-woocommerce') => (string) $c['pending'],
            __('Overdue (more than 48 hours)', 'montefiskal-woocommerce') => (string) $c['overdue'],
            __('Rejected', 'montefiskal-woocommerce') => (string) $c['rejected'],
            __('Oldest unsent', 'montefiskal-woocommerce') => $c['oldest'] !== null ? $c['oldest'] . ' UTC (' . human_time_diff((int) strtotime($c['oldest'] . ' UTC')) . ')' : '–',
            __('Last error', 'montefiskal-woocommerce') => $store->lastError() ?? '–',
            __('Certificate source', 'montefiskal-woocommerce') => ClientFactory::certificateSource(),
            __('Daily INITIAL deposit registered today', 'montefiskal-woocommerce') => Queue::initialRegisteredToday(new DateTimeImmutable()) ? __('yes', 'montefiskal-woocommerce') : __('no', 'montefiskal-woocommerce'),
            __('Archive folder', 'montefiskal-woocommerce') => Settings::archiveDirectory(),
        ];
        try {
            $cert = ClientFactory::certificates()->certificate();
            $info = $cert->info();
            $facts[__('Certificate valid until', 'montefiskal-woocommerce')] = gmdate('Y-m-d', (int) ($info['validTo_time_t'] ?? 0));
            $checks = CertificateChecks::inspect($cert, (string) Settings::get('issuer_tin'), new DateTimeImmutable());
            $facts[__('Certificate checks', 'montefiskal-woocommerce')] = implode(' ', array_merge($checks['errors'], $checks['warnings'])) ?: __('passed', 'montefiskal-woocommerce');
        } catch (\Throwable $e) {
            $facts[__('Certificate', 'montefiskal-woocommerce')] = $e->getMessage();
        }
        return $facts;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        echo '<div class="wrap montefiskal"><h1>' . esc_html__('Fiscal status', 'montefiskal-woocommerce') . '</h1><table class="widefat striped"><tbody>';
        foreach (self::facts() as $k => $v) {
            echo '<tr><th>' . esc_html($k) . '</th><td>' . esc_html($v) . '</td></tr>';
        }
        echo '</tbody></table><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('montefiskal_resend');
        echo '<input type="hidden" name="action" value="montefiskal_resend">';
        submit_button(__('Send pending invoices now', 'montefiskal-woocommerce'), 'primary');
        echo '</form>';
        echo '<h2>' . esc_html__('Delete fiscal records', 'montefiskal-woocommerce') . '</h2><p>' . esc_html__('Invoices, corrective invoices and cash deposits must be kept at least five years after the end of the year they relate to (BR-X1). Deleting them earlier can breach that duty. This removes the MonteFiskal tables and settings of this site; the archive folder is left in place.', 'montefiskal-woocommerce') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('montefiskal_erase');
        printf('<input type="hidden" name="action" value="montefiskal_erase"><p><label>%s <input type="text" name="confirm" class="regular-text" autocomplete="off"></label></p>', esc_html(sprintf(/* translators: %s: confirmation phrase */ __('Type %s to confirm:', 'montefiskal-woocommerce'), self::ERASE_PHRASE)));
        submit_button(__('Delete fiscal records', 'montefiskal-woocommerce'), 'delete');
        echo '</form></div>';
    }

    public static function resend(): void
    {
        check_admin_referer('montefiskal_resend');
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'montefiskal-woocommerce'), 403);
        }
        Queue::runResend();
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    public static function erase(): void
    {
        check_admin_referer('montefiskal_erase');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'montefiskal-woocommerce'), 403);
        }
        if (trim((string) wp_unslash($_POST['confirm'] ?? '')) !== self::ERASE_PHRASE) {
            wp_die(esc_html__('The confirmation phrase did not match; nothing was deleted.', 'montefiskal-woocommerce'));
        }
        self::dropAll();
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    public static function dropAll(): void
    {
        global $wpdb;
        foreach (Install::tables() as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL -- table names from the site prefix
        }
        foreach ([Settings::OPTION, Install::DB_OPTION, 'montefiskal_notices', 'montefiskal_cert_path', 'montefiskal_cert_password', 'montefiskal_sep_confirmed'] as $o) {
            delete_option($o);
        }
        Queue::unscheduleAll();
    }

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function debugTools(array $tools): array
    {
        $tools['montefiskal_resend'] = [
            'name' => __('MonteFiskal: send pending invoices', 'montefiskal-woocommerce'),
            'button' => __('Send now', 'montefiskal-woocommerce'),
            'desc' => __('Runs one resend pass of the fiscal queue.', 'montefiskal-woocommerce'),
            'callback' => static function (): string {
                Queue::runResend();
                return __('Resend pass finished.', 'montefiskal-woocommerce');
            },
        ];
        return $tools;
    }
}
