<?php

/**
 * Integration test bootstrap: loads a real WordPress + WooCommerce site prepared by tests/install-wp.sh.
 * The EFI service is the in-process fake of the PHP core and the certificate is generated for this run only:
 * no test calls a live endpoint.
 */

declare(strict_types=1);

$root = getenv('WP_ROOT') ?: '';
if ($root === '' || !is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "Set WP_ROOT to a site prepared by plugins/montefiskal-woocommerce/tests/install-wp.sh\n");
    exit(1);
}
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

if (!class_exists(\MonteFiskal\WooCommerce\Plugin::class)) {
    fwrite(STDERR, "MonteFiskal is not active on the test site\n");
    exit(1);
}

// No mail leaves the test site.
add_filter('pre_wp_mail', '__return_true');

require_once __DIR__ . '/PluginTestCase.php';
