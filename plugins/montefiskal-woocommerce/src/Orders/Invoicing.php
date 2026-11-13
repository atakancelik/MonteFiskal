<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Orders;

use MonteFiskal\Calculation\Dec;
use MonteFiskal\CorrectionRequest;
use MonteFiskal\IssuedInvoice;
use MonteFiskal\Model\CorrectionType;
use MonteFiskal\Model\InvoiceKind;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Model\Payment;
use MonteFiskal\Model\PaymentMethod;
use MonteFiskal\Outbox\StoredRequest;
use MonteFiskal\Validation\FiscalValidationException;
use MonteFiskal\WooCommerce\Admin\Notices;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Queue\Queue;
use MonteFiskal\WooCommerce\Settings;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * When and what to fiscalize for an order: the invoice (single mode), the advance flow (BR-K9), and corrective
 * invoices for refunds (BR-C2, BR-C3, BR-C5). Issuing is local and synchronous; sending is always queued.
 */
final class Invoicing
{
    public const META_ID = '_montefiskal_invoice_id';
    public const META_STATE = '_montefiskal_state';

    public const ROLE_INVOICE = 'invoice';
    public const ROLE_ADVANCE = 'advance';
    public const ROLE_ADVANCE_CORRECTIVE = 'advance_corrective';
    public const ROLE_ERROR_CORRECTIVE = 'error_corrective';

    public static function register(): void
    {
        $trigger = (string) Settings::get('trigger');
        if ($trigger === 'payment_complete') {
            add_action('woocommerce_payment_complete', [self::class, 'onTrigger'], 20);
            // Gateways that never call payment_complete (bank transfer, cash on delivery) reach "processing" or "completed" by hand.
            add_action('woocommerce_order_status_processing', [self::class, 'onTriggerIfPaid'], 20);
            add_action('woocommerce_order_status_completed', [self::class, 'onTriggerIfPaid'], 20);
        } elseif ($trigger !== 'manual') {
            add_action('woocommerce_order_status_' . sanitize_key($trigger), [self::class, 'onTrigger'], 20);
        }
        if (Settings::get('mode') === Settings::MODE_ADVANCE) {
            add_action('woocommerce_order_status_' . sanitize_key((string) Settings::get('delivery_status')), [self::class, 'onDelivered'], 25);
        }
        add_action('woocommerce_order_refunded', [self::class, 'onRefunded'], 20, 2);
    }

    public static function onTrigger(int|string $orderId): void
    {
        $order = wc_get_order((int) $orderId);
        if ($order instanceof WC_Order) {
            self::issueForOrder($order);
        }
    }

    public static function onTriggerIfPaid(int|string $orderId): void
    {
        $order = wc_get_order((int) $orderId);
        if ($order instanceof WC_Order && ($order->is_paid() || $order->get_date_paid() !== null)) {
            self::issueForOrder($order);
        }
    }

    /** Issues the first fiscal document of an order once. Returns the request ID or null. */
    public static function issueForOrder(WC_Order $order): ?string
    {
        if (!Settings::isConfigured()) {
            Notices::add('not_configured', __('MonteFiskal is not configured yet; orders are not being fiscalized.', 'montefiskal-woocommerce'));
            return null;
        }
        $advance = Settings::get('mode') === Settings::MODE_ADVANCE;
        $role = $advance ? self::ROLE_ADVANCE : self::ROLE_INVOICE;
        $existing = self::document($order, $role) ?? self::document($order, self::ROLE_INVOICE);
        if ($existing !== null) {
            return $existing->id;
        }
        if (!OrderMapper::isMapped($order)) {
            Notices::add('unmapped_' . $order->get_payment_method(), sprintf(
                /* translators: %s: payment gateway ID */
                __('Orders paid with "%s" are not fiscalized: map the gateway to a payment method in MonteFiskal settings.', 'montefiskal-woocommerce'),
                $order->get_payment_method()
            ));
            $order->add_order_note(__('MonteFiskal: no invoice issued, the payment gateway is not mapped.', 'montefiskal-woocommerce'));
            return null;
        }
        if (OrderMapper::paymentMethod($order) === null) {
            return null; // Mapped to "do not fiscalize".
        }
        try {
            $draft = OrderMapper::draft($order, $advance ? InvoiceKind::Advance : InvoiceKind::Invoice);
            $issued = self::issue($order, $role, static fn () => ClientFactory::client()->issueInvoice($draft));
        } catch (FiscalValidationException $e) {
            self::reportValidation($order, $e);
            return null;
        }
        return $issued->id;
    }

