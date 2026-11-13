<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Admin;

use DateTimeImmutable;
use MonteFiskal\Model\TcrRegistration;
use MonteFiskal\Model\TcrType;
use MonteFiskal\Security\CertificateChecks;
use MonteFiskal\Transport\FiscalEndpoints;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Settings;

defined('ABSPATH') || exit;

/** Setup wizard: taxpayer, codes, certificate, test connection, optional TCR registration, payment mapping. */
final class SetupWizard
{
    public const SLUG = 'montefiskal-setup';

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_submenu_page('', __('MonteFiskal setup', 'montefiskal-woocommerce'), __('MonteFiskal setup', 'montefiskal-woocommerce'), 'manage_woocommerce', self::SLUG, [self::class, 'render']);
        });
        add_action('admin_post_montefiskal_wizard', [self::class, 'handle']);
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function steps(): array
    {
        return [
            'taxpayer' => [__('Taxpayer', 'montefiskal-woocommerce'), ['environment', 'issuer_tin', 'issuer_name', 'issuer_address', 'issuer_town', 'issuer_in_vat']],
            'codes' => [__('Codes', 'montefiskal-woocommerce'), ['business_unit', 'software', 'maintainer', 'operator', 'tcr_internal_id']],
            'connection' => [__('Certificate and connection', 'montefiskal-woocommerce'), []],
            'tcr' => [__('TCR registration', 'montefiskal-woocommerce'), ['tcr']],
            'payments' => [__('Payments and invoicing', 'montefiskal-woocommerce'), ['mode', 'trigger', 'business_card']],
        ];
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $steps = self::steps();
        $step = sanitize_key((string) ($_GET['step'] ?? 'taxpayer'));
        $step = isset($steps[$step]) ? $step : 'taxpayer';
        $fields = SettingsPage::fields();
        $s = Settings::all();
        echo '<div class="wrap montefiskal montefiskal-wizard"><h1>' . esc_html__('MonteFiskal setup', 'montefiskal-woocommerce') . '</h1><ol class="montefiskal-steps">';
        foreach ($steps as $k => [$label]) {
            printf('<li class="%s">%s</li>', $k === $step ? 'current' : '', esc_html($label));
        }
        echo '</ol>';
        $result = get_transient('montefiskal_wizard_result_' . get_current_user_id());
        if (is_array($result)) {
            delete_transient('montefiskal_wizard_result_' . get_current_user_id());
            foreach ($result as [$type, $text]) {
                printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($type), esc_html($text));
            }
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('montefiskal_wizard');
        echo '<input type="hidden" name="action" value="montefiskal_wizard"><input type="hidden" name="step" value="' . esc_attr($step) . '"><table class="form-table" role="presentation">';
        foreach ($steps[$step][1] as $key) {
            SettingsPage::field($key, $fields[$key], $s[$key] ?? '');
        }
        echo '</table>';
        if ($step === 'connection') {
            echo '<p>' . esc_html__('Put the .p12 certificate outside the web root and add to wp-config.php:', 'montefiskal-woocommerce') . '</p>';
            echo "<pre>define( 'MONTEFISKAL_CERT_PATH', '/path/outside/webroot/&lt;your certificate&gt;.p12' );\ndefine( 'MONTEFISKAL_CERT_PASSWORD', '&lt;your certificate password&gt;' );</pre>";
            echo '<p>' . esc_html__('Then test the connection: the certificate is loaded and checked, and the service endpoint is reached over HTTPS. No invoice is sent.', 'montefiskal-woocommerce') . '</p>';
            echo '<p><button class="button" name="do" value="test">' . esc_html__('Test connection', 'montefiskal-woocommerce') . '</button></p>';
        }
        if ($step === 'tcr') {
            echo '<p>' . esc_html__('Leave the TCR code empty and press "Register TCR" to register this web shop as a TCR of the business unit. Registering again with the same internal ID returns the same code (BR-R3).', 'montefiskal-woocommerce') . '</p>';
            echo '<p><button class="button" name="do" value="register_tcr">' . esc_html__('Register TCR', 'montefiskal-woocommerce') . '</button></p>';
        }
        if ($step === 'payments') {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=montefiskal&tab=payments')) . '">' . esc_html__('Map each payment gateway to a payment method', 'montefiskal-woocommerce') . '</a> · <a href="' . esc_url(admin_url('admin.php?page=montefiskal&tab=taxes')) . '">' . esc_html__('Map zero-rate tax classes to exemption codes', 'montefiskal-woocommerce') . '</a></p>';
        }
        $keys = array_keys($steps);
        $next = $keys[array_search($step, $keys, true) + 1] ?? null;
        submit_button($next !== null ? __('Save and continue', 'montefiskal-woocommerce') : __('Finish', 'montefiskal-woocommerce'));
        echo '</form></div>';
    }

    public static function handle(): void
    {
        check_admin_referer('montefiskal_wizard');
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'montefiskal-woocommerce'), 403);
        }
        $steps = self::steps();
        $step = sanitize_key((string) ($_POST['step'] ?? 'taxpayer'));
        $input = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        Settings::update(SettingsPage::sanitize($input));
        ClientFactory::reset();
        $do = sanitize_key((string) ($_POST['do'] ?? ''));
        $messages = [];
        if ($do === 'test') {
            $messages = self::testConnection();
            $target = $step;
        } elseif ($do === 'register_tcr') {
            $messages = self::registerTcr();
            $target = $step;
        } else {
            $keys = array_keys($steps);
            $target = $keys[array_search($step, $keys, true) + 1] ?? null;
        }
        if ($messages !== []) {
            set_transient('montefiskal_wizard_result_' . get_current_user_id(), $messages, 300);
        }
        wp_safe_redirect($target === null ? admin_url('admin.php?page=montefiskal-status') : admin_url('admin.php?page=' . self::SLUG . '&step=' . $target));
        exit;
    }

    /** @return list<array{0: string, 1: string}> */
    public static function testConnection(): array
    {
        $out = [];
        try {
            $cert = ClientFactory::certificates()->certificate();
            $checks = CertificateChecks::inspect($cert, (string) Settings::get('issuer_tin'), new DateTimeImmutable());
            foreach ($checks['errors'] as $e) {
                $out[] = ['error', $e];
            }
            foreach ($checks['warnings'] as $w) {
                $out[] = ['warning', $w];
            }
            if ($checks['errors'] === []) {
                $out[] = ['success', __('The certificate loads and passes the local checks.', 'montefiskal-woocommerce')];
            }
        } catch (\Throwable $e) {
            $out[] = ['error', $e->getMessage()];
        }
        $endpoint = FiscalEndpoints::for(Settings::profile()->environment);
        $response = wp_remote_get($endpoint . '?wsdl', ['timeout' => 15]);
        if (is_wp_error($response)) {
            $out[] = ['error', sprintf(/* translators: 1: URL, 2: error */ __('%1$s could not be reached: %2$s', 'montefiskal-woocommerce'), $endpoint, $response->get_error_message())];
        } else {
            $out[] = ['success', sprintf(/* translators: 1: URL, 2: HTTP status */ __('%1$s answered with HTTP %2$d.', 'montefiskal-woocommerce'), $endpoint, (int) wp_remote_retrieve_response_code($response))];
        }
        return $out;
    }

    /** @return list<array{0: string, 1: string}> */
    public static function registerTcr(): array
    {
        $s = Settings::all();
        if ($s['tcr_internal_id'] === '' || $s['business_unit'] === '' || $s['issuer_tin'] === '') {
            return [['error', __('Enter the tax number, the business unit code and a TCR internal ID first.', 'montefiskal-woocommerce')]];
        }
        try {
            $result = ClientFactory::client()->registerTcr(new TcrRegistration(
                (string) $s['issuer_tin'],
                (string) $s['business_unit'],
                (string) $s['tcr_internal_id'],
                $s['software'] !== '' ? (string) $s['software'] : null,
                $s['maintainer'] !== '' ? (string) $s['maintainer'] : null,
                null,
                null,
                TcrType::Regular,
            ));
        } catch (\Throwable $e) {
            return [['error', $e->getMessage()]];
        }
        if ($result->success && $result->tcrCode !== null) {
            Settings::update(['tcr' => $result->tcrCode]);
            return [['success', sprintf(/* translators: %s: TCR code */ __('TCR registered: %s', 'montefiskal-woocommerce'), $result->tcrCode)]];
        }
        return [['error', trim(($result->error !== null ? 'code ' . $result->error->code . ': ' . $result->error->faultString : '') . ' ' . (string) $result->problem)]];
    }
}
