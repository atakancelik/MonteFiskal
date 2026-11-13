<?php

/**
 * Plugin Name:          MonteFiskal for WooCommerce
 * Plugin URI:           https://github.com/atakancelik/MonteFiskal
 * Description:          Fiscalizes WooCommerce orders with the Montenegrin EFI service: invoices, corrective invoices for refunds, daily cash deposits, QR codes and inspector reports.
 * Version:              0.1.0
 * Requires at least:    7.1
 * Requires PHP:         8.5
 * Requires Plugins:     woocommerce
 * WC requires at least: 11.3
 * WC tested up to:      11.3
 * Author:               MonteFiskal contributors
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          montefiskal-woocommerce
 * Domain Path:          /languages
 * Update URI:           https://github.com/atakancelik/MonteFiskal
 *
 * @package MonteFiskal\WooCommerce
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const MONTEFISKAL_WC_VERSION = '0.1.0';
const MONTEFISKAL_WC_FILE = __FILE__;

require_once __DIR__ . '/src/bootstrap.php';

MonteFiskal\WooCommerce\Bootstrap::load(__FILE__);
