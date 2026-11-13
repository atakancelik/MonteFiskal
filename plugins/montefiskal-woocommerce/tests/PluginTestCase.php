<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Tests;

use MonteFiskal\Security\StaticCertificateProvider;
use MonteFiskal\Testing\FakeEfiEndpoint;
use MonteFiskal\Testing\TestCertificateFactory;
use MonteFiskal\WooCommerce\Admin\Notices;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Install;
use MonteFiskal\WooCommerce\Queue\Queue;
use MonteFiskal\WooCommerce\Settings;
use PHPUnit\Framework\TestCase;

abstract class PluginTestCase extends TestCase
{
    protected static ?FakeEfiEndpoint $fake = null;
    private static ?\MonteFiskal\Security\Certificate $certificate = null;

    protected function setUp(): void
    {
        global $wpdb;
        self::$certificate ??= TestCertificateFactory::create();
        self::$fake = new FakeEfiEndpoint();
        remove_all_filters('montefiskal_transport');
        remove_all_filters('montefiskal_certificate_provider');
        add_filter('montefiskal_transport', static fn () => self::$fake);
        add_filter('montefiskal_certificate_provider', static fn () => new StaticCertificateProvider(self::$certificate));
        foreach (Install::tables() as $t) {
            $wpdb->query("TRUNCATE TABLE {$t}");
        }
        delete_option('montefiskal_notices');
        $archive = sys_get_temp_dir() . '/montefiskal-plugin-archive-' . getmypid();
        update_option(Settings::OPTION, array_replace(Settings::defaults(), [
            'issuer_tin' => '12345678',
            'issuer_name' => 'MonteFiskal Test Only DOO',
            'issuer_address' => 'Test Street 1',
            'issuer_town' => 'Podgorica',
            'issuer_in_vat' => true,
            'business_unit' => 'bb123bb123',
            'tcr' => 'cc123cc123',
            'tcr_internal_id' => 'webshop-1',
            'software' => 'ss123ss123',
            'operator' => 'oo123oo123',
            'maintainer' => 'mm123mm123',
            'payment_map' => ['stripe' => 'CARD', 'bacs' => 'ACCOUNT', 'cod' => 'BANKNOTE', 'cheque' => Settings::SKIP],
            'archive_dir' => $archive,
        ]), false);
        ClientFactory::reset();
        update_option('woocommerce_calc_taxes', 'yes');
        update_option('woocommerce_prices_include_tax', 'yes');
        update_option('woocommerce_tax_round_at_subtotal', 'no');
        self::ensureTaxRate();
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(Queue::SEND, [], Queue::GROUP);
            as_unschedule_all_actions(Queue::SEND);
        }
    }

    protected static function ensureTaxRate(): void
    {
        global $wpdb;
        $exists = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = '' AND tax_rate = '21.0000'");
        if ($exists === 0) {
            \WC_Tax::_insert_tax_rate([
                'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '21.0000', 'tax_rate_name' => 'PDV',
                'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '',
            ]);
        }
        if (!in_array('zero-rate', \WC_Tax::get_tax_class_slugs(), true)) {
            \WC_Tax::create_tax_class('Zero rate', 'zero-rate');
        }
    }

    protected static function product(string $price, string $taxClass = ''): \WC_Product_Simple
    {
        $p = new \WC_Product_Simple();
        $p->set_name('Test product ' . $price);
        $p->set_regular_price($price);
        $p->set_tax_class($taxClass);
        $p->set_sku('SKU-' . wp_generate_password(6, false));
        $p->save();
        return $p;
    }

    /** @param list<array{0: \WC_Product, 1: int}> $items */
    protected static function order(string $gateway, array $items, ?string $tin = null, string $company = ''): \WC_Order
    {
        $order = wc_create_order();
        foreach ($items as [$product, $qty]) {
            $order->add_product($product, $qty);
        }
        $order->set_address(['first_name' => 'Test', 'last_name' => 'Buyer', 'company' => $company, 'email' => 'buyer@example.test', 'address_1' => 'Test Street 2', 'city' => 'Budva', 'country' => 'ME'], 'billing');
        $order->set_payment_method($gateway);
        if ($tin !== null) {
            $order->update_meta_data('_wc_billing/montefiskal/tin', $tin);
        }
        $order->calculate_totals(true);
        $order->save();
        return $order;
    }

    /** Runs every queued montefiskal/send action once, like the Action Scheduler runner would. */
    protected static function drainQueue(int $rounds = 3): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            $actions = as_get_scheduled_actions(['hook' => Queue::SEND, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 100], 'ids');
            if ($actions === []) {
                return;
            }
            $store = \ActionScheduler::store();
            foreach ($actions as $actionId) {
                $action = $store->fetch_action($actionId);
                $store->mark_complete($actionId);
                Queue::runSend((string) $action->get_args()['id']);
            }
        }
    }

    /** @return list<\MonteFiskal\Outbox\StoredRequest> */
    protected static function docs(\WC_Order $order): array
    {
        return ClientFactory::store()->forOrder($order->get_id());
    }

    protected static function notices(): array
    {
        return Notices::all();
    }
}
