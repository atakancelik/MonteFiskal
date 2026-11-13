<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Frontend;

use MonteFiskal\WooCommerce\Orders\OrderMapper;

defined('ABSPATH') || exit;

/**
 * The buyer's tax number for classic checkout and, through the additional checkout fields API, for block checkout.
 * Company name is WooCommerce's own billing company field. A buyer with a tax number produces a Buyer element.
 */
final class BuyerFields
{
    public static function register(): void
    {
        add_filter('woocommerce_billing_fields', [self::class, 'classic']);
        add_filter('woocommerce_admin_billing_fields', [self::class, 'admin']);
        add_action('woocommerce_init', [self::class, 'blocks']);
        add_action('woocommerce_after_checkout_validation', [self::class, 'validateClassic'], 10, 2);
    }

    public static function label(): string
    {
        return __('Company tax number (PIB)', 'montefiskal-woocommerce');
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    public static function classic(array $fields): array
    {
        $fields['billing_montefiskal_tin'] = [
            'label' => self::label(),
            'required' => false,
            'class' => ['form-row-wide'],
            'priority' => 35,
            'description' => __('Only for purchases by a company that needs an invoice with its tax number.', 'montefiskal-woocommerce'),
        ];
        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    public static function admin(array $fields): array
    {
        $fields['montefiskal_tin'] = ['label' => self::label(), 'show' => true];
        return $fields;
    }

    public static function blocks(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }
        woocommerce_register_additional_checkout_field([
            'id' => OrderMapper::TIN_FIELD,
            'label' => self::label(),
            'optionalLabel' => self::label() . ' ' . __('(optional, companies only)', 'montefiskal-woocommerce'),
            'location' => 'address',
            'type' => 'text',
            'required' => false,
            'attributes' => ['autocomplete' => 'off'],
            'sanitize_callback' => [self::class, 'sanitize'],
            'validate_callback' => [self::class, 'validate'],
        ]);
    }

    public static function sanitize(mixed $value): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $value));
    }

    public static function validate(mixed $value): ?\WP_Error
    {
        $v = self::sanitize($value);
        if ($v !== '' && (strlen($v) < 5 || strlen($v) > 20)) {
            return new \WP_Error('montefiskal_tin', __('Enter a valid company tax number or leave the field empty.', 'montefiskal-woocommerce'));
        }
        return null;
    }

    /** @param array<string, mixed> $data */
    public static function validateClassic(array $data, \WP_Error $errors): void
    {
        $error = self::validate($data['billing_montefiskal_tin'] ?? '');
        if ($error !== null) {
            $errors->add($error->get_error_code(), $error->get_error_message());
        }
    }
}
