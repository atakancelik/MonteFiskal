<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use MonteFiskal\Outbox\StoredRequest;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Orders\Invoicing;
use MonteFiskal\WooCommerce\Queue\Queue;
use WC_Order;

defined('ABSPATH') || exit;

/** Order screen panel: state, codes, attempts and actions (send now, corrective, error-corrective). */
final class OrderPanel
{
    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'addBox']);
        add_filter('woocommerce_order_actions', [self::class, 'orderActions'], 10, 2);
        add_action('woocommerce_order_action_montefiskal_issue', [self::class, 'actionIssue']);
        add_action('woocommerce_order_action_montefiskal_send', [self::class, 'actionSend']);
        add_action('woocommerce_order_action_montefiskal_deliver', [self::class, 'actionDeliver']);
        add_action('woocommerce_order_action_montefiskal_corrective', [self::class, 'actionCorrective']);
        add_action('woocommerce_order_action_montefiskal_error_corrective', [self::class, 'actionErrorCorrective']);
    }

    public static function screen(): string
    {
        if (
            class_exists(CustomOrdersTableController::class) && function_exists('wc_get_container')
            && wc_get_container()->get(CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
        ) {
            return wc_get_page_screen_id('shop-order');
        }
        return 'shop_order';
    }

    public static function addBox(): void
    {
        add_meta_box('montefiskal-order', __('Fiscalization (MonteFiskal)', 'montefiskal-woocommerce'), [self::class, 'render'], self::screen(), 'side', 'high');
    }

    public static function render(mixed $postOrOrder): void
    {
        $order = $postOrOrder instanceof WC_Order ? $postOrOrder : wc_get_order($postOrOrder instanceof \WP_Post ? $postOrOrder->ID : 0);
        if (!$order instanceof WC_Order) {
            return;
        }
        $docs = ClientFactory::store()->forOrder($order->get_id());
        if ($docs === []) {
            echo '<p>' . esc_html__('No fiscal document yet.', 'montefiskal-woocommerce') . '</p>';
            return;
        }
        foreach ($docs as $d) {
            self::document($d);
        }
    }

    private static function document(StoredRequest $d): void
    {
        $link = ClientFactory::store()->link($d->id);
        echo '<div class="montefiskal-doc"><p><strong>' . esc_html(Invoicing::roleLabel((string) ($link['order_role'] ?? ''))) . '</strong><br>';
        echo '<span class="montefiskal-state montefiskal-state-' . esc_attr(strtolower($d->state->value)) . '">' . esc_html(StatusLabels::state($d->state)) . '</span></p><dl>';
        $rows = [
            __('Number', 'montefiskal-woocommerce') => $d->invoiceNumber,
            'IKOF' => $d->iic,
            'JIKR' => $d->result,
            __('Type', 'montefiskal-woocommerce') => $d->typeOfInv?->value,
            __('Total', 'montefiskal-woocommerce') => $d->total,
            __('Attempts', 'montefiskal-woocommerce') => (string) count($d->attempts),
        ];
        foreach ($rows as $k => $v) {
            if ($v !== null && $v !== '') {
                echo '<dt>' . esc_html($k) . '</dt><dd><code>' . esc_html((string) $v) . '</code></dd>';
            }
        }
        echo '</dl>';
        if ($d->lastError !== null) {
            echo '<p class="montefiskal-error">' . esc_html(trim(($d->lastError->code !== null ? $d->lastError->code . ': ' : '') . $d->lastError->faultString . ' ' . (string) $d->lastError->hint)) . '</p>';
        }
        if ($d->verificationUrl !== null) {
            echo '<p><a href="' . esc_url($d->verificationUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Verification page', 'montefiskal-woocommerce') . '</a></p>';
        }
        echo '</div>';
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function orderActions(array $actions, mixed $order = null): array
    {
        $actions['montefiskal_issue'] = __('MonteFiskal: issue invoice', 'montefiskal-woocommerce');
        $actions['montefiskal_send'] = __('MonteFiskal: send now', 'montefiskal-woocommerce');
        $actions['montefiskal_deliver'] = __('MonteFiskal: issue final invoice (advance flow)', 'montefiskal-woocommerce');
        $actions['montefiskal_corrective'] = __('MonteFiskal: issue full corrective invoice', 'montefiskal-woocommerce');
        $actions['montefiskal_error_corrective'] = __('MonteFiskal: issue error-corrective invoice from the current order', 'montefiskal-woocommerce');
        return $actions;
    }

    public static function actionIssue(WC_Order $order): void
    {
        Invoicing::issueForOrder($order);
    }

    public static function actionSend(WC_Order $order): void
    {
        foreach (ClientFactory::store()->forOrder($order->get_id()) as $d) {
            if ($d->isOpen()) {
                Queue::runSend($d->id);
            }
        }
    }

    public static function actionDeliver(WC_Order $order): void
    {
        Invoicing::deliver($order);
    }

    public static function actionCorrective(WC_Order $order): void
    {
        $original = Invoicing::document($order, Invoicing::ROLE_INVOICE) ?? Invoicing::document($order, Invoicing::ROLE_ADVANCE);
        if ($original === null) {
            return;
        }
        $store = ClientFactory::store();
        $store->setContext($order->get_id(), 'corrective:' . time());
        try {
            $issued = ClientFactory::client()->issueCorrective(new \MonteFiskal\CorrectionRequest($original->id));
        } finally {
            $store->setContext(null, null);
        }
        $order->add_order_note(sprintf(/* translators: %s: invoice number */ __('MonteFiskal: full corrective invoice %s issued by hand.', 'montefiskal-woocommerce'), $issued->number));
        Queue::send($issued->id);
    }

    public static function actionErrorCorrective(WC_Order $order): void
    {
        Invoicing::errorCorrective($order);
    }
}
