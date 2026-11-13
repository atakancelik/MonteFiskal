<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Admin;

use MonteFiskal\Model\InvoiceState;

defined('ABSPATH') || exit;

final class StatusLabels
{
    public static function state(InvoiceState $s): string
    {
        return match ($s) {
            InvoiceState::Issued => __('Issued, not sent yet', 'montefiskal-woocommerce'),
            InvoiceState::Pending => __('Waiting to be sent', 'montefiskal-woocommerce'),
            InvoiceState::Overdue => __('Overdue (more than 48 hours)', 'montefiskal-woocommerce'),
            InvoiceState::Fiscalized => __('Fiscalized', 'montefiskal-woocommerce'),
            InvoiceState::Rejected => __('Rejected', 'montefiskal-woocommerce'),
        };
    }
}
