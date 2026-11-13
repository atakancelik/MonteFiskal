<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce;

use MonteFiskal\WooCommerce\Queue\Queue;

defined('ABSPATH') || exit;

/**
 * Own tables with the site prefix (architecture section 4, "Data"). Uninstalling never drops them (BR-X1).
 */
final class Install
{
    public const DB_VERSION = '1';
    public const DB_OPTION = 'montefiskal_db_version';

    /** @return array{invoices: string, attempts: string, sequences: string, deposits: string} */
    public static function tables(): array
    {
        global $wpdb;
        return [
            'invoices' => $wpdb->prefix . 'montefiskal_invoices',
            'attempts' => $wpdb->prefix . 'montefiskal_attempts',
            'sequences' => $wpdb->prefix . 'montefiskal_sequences',
            'deposits' => $wpdb->prefix . 'montefiskal_cash_deposits',
        ];
    }

    public static function activate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::createTables();
                restore_current_blog();
            }
        } else {
            self::createTables();
        }
        Queue::scheduleRecurring();
    }

    public static function deactivate(): void
    {
        Queue::unscheduleAll();
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::createTables();
        }
    }

    public static function createTables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::tables();
        $charset = $wpdb->get_charset_collate();
        // Amounts are kept as decimal strings exactly as calculated; never as floating point.
        dbDelta("CREATE TABLE {$t['invoices']} (
  id char(36) NOT NULL,
  operation varchar(32) NOT NULL,
  state varchar(16) NOT NULL,
  kind varchar(20) DEFAULT NULL,
  type_of_inv varchar(10) DEFAULT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  order_role varchar(40) DEFAULT NULL,
  tcr varchar(20) NOT NULL DEFAULT '',
  sequence_key varchar(100) DEFAULT NULL,
  ordinal int(10) unsigned DEFAULT NULL,
  invoice_number varchar(80) DEFAULT NULL,
  iic char(32) DEFAULT NULL,
  result varchar(64) DEFAULT NULL,
  total varchar(32) DEFAULT NULL,
  depends_on_iic char(32) DEFAULT NULL,
  issued_at datetime NOT NULL,
  next_attempt_at datetime DEFAULT NULL,
  version int(10) unsigned NOT NULL DEFAULT 0,
  payload longtext NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY iic (iic),
  KEY state (state),
  KEY order_id (order_id),
  KEY issued_at (issued_at)
) $charset;");
        dbDelta("CREATE TABLE {$t['attempts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_id char(36) NOT NULL,
  attempted_at datetime NOT NULL,
  message_uuid char(36) NOT NULL,
  outcome varchar(16) NOT NULL,
  error_code int(11) DEFAULT NULL,
  problem text DEFAULT NULL,
  response_uuid varchar(40) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY request_message (request_id,message_uuid)
) $charset;");
        dbDelta("CREATE TABLE {$t['sequences']} (
  sequence_key varchar(100) NOT NULL,
  last_ordinal int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (sequence_key)
) $charset;");
        dbDelta("CREATE TABLE {$t['deposits']} (
  request_id char(36) NOT NULL,
  tcr varchar(20) NOT NULL,
  deposit_date date NOT NULL,
  operation varchar(10) NOT NULL,
  amount varchar(32) NOT NULL,
  state varchar(16) NOT NULL,
  fcdc varchar(64) DEFAULT NULL,
  PRIMARY KEY  (request_id),
  KEY tcr_date (tcr,deposit_date)
) $charset;");
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }
}
