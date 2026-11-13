<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Orders;

defined('ABSPATH') || exit;

/** The order's gateway has no payment method mapping; no invoice is issued (architecture section 4, "Payment mapping"). */
final class UnmappedGateway extends \RuntimeException
{
    public function __construct(public readonly string $gateway)
    {
        parent::__construct(sprintf('The payment gateway "%s" is not mapped to an EFI payment method.', $gateway));
    }
}
