<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Orders;

use MonteFiskal\Calculation\Dec;
use MonteFiskal\Model\IdType;
use MonteFiskal\Model\InvoiceDraft;
use MonteFiskal\Model\InvoiceKind;
use MonteFiskal\Model\InvoiceLine;
use MonteFiskal\Model\Party;
use MonteFiskal\Model\Payment;
use MonteFiskal\Model\PaymentMethod;
use MonteFiskal\WooCommerce\Settings;
use WC_Abstract_Order;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Tax;

defined('ABSPATH') || exit;

/** Turns a WooCommerce order or refund into an invoice draft of the PHP core. Amounts stay decimal strings. */
final class OrderMapper
{
    public const TIN_FIELD = 'montefiskal/tin';
    public const TIN_META_CLASSIC = '_billing_montefiskal_tin';
    public const TIN_META_BLOCKS = '_wc_billing/montefiskal/tin';
    public const COMMERCIAL_CARD_META = '_montefiskal_commercial_card';

    /** The payment method for an order, or null when the gateway is mapped to "do not fiscalize" or not mapped. */
    public static function paymentMethod(WC_Order $order): ?PaymentMethod
    {
        $map = (array) Settings::get('payment_map');
        $gateway = $order->get_payment_method();
        $value = $map[$gateway] ?? null;
        if ($value === null || $value === '' || $value === Settings::SKIP) {
            return null;
        }
        $method = PaymentMethod::tryFrom((string) $value);
        if ($method === PaymentMethod::Card && self::isBusinessCard($order)) {
            // Q-29: BUSINESSCARD only if the admin enabled it, the buyer is a company with a tax number and the gateway reports a commercial card.
            return PaymentMethod::BusinessCard;
        }
        return $method;
    }

    public static function isMapped(WC_Order $order): bool
    {
        $map = (array) Settings::get('payment_map');
        return array_key_exists($order->get_payment_method(), $map) && $map[$order->get_payment_method()] !== '';
    }

    public static function isBusinessCard(WC_Order $order): bool
    {
        if (!(bool) Settings::get('business_card') || self::buyerTin($order) === null) {
            return false;
        }
        $reported = $order->get_meta(self::COMMERCIAL_CARD_META) === 'yes';
        return (bool) apply_filters('montefiskal_is_commercial_card', $reported, $order);
    }

