<?php
/**
 * Uninstall: fiscal tables and records are never deleted automatically (BR-X1). Only scheduled jobs are removed.
 * To delete fiscal data after the retention period, use WooCommerce > Fiscal status > Delete fiscal records.
 *
 * @package MonteFiskal\WooCommerce
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

if (function_exists('as_unschedule_all_actions')) {
    foreach (['montefiskal/resend_pending', 'montefiskal/deadline_watch', 'montefiskal/daily_cash_deposit', 'montefiskal/send'] as $montefiskal_hook) {
        as_unschedule_all_actions($montefiskal_hook, [], 'montefiskal');
    }
}
delete_option('montefiskal_notices');
