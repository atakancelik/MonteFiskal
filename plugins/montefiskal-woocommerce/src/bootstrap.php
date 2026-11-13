<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Finds the PHP core and the shared data files, registers the autoloader and starts the plugin.
 *
 * Release zip: lib/montefiskal/{src/php/montefiskal-core/src, data, schemas}, built by tools/plugin/build-zip.sh.
 * Repository checkout: the core two folders up (plugins/montefiskal-woocommerce -> repository root).
 */
final class Bootstrap
{
    private static bool $loaded = false;

    public static function coreRoot(string $pluginDir): ?string
    {
        foreach ([$pluginDir . '/lib/montefiskal', dirname($pluginDir, 2)] as $root) {
            if (is_file($root . '/src/php/montefiskal-core/src/FiscalClient.php') && is_dir($root . '/schemas')) {
                return $root;
            }
        }
        return null;
    }

    public static function load(string $pluginFile): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $dir = dirname($pluginFile);
        $root = self::coreRoot($dir);
        spl_autoload_register(static function (string $class) use ($dir, $root): void {
            $map = ['MonteFiskal\\WooCommerce\\' => $dir . '/src/'];
            if ($root !== null) {
                $map['MonteFiskal\\'] = $root . '/src/php/montefiskal-core/src/';
            }
            foreach ($map as $prefix => $base) {
                if (str_starts_with($class, $prefix)) {
                    $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                    return;
                }
            }
        });
        if ($root === null) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__('MonteFiskal: the bundled PHP core is missing. Install the release zip from GitHub, not the source folder alone.', 'montefiskal-woocommerce') . '</p></div>';
            });
            return;
        }
        \MonteFiskal\Resources::setRoot($root);

        register_activation_hook($pluginFile, [Install::class, 'activate']);
        register_deactivation_hook($pluginFile, [Install::class, 'deactivate']);

        // HPOS and block checkout compatibility (architecture section 4, "Compatibility").
        add_action('before_woocommerce_init', static function () use ($pluginFile): void {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $pluginFile, true);
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $pluginFile, true);
            }
        });

        add_action('plugins_loaded', static function () use ($pluginFile): void {
            if (!class_exists(\WooCommerce::class)) {
                add_action('admin_notices', static function (): void {
                    echo '<div class="notice notice-error"><p>' . esc_html__('MonteFiskal needs WooCommerce to be active.', 'montefiskal-woocommerce') . '</p></div>';
                });
                return;
            }
            Plugin::instance($pluginFile)->register();
        }, 20);
    }
}