    /** Advance flow, step 2 and 3 of BR-K9: the invoice paid by the advance, then the corrective of the advance. */
    public static function onDelivered(int|string $orderId): void
    {
        $order = wc_get_order((int) $orderId);
        if ($order instanceof WC_Order) {
            self::deliver($order);
        }
    }

    public static function deliver(WC_Order $order): ?string
    {
        $advance = self::document($order, self::ROLE_ADVANCE);
        if ($advance === null || self::document($order, self::ROLE_INVOICE) !== null || $advance->iic === null) {
            return null;
        }
        $refunded = Dec::of(OrderMapper::dec($order->get_total_refunded()));
        $value = Dec::sub(OrderMapper::dec($order->get_total()), $refunded);
        try {
            // BR-K9 (2): a normal invoice whose PayMethod is ADVANCE with the amount and AdvIIC = IKOF of the advance invoice.
            $draft = OrderMapper::draft($order, InvoiceKind::Invoice, [new Payment(PaymentMethod::Advance, $value, advanceIic: $advance->iic)]);
            $issued = self::issue($order, self::ROLE_INVOICE, static fn () => ClientFactory::client()->issueInvoice($draft));
            // BR-K9 (3): a corrective invoice that reduces the advance invoice by the delivered value.
            self::issue($order, self::ROLE_ADVANCE_CORRECTIVE, static fn () => ClientFactory::client()->issueCorrective(new CorrectionRequest($advance->id)));
        } catch (FiscalValidationException $e) {
            self::reportValidation($order, $e);
            return null;
        }
        return $issued->id;
    }

    /**
     * A full refund gives a full corrective invoice (BR-C2); a partial refund a corrective of the refunded lines (BR-C5).
     * The corrective waits in the queue until the original has a JIKR (BR-C3).
     */
    public static function onRefunded(int|string $orderId, int|string $refundId): void
    {
        $order = wc_get_order((int) $orderId);
        $refund = wc_get_order((int) $refundId);
        if ($order instanceof WC_Order && $refund instanceof \WC_Order_Refund) {
            self::refund($order, $refund);
        }
    }

    public static function refund(WC_Order $order, \WC_Order_Refund $refund): ?string
    {
        $role = 'refund:' . $refund->get_id();
        $done = self::document($order, $role);
        if ($done !== null) {
            return $done->id;
        }
        $original = self::document($order, self::ROLE_INVOICE) ?? self::document($order, self::ROLE_ADVANCE);
        if ($original === null) {
            return null;
        }
        $amount = OrderMapper::dec($refund->get_amount());
        $isFull = Dec::cmp($amount, OrderMapper::dec($order->get_total())) === 0 && count($order->get_refunds()) === 1;
        $method = OrderMapper::paymentMethod($order) ?? PaymentMethod::Other;
        try {
            if ($isFull) {
                $request = new CorrectionRequest($original->id);
            } else {
                $lines = OrderMapper::refundLines($refund);
                if ($lines === []) {
                    $order->add_order_note(__('MonteFiskal: this refund has no refunded items, shipping or fees, so no corrective invoice was issued. Refund by line items, or issue the corrective invoice by hand.', 'montefiskal-woocommerce'));
                    Notices::add('refund_' . $refund->get_id(), sprintf(
                        /* translators: %d: order number */
                        __('Order %d: a refund without line items needs a corrective invoice issued by hand.', 'montefiskal-woocommerce'),
                        $order->get_id()
                    ));
                    return null;
                }
                $paid = $original->kind === InvoiceKind::Invoice && self::document($order, self::ROLE_ADVANCE) !== null ? PaymentMethod::Advance : $method;
                $request = new CorrectionRequest($original->id, CorrectionType::Corrective, $lines, [new Payment($paid, Dec::neg($amount), advanceIic: $paid === PaymentMethod::Advance ? self::document($order, self::ROLE_ADVANCE)?->iic : null)]);
            }
            $issued = self::issue($order, $role, static fn () => ClientFactory::client()->issueCorrective($request));
        } catch (FiscalValidationException $e) {
            self::reportValidation($order, $e);
            return null;
        }
        return $issued->id;
    }

    /** ERROR_CORRECTIVE: all items again with the correct values (BR-C4), taken from the order as it is now. */
    public static function errorCorrective(WC_Order $order): ?string
    {
        $original = self::document($order, self::ROLE_INVOICE) ?? self::document($order, self::ROLE_ADVANCE);
        if ($original === null) {
            return null;
        }
        try {
            $draft = OrderMapper::draft($order);
            $issued = self::issue($order, self::ROLE_ERROR_CORRECTIVE . ':' . time(), static fn () => ClientFactory::client()->issueCorrective(new CorrectionRequest($original->id, CorrectionType::ErrorCorrective, $draft->lines, $draft->payments)));
        } catch (FiscalValidationException $e) {
            self::reportValidation($order, $e);
            return null;
        }
        return $issued->id;
    }

