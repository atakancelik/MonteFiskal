<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce;

use MonteFiskal\WooCommerce\Admin\Notices;
use MonteFiskal\WooCommerce\Admin\OrderPanel;
use MonteFiskal\WooCommerce\Admin\OrdersList;
use MonteFiskal\WooCommerce\Admin\ReportsPage;
use MonteFiskal\WooCommerce\Admin\SettingsPage;
use MonteFiskal\WooCommerce\Admin\SetupWizard;
use MonteFiskal\WooCommerce\Admin\StatusPage;
use MonteFiskal\WooCommerce\Cli\Command;
use MonteFiskal\WooCommerce\Frontend\BuyerFields;
use MonteFiskal\WooCommerce\Frontend\CustomerOutput;
use MonteFiskal\WooCommerce\Orders\Invoicing;
use MonteFiskal\WooCommerce\Privacy\Privacy;
use MonteFiskal\WooCommerce\Queue\Queue;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?self $instance = null;

    private function __construct(public readonly string $file)
    {
    }

    public static function instance(string $file = MONTEFISKAL_WC_FILE): self
    {
        return self::$instance ??= new self($file);
    }

    public function url(string $path = ''): string
    {
        return plugins_url($path, $this->file);
    }

    public function dir(string $path = ''): string
    {
        return dirname($this->file) . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public function register(): void
    {
        add_action('init', function (): void {
            load_plugin_textdomain('montefiskal-woocommerce', false, dirname(plugin_basename($this->file)) . '/languages');
        });
        Install::maybeUpgrade();
        Queue::register();
        Invoicing::register();
        BuyerFields::register();
        CustomerOutput::register();
        Privacy::register();
        if (is_admin()) {
            Notices::register();
            SettingsPage::register();
            SetupWizard::register();
            StatusPage::register();
            ReportsPage::register();
            OrderPanel::register();
            OrdersList::register();
            Updates::register();
            add_filter('plugin_action_links_' . plugin_basename($this->file), [$this, 'actionLinks']);
        }
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('montefiskal', Command::class);
        }
        add_action('update_option_' . Settings::OPTION, static function (): void {
            ClientFactory::reset();
            Queue::scheduleRecurring();
        });
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function actionLinks(array $links): array
    {
        $links['settings'] = '<a href="' . esc_url(admin_url('admin.php?page=montefiskal')) . '">' . esc_html__('Settings', 'montefiskal-woocommerce') . '</a>';
        return $links;
    }
}
