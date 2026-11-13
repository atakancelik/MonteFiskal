<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Privacy;

use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Settings;

defined('ABSPATH') || exit;

/**
 * Personal-data exporter and eraser. Fiscal records are exempt from erasure during the retention period (BR-X1);
 * the eraser reports that instead of deleting them.
 */
final class Privacy
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
        add_action('admin_init', [self::class, 'policy']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function exporters(array $exporters): array
    {
        $exporters['montefiskal'] = ['exporter_friendly_name' => __('Fiscal invoices (MonteFiskal)', 'montefiskal-woocommerce'), 'callback' => [self::class, 'export']];
        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function erasers(array $erasers): array
    {
        $erasers['montefiskal'] = ['eraser_friendly_name' => __('Fiscal invoices (MonteFiskal)', 'montefiskal-woocommerce'), 'callback' => [self::class, 'erase']];
        return $erasers;
    }

    /** @return list<\WC_Order> */
    private static function orders(string $email, int $page): array
    {
        $orders = wc_get_orders(['billing_email' => $email, 'limit' => 20, 'page' => $page, 'type' => 'shop_order']);
        return array_values(array_filter(is_array($orders) ? $orders : [], static fn ($o): bool => $o instanceof \WC_Order));
    }

    /** @return array{data: list<array<string, mixed>>, done: bool} */
    public static function export(string $email, int $page = 1): array
    {
        $data = [];
        $orders = self::orders($email, $page);
        foreach ($orders as $order) {
            foreach (ClientFactory::store()->forOrder($order->get_id()) as $doc) {
                if ($doc->iic === null) {
                    continue;
                }
                $data[] = [
                    'group_id' => 'montefiskal',
                    'group_label' => __('Fiscal invoices', 'montefiskal-woocommerce'),
                    'item_id' => 'montefiskal-' . $doc->id,
                    'data' => [
                        ['name' => __('Order', 'montefiskal-woocommerce'), 'value' => (string) $order->get_order_number()],
                        ['name' => __('Invoice number', 'montefiskal-woocommerce'), 'value' => (string) $doc->invoiceNumber],
                        ['name' => 'IKOF', 'value' => $doc->iic],
                        ['name' => 'JIKR', 'value' => (string) $doc->result],
                        ['name' => __('Issued', 'montefiskal-woocommerce'), 'value' => (string) $doc->issueDateTime],
                        ['name' => __('Total', 'montefiskal-woocommerce'), 'value' => (string) $doc->total],
                    ],
                ];
            }
        }
        return ['data' => $data, 'done' => count($orders) < 20];
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
    public static function erase(string $email, int $page = 1): array
    {
        $orders = self::orders($email, $page);
        $retained = false;
        foreach ($orders as $order) {
            if (ClientFactory::store()->forOrder($order->get_id()) !== []) {
                $retained = true;
            }
        }
        $messages = $retained ? [sprintf(
            /* translators: %d: years */
            __('Fiscal invoices were retained: the law requires them to be kept (BR-X1); this site keeps them for %d years.', 'montefiskal-woocommerce'),
            (int) Settings::get('retention_years')
        )] : [];
        return ['items_removed' => false, 'items_retained' => $retained, 'messages' => $messages, 'done' => count($orders) < 20];
    }

    public static function policy(): void
    {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content('MonteFiskal', wp_kses_post('<p>' . __('When you buy from this shop, the invoice is registered with the Tax Administration of Montenegro (fiscalization). For a company purchase the invoice contains the company name, tax number and address. Fiscal invoices are kept for the period the law requires, also when you ask for your data to be erased.', 'montefiskal-woocommerce') . '</p>'));
        }
    }
}