    /** @param callable(): IssuedInvoice $issue */
    private static function issue(WC_Order $order, string $role, callable $issue): IssuedInvoice
    {
        $store = ClientFactory::store();
        $store->setContext($order->get_id(), $role);
        try {
            $issued = $issue();
        } finally {
            $store->setContext(null, null);
        }
        if (in_array($role, [self::ROLE_INVOICE, self::ROLE_ADVANCE], true)) {
            $order->update_meta_data(self::META_ID, $issued->id);
        }
        $order->update_meta_data(self::META_STATE, $issued->state->value);
        $order->add_order_note(sprintf(
            /* translators: 1: document role, 2: invoice number, 3: IKOF */
            __('MonteFiskal: %1$s %2$s issued, IKOF %3$s. Sending is queued.', 'montefiskal-woocommerce'),
            self::roleLabel($role),
            $issued->number,
            $issued->iic
        ));
        foreach ($issued->warnings as $w) {
            $order->add_order_note('MonteFiskal: ' . (string) $w);
        }
        $order->save();
        Queue::send($issued->id);
        return $issued;
    }

    public static function roleLabel(string $role): string
    {
        return match (true) {
            $role === self::ROLE_INVOICE => __('invoice', 'montefiskal-woocommerce'),
            $role === self::ROLE_ADVANCE => __('advance invoice', 'montefiskal-woocommerce'),
            $role === self::ROLE_ADVANCE_CORRECTIVE => __('corrective of the advance invoice', 'montefiskal-woocommerce'),
            str_starts_with($role, 'refund:') => __('corrective invoice for the refund', 'montefiskal-woocommerce'),
            str_starts_with($role, self::ROLE_ERROR_CORRECTIVE) => __('error-corrective invoice', 'montefiskal-woocommerce'),
            default => $role,
        };
    }

    public static function document(WC_Order $order, string $role): ?StoredRequest
    {
        $docs = ClientFactory::store()->forOrder($order->get_id(), $role);
        return $docs[0] ?? null;
    }

    /** Keeps the order meta used by the orders-list column and filter in step with the fiscal record. */
    public static function syncOrder(StoredRequest $stored): void
    {
        $link = ClientFactory::store()->link($stored->id);
        if ($link === null || $link['order_id'] === null) {
            return;
        }
        $order = wc_get_order($link['order_id']);
        if (!$order instanceof WC_Order) {
            return;
        }
        $states = array_map(static fn (StoredRequest $s): InvoiceState => $s->state, ClientFactory::store()->forOrder($order->get_id()));
        // The order shows the worst state of its documents.
        $worst = InvoiceState::Fiscalized;
        foreach ([InvoiceState::Rejected, InvoiceState::Overdue, InvoiceState::Pending, InvoiceState::Issued] as $s) {
            if (in_array($s, $states, true)) {
                $worst = $s;
                break;
            }
        }
        $order->update_meta_data(self::META_STATE, $worst->value);
        if ($stored->state === InvoiceState::Fiscalized && $stored->result !== null) {
            $order->add_order_note(sprintf(
                /* translators: 1: invoice number, 2: JIKR */
                __('MonteFiskal: %1$s fiscalized, JIKR %2$s.', 'montefiskal-woocommerce'),
                (string) $stored->invoiceNumber,
                $stored->result
            ));
        } elseif ($stored->state === InvoiceState::Rejected) {
            $order->add_order_note(sprintf(
                /* translators: 1: invoice number, 2: error code, 3: message */
                __('MonteFiskal: %1$s rejected by the tax administration (code %2$s): %3$s', 'montefiskal-woocommerce'),
                (string) $stored->invoiceNumber,
                (string) ($stored->lastError?->code ?? ''),
                (string) ($stored->lastError?->faultString ?? '')
            ));
        }
        $order->save();
    }

    private static function reportValidation(WC_Order $order, FiscalValidationException $e): void
    {
        $messages = [];
        foreach ($e->result->errors() as $f) {
            $messages[] = $f->rule . ': ' . $f->message;
        }
        $order->add_order_note(__('MonteFiskal: the invoice was not issued because it is not valid.', 'montefiskal-woocommerce') . ' ' . implode(' | ', $messages));
        Notices::add('invalid_' . $order->get_id(), sprintf(
            /* translators: %d: order ID */
            __('Order %d could not be fiscalized; see the order notes.', 'montefiskal-woocommerce'),
            $order->get_id()
        ));
    }
}
