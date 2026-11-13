<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Frontend;

use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Outbox\StoredRequest;
use MonteFiskal\Rendering\HtmlReceipt;
use MonteFiskal\Rendering\QrCode;
use MonteFiskal\Rendering\QrImages;
use MonteFiskal\Rendering\Receipt;
use MonteFiskal\Transport\FiscalOperation;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Plugin;
use MonteFiskal\WooCommerce\Settings;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * What the buyer sees (BR-P4, Q-28): an e-mail block and an order-received / My Account block with number, IKOF,
 * JIKR, verification link and QR code, and a printable invoice that themes can override. Test mode is watermarked.
 */
final class CustomerOutput
{
    public const QUERY_VAR = 'montefiskal-invoice';
    /** Printed QR size in millimetres; BR-Q8 requires at least 21 mm x 21 mm. */
    public const QR_MM = 25;

    public static function register(): void
    {
        add_action('woocommerce_email_after_order_table', [self::class, 'email'], 20, 4);
        add_action('woocommerce_order_details_after_order_table', [self::class, 'orderDetails'], 20);
        add_action('template_redirect', [self::class, 'printable']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
        add_filter('woocommerce_my_account_my_orders_actions', [self::class, 'accountAction'], 10, 2);
    }

    /** @return list<StoredRequest> fiscal invoices of the order, oldest first */
    public static function documents(WC_Order $order): array
    {
        return array_values(array_filter(
            ClientFactory::store()->forOrder($order->get_id()),
            static fn (StoredRequest $s): bool => $s->operation === FiscalOperation::RegisterInvoice && $s->iic !== null
        ));
    }

    public static function email(mixed $order, bool $sentToAdmin = false, bool $plainText = false, mixed $email = null): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        foreach (self::documents($order) as $doc) {
            self::template('fiscal-block.php', ['doc' => $doc, 'order' => $order, 'plain_text' => $plainText, 'context' => 'email', 'qr' => self::qrPng($doc), 'print_url' => self::printUrl($order, $doc), 'test_mode' => !Settings::isProduction()]);
        }
    }

    public static function orderDetails(mixed $order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        foreach (self::documents($order) as $doc) {
            self::template('fiscal-block.php', ['doc' => $doc, 'order' => $order, 'plain_text' => false, 'context' => 'page', 'qr' => self::qrSvg($doc), 'print_url' => self::printUrl($order, $doc), 'test_mode' => !Settings::isProduction()]);
        }
    }

    /**
     * @param array<string, array{url: string, name: string}> $actions
     * @return array<string, array{url: string, name: string}>
     */
    public static function accountAction(array $actions, mixed $order): array
    {
        if ($order instanceof WC_Order) {
            $docs = self::documents($order);
            if ($docs !== []) {
                $actions['montefiskal-invoice'] = ['url' => self::printUrl($order, $docs[0]), 'name' => __('Fiscal invoice', 'montefiskal-woocommerce')];
            }
        }
        return $actions;
    }

    public static function printUrl(WC_Order $order, StoredRequest $doc): string
    {
        return add_query_arg([self::QUERY_VAR => $doc->id, 'key' => $order->get_order_key()], home_url('/'));
    }

    public static function qrSvg(StoredRequest $doc): string
    {
        return $doc->verificationUrl === null ? '' : QrImages::svg(QrCode::encode($doc->verificationUrl), self::QR_MM);
    }

    public static function qrPng(StoredRequest $doc): string
    {
        return $doc->verificationUrl === null ? '' : QrImages::pngDataUri(QrCode::encode($doc->verificationUrl), 6);
    }

    /** The printable invoice: the order key or the shop manager capability is required. */
    public static function printable(): void
    {
        $id = get_query_var(self::QUERY_VAR);
        if (!is_string($id) || $id === '') {
            $id = isset($_GET[self::QUERY_VAR]) ? sanitize_text_field(wp_unslash((string) $_GET[self::QUERY_VAR])) : '';
        }
        if ($id === '' || !preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return;
        }
        $html = self::printableHtml($id, isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '');
        if ($html === null) {
            wp_die(esc_html__('This invoice is not available.', 'montefiskal-woocommerce'), '', ['response' => 403]);
        }
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built by HtmlReceipt, which escapes every value
        exit;
    }

    public static function printableHtml(string $id, string $key): ?string
    {
        $doc = ClientFactory::store()->get($id);
        $link = ClientFactory::store()->link($id);
        if ($doc === null || $link === null || $link['order_id'] === null || $doc->operation !== FiscalOperation::RegisterInvoice) {
            return null;
        }
        $order = wc_get_order($link['order_id']);
        if (!$order instanceof WC_Order) {
            return null;
        }
        $allowed = current_user_can('manage_woocommerce')
            || ($key !== '' && hash_equals($order->get_order_key(), $key))
            || (get_current_user_id() !== 0 && $order->get_customer_id() === get_current_user_id());
        if (!$allowed) {
            return null;
        }
        $language = (string) apply_filters('montefiskal_invoice_language', 'me', $order);
        $html = HtmlReceipt::render(Receipt::fromStored($doc, !Settings::isProduction()), $language);
        if (Settings::get('print_mode') === 'receipt') {
            $html = str_replace('</style>', '@page{size:80mm auto;margin:3mm}body{margin:2mm;font-size:9pt;max-width:76mm}td,th{padding:1mm}</style>', $html);
        }
        ob_start();
        self::template('invoice.php', ['html' => $html, 'doc' => $doc, 'order' => $order]);
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $args */
    public static function template(string $name, array $args): void
    {
        wc_get_template('montefiskal/' . $name, $args, '', Plugin::instance()->dir('templates') . '/');
    }

    public static function stateText(StoredRequest $doc): string
    {
        return $doc->state === InvoiceState::Fiscalized
            ? __('Fiscalized', 'montefiskal-woocommerce')
            : __('Not yet registered with the tax administration; it will be sent automatically.', 'montefiskal-woocommerce');
    }
}
