<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce;

use MonteFiskal\DateTimeFormat;
use MonteFiskal\DuplicateIicHandling;
use MonteFiskal\FiscalProfile;
use MonteFiskal\Model\FiscalEnvironment;
use MonteFiskal\MonteFiskalOptions;
use MonteFiskal\ResponseSignaturePolicy;
use MonteFiskal\SequenceScope;
use MonteFiskal\TextPolicy;
use MonteFiskal\VerificationUrlEncoding;

defined('ABSPATH') || exit;

/**
 * The plugin settings, kept in one option per site (multisite: each site is its own taxpayer profile).
 * The option names follow the intake questions of docs/ai/intake.md so that an assistant can map answers 1:1.
 */
final class Settings
{
    public const OPTION = 'montefiskal_settings';

    /** Invoicing modes (Q-24): one invoice at payment, or the advance flow of BR-K9. */
    public const MODE_SINGLE = 'single';
    public const MODE_ADVANCE = 'advance';

    /** Payment mapping value meaning "do not fiscalize orders paid with this gateway". */
    public const SKIP = 'skip';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'environment' => 'test',
            'issuer_tin' => '',
            'issuer_name' => '',
            'issuer_address' => '',
            'issuer_town' => '',
            'issuer_in_vat' => true,
            'business_unit' => '',
            'tcr' => '',
            'tcr_internal_id' => '',
            'software' => '',
            'operator' => '',
            'maintainer' => '',
            'time_zone' => 'Europe/Podgorica',
            // Trigger: the order event that issues the invoice (architecture section 4, "Trigger").
            'trigger' => 'payment_complete',
            'mode' => self::MODE_SINGLE,
            // Advance flow: the status that means "delivered", which issues the final invoice (BR-K9 step 2).
            'delivery_status' => 'completed',
            'payment_map' => [],
            // Q-29: BUSINESSCARD only when enabled, the buyer is a company with a tax number and the gateway reports a commercial card.
            'business_card' => false,
            'exemption_map' => [],
            'default_unit' => 'kom',
            'text_policy' => 'unchanged',
            'retention_years' => 10,
            'cash_limit' => '10000',
            'auto_initial_deposit' => true,
            'deposit_time' => '',
            // Switches for behaviour only the test service can settle (open-questions.md); defaults are the documented ones.
            'send_soap_action' => true,
            'item_decimals' => 4,
            'date_time_format' => 'localOffset',
            'verification_url_encoding' => 'percent',
            'response_signature' => 'verify',
            'duplicate_iic' => 'reject',
            'sequence_scope' => 'tcrYear',
            'initial_already_registered_is_success' => true,
            'deadline_warning_hours' => 36,
            'archive_dir' => '',
            'update_check' => false,
            'print_mode' => 'a4',
        ];
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        return array_replace(self::defaults(), is_array($stored) ? $stored : []);
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public static function update(array $values): void
    {
        update_option(self::OPTION, array_replace(self::all(), $values), false);
    }

    public static function isProduction(): bool
    {
        return self::get('environment') === 'production';
    }

    /** True when the codes needed to issue an invoice are present. */
    public static function isConfigured(): bool
    {
        $s = self::all();
        foreach (['issuer_tin', 'issuer_name', 'business_unit', 'tcr', 'software', 'operator'] as $k) {
            if (trim((string) $s[$k]) === '') {
                return false;
            }
        }
        return true;
    }

    public static function profile(): FiscalProfile
    {
        $s = self::all();
        return new FiscalProfile(
            issuerTin: (string) $s['issuer_tin'],
            issuerName: (string) $s['issuer_name'],
            businessUnit: (string) $s['business_unit'],
            software: (string) $s['software'],
            operator: (string) $s['operator'],
            tcr: (string) $s['tcr'],
            environment: self::isProduction() ? FiscalEnvironment::Production : FiscalEnvironment::Test,
            issuerAddress: $s['issuer_address'] !== '' ? (string) $s['issuer_address'] : null,
            issuerTown: $s['issuer_town'] !== '' ? (string) $s['issuer_town'] : null,
            issuerInVat: (bool) $s['issuer_in_vat'],
            maintainer: $s['maintainer'] !== '' ? (string) $s['maintainer'] : null,
            timeZone: (string) $s['time_zone'],
        );
    }

    public static function options(): MonteFiskalOptions
    {
        $s = self::all();
        return new MonteFiskalOptions(
            verificationUrlEncoding: VerificationUrlEncoding::tryFrom((string) $s['verification_url_encoding']) ?? VerificationUrlEncoding::Percent,
            text: TextPolicy::tryFrom((string) $s['text_policy']) ?? TextPolicy::Unchanged,
            responseSignature: ResponseSignaturePolicy::tryFrom((string) $s['response_signature']) ?? ResponseSignaturePolicy::Verify,
            dateTimeFormat: DateTimeFormat::tryFrom((string) $s['date_time_format']) ?? DateTimeFormat::LocalOffset,
            duplicateIic: DuplicateIicHandling::tryFrom((string) $s['duplicate_iic']) ?? DuplicateIicHandling::Reject,
            sequenceScope: SequenceScope::tryFrom((string) $s['sequence_scope']) ?? SequenceScope::TcrYear,
            itemDecimals: max(2, min(4, (int) $s['item_decimals'])),
            sendSoapAction: (bool) $s['send_soap_action'],
            cashLimit: (string) $s['cash_limit'],
            autoInitialDeposit: (bool) $s['auto_initial_deposit'],
            treatInitialAlreadyRegisteredAsSuccess: (bool) $s['initial_already_registered_is_success'],
        );
    }

    /** Where signed requests and responses are archived (BR-X2). Outside the web root when the constant is set. */
    public static function archiveDirectory(): string
    {
        if (defined('MONTEFISKAL_ARCHIVE_DIR')) {
            return (string) constant('MONTEFISKAL_ARCHIVE_DIR');
        }
        $configured = (string) self::get('archive_dir');
        return $configured !== '' ? $configured : WP_CONTENT_DIR . '/montefiskal-archive/' . get_current_blog_id();
    }
}