    public static function buyerTin(WC_Abstract_Order $order): ?string
    {
        foreach ([self::TIN_META_BLOCKS, self::TIN_META_CLASSIC] as $key) {
            $v = preg_replace('/\s+/', '', (string) $order->get_meta($key));
            if ($v !== null && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    /** A buyer with a tax number produces a Buyer element; a consumer does not (architecture section 4, "Buyer data"). */
    public static function buyer(WC_Order $order): ?Party
    {
        $tin = self::buyerTin($order);
        if ($tin === null) {
            return null;
        }
        $name = trim($order->get_billing_company()) !== '' ? $order->get_billing_company() : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $address = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
        return new Party(
            idNum: $tin,
            name: $name,
            idType: IdType::Tin,
            address: $address !== '' ? $address : null,
            town: $order->get_billing_city() !== '' ? $order->get_billing_city() : null,
            country: self::alpha3($order->get_billing_country()),
        );
    }

    public static function alpha3(string $alpha2): ?string
    {
        static $map = null;
        $map ??= require __DIR__ . '/../Support/countries.php';
        return $map[strtoupper($alpha2)] ?? null;
    }

    /**
     * The draft for the order. Kind INVOICE in single mode, ADVANCE for the advance invoice of BR-K9.
     *
     * @param list<Payment>|null $payments null: one payment of the order total with the mapped method
     */
    public static function draft(WC_Order $order, InvoiceKind $kind = InvoiceKind::Invoice, ?array $payments = null): InvoiceDraft
    {
        $method = self::paymentMethod($order) ?? throw new UnmappedGateway($order->get_payment_method());
        $lines = self::lines($order, 1);
        $payments ??= [new Payment($method, self::dec($order->get_total()))];
        $draft = new InvoiceDraft(
            kind: $kind,
            buyer: self::buyer($order),
            lines: $lines,
            payments: $payments,
            note: null,
        );
        return apply_filters('montefiskal_invoice_draft', $draft, $order, $kind);
    }

    /**
     * Lines of a partial refund for a corrective invoice (BR-C5): negative quantities, positive unit prices (BR-C2).
     *
     * @return list<InvoiceLine>
     */
    public static function refundLines(\WC_Order_Refund $refund): array
    {
        return self::lines($refund, -1);
    }

    /** @return list<InvoiceLine> */
    private static function lines(WC_Abstract_Order $order, int $sign): array
    {
        $lines = [];
        foreach ($order->get_items(['line_item', 'shipping', 'fee']) as $item) {
            $line = self::line($item, $sign);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private static function line(WC_Order_Item $item, int $sign): ?InvoiceLine
    {
        $grossIncluded = wc_prices_include_tax();
        $net = self::dec($item->get_total());
        $tax = self::dec($item->get_total_tax());
        if ($item instanceof WC_Order_Item_Product) {
            $qty = Dec::of((string) abs((float) $item->get_quantity()));
            $product = $item->get_product();
            $code = $product !== null && $product !== false && $product->get_sku() !== '' ? $product->get_sku() : null;
            $unit = (string) apply_filters('montefiskal_item_unit', (string) Settings::get('default_unit'), $item);
        } elseif ($item instanceof WC_Order_Item_Shipping || $item instanceof WC_Order_Item_Fee) {
            $qty = '1';
            $code = null;
            $unit = (string) apply_filters('montefiskal_item_unit', (string) Settings::get('default_unit'), $item);
        } else {
            return null;
        }
        if (Dec::isZero($net) && Dec::isZero($tax)) {
            return null;
        }
        if (Dec::isZero($qty)) {
            // A refund of an amount without a quantity: one unit of the refunded value.
            $qty = '1';
        }
        $net = self::abs($net);
        $tax = self::abs($tax);
        [$rate, $exemption] = self::vat($item, $tax);
        $price = $grossIncluded ? Dec::div(Dec::add($net, $tax), $qty) : Dec::div($net, $qty);
        $name = $item->get_name() !== '' ? $item->get_name() : __('Item', 'montefiskal-woocommerce');
        return new InvoiceLine(
            name: mb_substr($name, 0, 50),
            unit: $unit,
            quantity: $sign < 0 ? Dec::neg($qty) : $qty,
            unitPrice: Dec::trim($price),
            priceIncludesVat: $grossIncluded,
            vatRate: $rate,
            exemption: $exemption,
            code: $code,
        );
    }

    /** @return array{0: string|null, 1: string|null} VAT rate and exemption code */
    private static function vat(WC_Order_Item $item, string $tax): array
    {
        if (!(bool) Settings::get('issuer_in_vat')) {
            return [null, null];
        }
        $taxes = method_exists($item, 'get_taxes') ? $item->get_taxes() : [];
        foreach (($taxes['total'] ?? []) as $rateId => $amount) {
            if ($amount !== '' && !Dec::isZero(self::dec($amount))) {
                return [Dec::trim(self::dec((string) WC_Tax::get_rate_percent_value((int) $rateId))), null];
            }
        }
        if (!Dec::isZero($tax)) {
            return [null, null];
        }
        $class = method_exists($item, 'get_tax_class') ? (string) $item->get_tax_class() : '';
        $map = (array) Settings::get('exemption_map');
        $exemption = $map[$class === '' ? 'standard' : $class] ?? null;
        // BR-V3: a line of a VAT seller carries either a rate or an exemption code, never both.
        return $exemption !== null && $exemption !== '' ? [null, (string) $exemption] : ['0', null];
    }

    public static function dec(mixed $value): string
    {
        $s = is_string($value) ? trim($value) : (is_int($value) ? (string) $value : wc_format_decimal((string) $value, false, true));
        if ($s === '' || !is_numeric($s)) {
            return '0';
        }
        // Floats from WooCommerce arrive with at most the store's decimals; format without exponent.
        if (str_contains(strtolower($s), 'e')) {
            $s = number_format((float) $s, 8, '.', '');
        }
        return Dec::of($s);
    }

    private static function abs(string $v): string
    {
        return Dec::cmp($v, '0') < 0 ? Dec::neg($v) : $v;
    }
}
