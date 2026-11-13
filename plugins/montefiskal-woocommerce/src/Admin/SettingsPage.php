<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Admin;

use MonteFiskal\Model\PaymentMethod;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Secrets;
use MonteFiskal\WooCommerce\Settings;

defined('ABSPATH') || exit;

/** WooCommerce > MonteFiskal: environment, taxpayer data, codes, certificate, trigger, mappings, text policy, retention. */
final class SettingsPage
{
    public const AI_SETUP_URL = 'https://github.com/atakancelik/MonteFiskal/blob/main/docs/ai/prompts/woocommerce-setup.md';

    public const SLUG = 'montefiskal';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 60);
        add_action('admin_post_montefiskal_save_settings', [self::class, 'save']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function menu(): void
    {
        add_submenu_page('woocommerce', __('MonteFiskal', 'montefiskal-woocommerce'), __('MonteFiskal', 'montefiskal-woocommerce'), 'manage_woocommerce', self::SLUG, [self::class, 'render']);
    }

    public static function assets(string $hook): void
    {
        if (str_contains($hook, 'montefiskal')) {
            wp_enqueue_style('montefiskal-admin', \MonteFiskal\WooCommerce\Plugin::instance()->url('assets/css/admin.css'), [], MONTEFISKAL_WC_VERSION);
        }
    }

    /** @return array<string, string> */
    public static function tabs(): array
    {
        return [
            'general' => __('Taxpayer', 'montefiskal-woocommerce'),
            'codes' => __('Codes', 'montefiskal-woocommerce'),
            'certificate' => __('Certificate', 'montefiskal-woocommerce'),
            'invoicing' => __('Invoicing', 'montefiskal-woocommerce'),
            'payments' => __('Payment methods', 'montefiskal-woocommerce'),
            'taxes' => __('Taxes', 'montefiskal-woocommerce'),
            'advanced' => __('Advanced', 'montefiskal-woocommerce'),
        ];
    }

    /**
     * Field definitions: key => [tab, type, label, help, choices].
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3?: string, 4?: array<string, string>}>
     */
    public static function fields(): array
    {
        $statuses = [];
        foreach (wc_get_order_statuses() as $k => $label) {
            $statuses[substr($k, 3)] = $label;
        }
        return [
            'environment' => ['general', 'select', __('Environment', 'montefiskal-woocommerce'), __('Use the test environment (efitest) until the test scenarios pass.', 'montefiskal-woocommerce'), ['test' => __('Test (efitest)', 'montefiskal-woocommerce'), 'production' => __('Production', 'montefiskal-woocommerce')]],
            'issuer_tin' => ['general', 'text', __('Tax number (PIB)', 'montefiskal-woocommerce'), __('8 digits for a company, 13 for a natural person (JMB).', 'montefiskal-woocommerce')],
            'issuer_name' => ['general', 'text', __('Registered name', 'montefiskal-woocommerce')],
            'issuer_address' => ['general', 'text', __('Address', 'montefiskal-woocommerce')],
            'issuer_town' => ['general', 'text', __('Town', 'montefiskal-woocommerce')],
            'issuer_in_vat' => ['general', 'checkbox', __('Registered for VAT', 'montefiskal-woocommerce'), __('Must match the VAT register (BR-V1).', 'montefiskal-woocommerce')],
            'time_zone' => ['general', 'text', __('Time zone', 'montefiskal-woocommerce'), __('IANA name; Europe/Podgorica unless advised otherwise.', 'montefiskal-woocommerce')],
            'business_unit' => ['codes', 'text', __('Business unit code', 'montefiskal-woocommerce'), __('From the business unit registration on the SEP.', 'montefiskal-woocommerce')],
            'tcr' => ['codes', 'text', __('TCR code', 'montefiskal-woocommerce'), __('Returned by the TCR registration; the setup wizard can register one.', 'montefiskal-woocommerce')],
            'tcr_internal_id' => ['codes', 'text', __('TCR internal ID', 'montefiskal-woocommerce'), __('Your own identifier of this web shop as a TCR; the same ID returns the same TCR code (BR-R3).', 'montefiskal-woocommerce')],
            'software' => ['codes', 'text', __('Software code', 'montefiskal-woocommerce'), __('The code you received when registering this software version on the SEP (BR-R7).', 'montefiskal-woocommerce')],
            'maintainer' => ['codes', 'text', __('Maintainer code', 'montefiskal-woocommerce'), __('The code of the registered maintainer (BR-R7).', 'montefiskal-woocommerce')],
            'operator' => ['codes', 'text', __('Operator code', 'montefiskal-woocommerce'), __('The operator registered for automated issuing by the web shop (BR-K16).', 'montefiskal-woocommerce')],
            'trigger' => ['invoicing', 'select', __('Issue the invoice', 'montefiskal-woocommerce'), __('Default: when the payment is complete.', 'montefiskal-woocommerce'), ['payment_complete' => __('When payment is complete', 'montefiskal-woocommerce')] + array_map(static fn (string $l): string => sprintf(/* translators: %s: order status */ __('When the order becomes "%s"', 'montefiskal-woocommerce'), $l), $statuses) + ['manual' => __('Only by hand (order action, bulk action, WP-CLI)', 'montefiskal-woocommerce')]],
            'mode' => ['invoicing', 'select', __('Invoicing mode', 'montefiskal-woocommerce'), __('Single invoice at payment, or the advance flow: an advance invoice at payment, then the invoice and the corrective of the advance at delivery (BR-K9). Ask your accountant which applies to prepaid orders (Q-24).', 'montefiskal-woocommerce'), [Settings::MODE_SINGLE => __('Single invoice (default)', 'montefiskal-woocommerce'), Settings::MODE_ADVANCE => __('Advance flow', 'montefiskal-woocommerce')]],
            'delivery_status' => ['invoicing', 'select', __('Delivery status (advance flow)', 'montefiskal-woocommerce'), '', $statuses],
            'default_unit' => ['invoicing', 'text', __('Default unit of measure', 'montefiskal-woocommerce')],
            'auto_initial_deposit' => ['invoicing', 'checkbox', __('Register the daily INITIAL cash deposit of 0.00 automatically', 'montefiskal-woocommerce'), __('Required before the first cash invoice of the day, including consumer card payments (BR-D5).', 'montefiskal-woocommerce')],
            'deposit_time' => ['invoicing', 'text', __('Also register it at a fixed time (HH:MM, optional)', 'montefiskal-woocommerce')],
            'cash_limit' => ['invoicing', 'text', __('Cash limit (EUR)', 'montefiskal-woocommerce'), __('Banknote payments at or above this amount are refused (Q-15).', 'montefiskal-woocommerce')],
            'retention_years' => ['invoicing', 'number', __('Retention (years)', 'montefiskal-woocommerce'), __('Fiscal records are kept at least five years after the end of the year (BR-X1); the default is ten.', 'montefiskal-woocommerce')],
            'print_mode' => ['invoicing', 'select', __('Printable invoice', 'montefiskal-woocommerce'), __('Cash invoices must be handed over on paper (BR-P4).', 'montefiskal-woocommerce'), ['a4' => 'A4', 'receipt' => __('Receipt printer (80 mm)', 'montefiskal-woocommerce')]],
            'business_card' => ['payments', 'checkbox', __('Use BUSINESSCARD for company cards', 'montefiskal-woocommerce'), __('Only when the buyer is a company with a tax number and the gateway reports a commercial card (Q-29). Otherwise every card payment is CARD.', 'montefiskal-woocommerce')],
            'text_policy' => ['advanced', 'select', __('Text outside ASCII', 'montefiskal-woocommerce'), 'Q-07', ['unchanged' => __('Send unchanged (default)', 'montefiskal-woocommerce'), 'transliterate' => __('Transliterate to ASCII', 'montefiskal-woocommerce')]],
            'send_soap_action' => ['advanced', 'checkbox', __('Send the SOAPAction header', 'montefiskal-woocommerce'), 'Q-22'],
            'item_decimals' => ['advanced', 'number', __('Decimals of item amounts (2 to 4)', 'montefiskal-woocommerce'), 'Q-14'],
            'date_time_format' => ['advanced', 'select', __('Date and time format', 'montefiskal-woocommerce'), 'Q-23', ['localOffset' => __('Local time with offset (default)', 'montefiskal-woocommerce'), 'utc' => 'UTC']],
            'verification_url_encoding' => ['advanced', 'select', __('Verification link encoding', 'montefiskal-woocommerce'), 'Q-06', ['percent' => __('Percent-encoded (default)', 'montefiskal-woocommerce'), 'none' => __('Not encoded', 'montefiskal-woocommerce')]],
            'response_signature' => ['advanced', 'select', __('Response signature', 'montefiskal-woocommerce'), 'Q-12', ['verify' => __('Verify when present (default)', 'montefiskal-woocommerce'), 'require' => __('Require', 'montefiskal-woocommerce'), 'ignore' => __('Ignore', 'montefiskal-woocommerce')]],
            'duplicate_iic' => ['advanced', 'select', __('IKOF already registered', 'montefiskal-woocommerce'), 'Q-09', ['reject' => __('Treat as rejected (default)', 'montefiskal-woocommerce'), 'acceptWithoutFic' => __('Accept without JIKR', 'montefiskal-woocommerce')]],
            'sequence_scope' => ['advanced', 'select', __('Invoice number sequence', 'montefiskal-woocommerce'), 'BR-N3', ['tcrYear' => __('Per TCR and year (default)', 'montefiskal-woocommerce'), 'businessUnitYear' => __('Per business unit and year', 'montefiskal-woocommerce')]],
            'initial_already_registered_is_success' => ['advanced', 'checkbox', __('Count "INITIAL already registered" as success', 'montefiskal-woocommerce'), 'Q-17'],
            'deadline_warning_hours' => ['advanced', 'number', __('Warn about unsent invoices after (hours)', 'montefiskal-woocommerce'), 'BR-O12'],
            'archive_dir' => ['advanced', 'text', __('Archive folder', 'montefiskal-woocommerce'), __('Signed requests and responses (BR-X2). Prefer a folder outside the web root, or define MONTEFISKAL_ARCHIVE_DIR.', 'montefiskal-woocommerce')],
            'update_check' => ['advanced', 'checkbox', __('Check GitHub for new releases', 'montefiskal-woocommerce')],
        ];
    }

    /** @return array<string, string> */
    public static function methodChoices(): array
    {
        $labels = [
            '' => __('Not mapped (no invoice, admin notice)', 'montefiskal-woocommerce'),
            Settings::SKIP => __('Do not fiscalize', 'montefiskal-woocommerce'),
        ];
        foreach (PaymentMethod::cases() as $m) {
            if ($m === PaymentMethod::BusinessCard) {
                continue; // Chosen per order from CARD (Q-29).
            }
            $labels[$m->value] = $m->value;
        }
        return $labels;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $tabs = self::tabs();
        $tab = sanitize_key((string) ($_GET['tab'] ?? 'general'));
        $tab = isset($tabs[$tab]) ? $tab : 'general';
        $s = Settings::all();
        echo '<div class="wrap montefiskal"><h1>' . esc_html__('MonteFiskal', 'montefiskal-woocommerce') . '</h1>';
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'montefiskal-woocommerce') . '</p></div>';
        }
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=montefiskal-setup')) . '">' . esc_html__('Run the setup wizard', 'montefiskal-woocommerce') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=montefiskal-status')) . '">' . esc_html__('Status', 'montefiskal-woocommerce') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=montefiskal-reports')) . '">' . esc_html__('Reports', 'montefiskal-woocommerce') . '</a> ';
        echo '<a href="' . esc_url(self::AI_SETUP_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Set up with an AI assistant', 'montefiskal-woocommerce') . '</a></p>';
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $k => $label) {
            printf('<a class="nav-tab%s" href="%s">%s</a>', $k === $tab ? ' nav-tab-active' : '', esc_url(admin_url('admin.php?page=' . self::SLUG . '&tab=' . $k)), esc_html($label));
        }
        echo '</nav><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('montefiskal_save_settings');
        echo '<input type="hidden" name="action" value="montefiskal_save_settings"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
        echo '<table class="form-table" role="presentation">';
        foreach (self::fields() as $key => $f) {
            if ($f[0] === $tab) {
                self::field($key, $f, $s[$key] ?? '');
            }
        }
        if ($tab === 'certificate') {
            self::certificateRows();
        } elseif ($tab === 'payments') {
            self::paymentRows($s);
        } elseif ($tab === 'taxes') {
            self::taxRows($s);
        }
        echo '</table>';
        submit_button();
        echo '</form></div>';
    }

    /** @param array{0: string, 1: string, 2: string, 3?: string, 4?: array<string, string>} $f */
    public static function field(string $key, array $f, mixed $value): void
    {
        $id = 'montefiskal_' . $key;
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($f[2]) . '</label></th><td>';
        $name = 'settings[' . $key . ']';
        switch ($f[1]) {
            case 'checkbox':
                printf('<input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%2$s" name="%1$s" value="1"%3$s>', esc_attr($name), esc_attr($id), checked((bool) $value, true, false));
                break;
            case 'select':
                printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));
                foreach ($f[4] ?? [] as $k => $label) {
                    printf('<option value="%s"%s>%s</option>', esc_attr((string) $k), selected((string) $value, (string) $k, false), esc_html($label));
                }
                echo '</select>';
                break;
            case 'number':
                printf('<input type="number" class="small-text" id="%s" name="%s" value="%s">', esc_attr($id), esc_attr($name), esc_attr((string) $value));
                break;
            default:
                printf('<input type="text" class="regular-text" id="%s" name="%s" value="%s" autocomplete="off">', esc_attr($id), esc_attr($name), esc_attr((string) $value));
        }
        if (($f[3] ?? '') !== '') {
            echo '<p class="description">' . esc_html($f[3]) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function certificateRows(): void
    {
        $source = ClientFactory::certificateSource();
        echo '<tr><th scope="row">' . esc_html__('Source in use', 'montefiskal-woocommerce') . '</th><td><code>' . esc_html($source) . '</code>';
        echo '<p class="description">' . esc_html__('Preferred: define MONTEFISKAL_CERT_PATH and MONTEFISKAL_CERT_PASSWORD in wp-config.php, with the .p12 file outside the web root. Never upload the certificate through the media library.', 'montefiskal-woocommerce') . '</p></td></tr>';
        if ($source === 'wp-config.php' || $source === 'environment' || $source === 'filter') {
            return;
        }
        echo '<tr><th scope="row"><label for="montefiskal_cert_path">' . esc_html__('Path to the .p12 file', 'montefiskal-woocommerce') . '</label></th><td>';
        printf('<input type="text" class="regular-text" id="montefiskal_cert_path" name="cert_path" value="%s" autocomplete="off">', esc_attr((string) get_option('montefiskal_cert_path', '')));
        echo '</td></tr><tr><th scope="row"><label for="montefiskal_cert_password">' . esc_html__('Password', 'montefiskal-woocommerce') . '</label></th><td>';
        echo '<input type="password" class="regular-text" id="montefiskal_cert_password" name="cert_password" value="" autocomplete="new-password">';
        echo '<p class="description">' . esc_html__('Stored encrypted with a key derived from the site salts. Leave empty to keep the saved one.', 'montefiskal-woocommerce') . '</p></td></tr>';
    }

    /** @param array<string, mixed> $s */
    private static function paymentRows(array $s): void
    {
        $map = (array) $s['payment_map'];
        $choices = self::methodChoices();
        $gateways = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            $gateways[(string) $id] = $gateway->get_method_title() ?: (string) $id;
        }
        // Keep mappings of gateways that are not active at the moment, so that saving does not drop them.
        foreach (array_keys($map) as $id) {
            $gateways[(string) $id] ??= __('(not active)', 'montefiskal-woocommerce');
        }
        foreach ($gateways as $id => $title) {
            echo '<tr><th scope="row">' . esc_html($title) . ' <code>' . esc_html($id) . '</code></th><td>';
            printf('<select name="payment_map[%s]">', esc_attr((string) $id));
            foreach ($choices as $k => $label) {
                printf('<option value="%s"%s>%s</option>', esc_attr($k), selected((string) ($map[$id] ?? ''), $k, false), esc_html($label));
            }
            echo '</select></td></tr>';
        }
        echo '<tr><td colspan="2"><p class="description">' . esc_html__('The invoice type follows from the payment method (BR-K3): BANKNOTE, CARD and OTHER-CASH make a cash invoice, which needs the daily INITIAL deposit and a paper invoice; ACCOUNT, COMPANY, BUSINESSCARD and the others make a non-cash invoice.', 'montefiskal-woocommerce') . '</p></td></tr>';
    }

    /** @param array<string, mixed> $s */
    private static function taxRows(array $s): void
    {
        $map = (array) $s['exemption_map'];
        $classes = ['standard' => __('Standard rate', 'montefiskal-woocommerce')];
        foreach (\WC_Tax::get_tax_class_slugs() as $i => $slug) {
            $classes[$slug] = \WC_Tax::get_tax_classes()[$i] ?? $slug;
        }
        $codes = ['' => __('No exemption', 'montefiskal-woocommerce')];
        foreach (['VAT_CL3', 'VAT_CL4', 'VAT_CL14', 'VAT_CL15', 'VAT_CL17', 'VAT_CL20', 'VAT_CL26', 'VAT_CL27', 'VAT_CL28', 'VAT_CL29', 'VAT_CL30', 'VAT_CL44'] as $c) {
            $codes[$c] = $c;
        }
        echo '<tr><td colspan="2"><p class="description">' . esc_html__('The VAT percentage comes from the WooCommerce tax rates. For a tax class whose rate is zero, choose the article of the VAT law the exemption rests on (BR-V5).', 'montefiskal-woocommerce') . '</p></td></tr>';
        foreach ($classes as $slug => $label) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
            printf('<select name="exemption_map[%s]">', esc_attr($slug));
            foreach ($codes as $k => $l) {
                printf('<option value="%s"%s>%s</option>', esc_attr($k), selected((string) ($map[$slug] ?? ''), $k, false), esc_html($l));
            }
            echo '</select></td></tr>';
        }
    }

    public static function save(): void
    {
        check_admin_referer('montefiskal_save_settings');
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'montefiskal-woocommerce'), 403);
        }
        $tab = sanitize_key((string) ($_POST['tab'] ?? 'general'));
        $input = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        Settings::update(self::sanitize($input));
        if ($tab === 'payments') {
            $map = [];
            foreach ((array) wp_unslash($_POST['payment_map'] ?? []) as $gw => $v) {
                $v = sanitize_text_field((string) $v);
                if (isset(self::methodChoices()[$v])) {
                    $map[sanitize_key((string) $gw)] = $v;
                }
            }
            Settings::update(['payment_map' => $map]);
        } elseif ($tab === 'taxes') {
            $map = [];
            foreach ((array) wp_unslash($_POST['exemption_map'] ?? []) as $cls => $v) {
                $v = sanitize_text_field((string) $v);
                if ($v === '' || preg_match('/^VAT_CL\d+$/', $v)) {
                    $map[sanitize_title((string) $cls)] = $v;
                }
            }
            Settings::update(['exemption_map' => $map]);
        } elseif ($tab === 'certificate') {
            if (isset($_POST['cert_path'])) {
                update_option('montefiskal_cert_path', sanitize_text_field(wp_unslash((string) $_POST['cert_path'])), false);
            }
            $password = isset($_POST['cert_password']) ? (string) wp_unslash($_POST['cert_password']) : '';
            if ($password !== '') {
                update_option('montefiskal_cert_password', Secrets::encrypt($password), false);
            }
        }
        ClientFactory::reset();
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&tab=' . $tab . '&saved=1'));
        exit;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $out = [];
        foreach (self::fields() as $key => $f) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $v = $input[$key];
            $out[$key] = match ($f[1]) {
                'checkbox' => (string) $v === '1',
                'number' => (int) $v,
                'select' => array_key_exists((string) $v, $f[4] ?? []) ? (string) $v : Settings::defaults()[$key],
                default => sanitize_text_field((string) $v),
            };
        }
        if (isset($out['cash_limit']) && !preg_match('/^\d+(\.\d{1,2})?$/', (string) $out['cash_limit'])) {
            $out['cash_limit'] = Settings::defaults()['cash_limit'];
        }
        if (isset($out['issuer_tin'])) {
            $out['issuer_tin'] = preg_replace('/\D/', '', (string) $out['issuer_tin']);
        }
        if (isset($out['time_zone']) && !in_array($out['time_zone'], timezone_identifiers_list(), true)) {
            $out['time_zone'] = 'Europe/Podgorica';
        }
        return $out;
    }
}
