<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Admin;

use MonteFiskal\Model\InvoiceState;
use MonteFiskal\WooCommerce\Orders\Invoicing;

defined('ABSPATH') || exit;

/** Orders-list column, filter and bulk action, for both the HPOS list and the legacy posts list. */
final class OrdersList
{
    public static function register(): void
    {
        // HPOS list.
        add_filter('manage_woocommerce_page_wc-orders_columns', [self::class, 'columns']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [self::class, 'cell'], 10, 2);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [self::class, 'filter']);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [self::class, 'hposQuery']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [self::class, 'bulkActions']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [self::class, 'handleBulk'], 10, 3);
        // Legacy list.
        add_filter('manage_edit-shop_order_columns', [self::class, 'columns']);
        add_action('manage_shop_order_posts_custom_column', [self::class, 'cell'], 10, 2);
        add_action('restrict_manage_posts', [self::class, 'legacyFilter']);
        add_action('pre_get_posts', [self::class, 'legacyQuery']);
        add_filter('bulk_actions-edit-shop_order', [self::class, 'bulkActions']);
        add_filter('handle_bulk_actions-edit-shop_order', [self::class, 'handleBulk'], 10, 3);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function columns(array $columns): array
    {
        $columns['montefiskal'] = __('Fiscal', 'montefiskal-woocommerce');
        return $columns;
    }

    public static function cell(string $column, mixed $orderOrId): void
    {
        if ($column !== 'montefiskal') {
            return;
        }
        $order = $orderOrId instanceof \WC_Order ? $orderOrId : wc_get_order((int) $orderOrId);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $state = InvoiceState::tryFrom((string) $order->get_meta(Invoicing::META_STATE));
        echo $state === null ? '–' : '<span class="montefiskal-state montefiskal-state-' . esc_attr(strtolower($state->value)) . '">' . esc_html(StatusLabels::state($state)) . '</span>';
    }

    private static function select(): void
    {
        $current = sanitize_text_field(wp_unslash((string) ($_GET['montefiskal_state'] ?? '')));
        echo '<select name="montefiskal_state"><option value="">' . esc_html__('All fiscal states', 'montefiskal-woocommerce') . '</option>';
        foreach (InvoiceState::cases() as $s) {
            printf('<option value="%s"%s>%s</option>', esc_attr($s->value), selected($current, $s->value, false), esc_html(StatusLabels::state($s)));
        }
        echo '</select>';
    }

    public static function filter(): void
    {
        self::select();
    }

    public static function legacyFilter(string $postType): void
    {
        if ($postType === 'shop_order') {
            self::select();
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function hposQuery(array $args): array
    {
        $state = InvoiceState::tryFrom(sanitize_text_field(wp_unslash((string) ($_GET['montefiskal_state'] ?? ''))));
        if ($state !== null) {
            $args['meta_query'] = array_merge((array) ($args['meta_query'] ?? []), [['key' => Invoicing::META_STATE, 'value' => $state->value]]);
        }
        return $args;
    }

    public static function legacyQuery(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'shop_order') {
            return;
        }
        $state = InvoiceState::tryFrom(sanitize_text_field(wp_unslash((string) ($_GET['montefiskal_state'] ?? ''))));
        if ($state !== null) {
            $query->set('meta_query', array_merge((array) $query->get('meta_query'), [['key' => Invoicing::META_STATE, 'value' => $state->value]]));
        }
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function bulkActions(array $actions): array
    {
        $actions['montefiskal_issue'] = __('MonteFiskal: issue invoices', 'montefiskal-woocommerce');
        return $actions;
    }

    /** @param list<int|string> $ids */
    public static function handleBulk(string $redirect, string $action, array $ids): string
    {
        if ($action !== 'montefiskal_issue' || !current_user_can('manage_woocommerce')) {
            return $redirect;
        }
        $n = 0;
        foreach ($ids as $id) {
            $order = wc_get_order((int) $id);
            if ($order instanceof \WC_Order && Invoicing::issueForOrder($order) !== null) {
                $n++;
            }
        }
        return add_query_arg('montefiskal_issued', $n, $redirect);
    }
}
