<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Cli;

use DateTimeImmutable;
use DateTimeZone;
use MonteFiskal\Archive\InvoicePairs;
use MonteFiskal\Archive\OfflineExchange;
use MonteFiskal\Model\CashDeposit;
use MonteFiskal\Model\CashDepositOperation;
use MonteFiskal\WooCommerce\Admin\ReportsPage;
use MonteFiskal\WooCommerce\Admin\SetupWizard;
use MonteFiskal\WooCommerce\Admin\StatusPage;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Orders\Invoicing;
use MonteFiskal\WooCommerce\Queue\Queue;
use MonteFiskal\WooCommerce\Settings;
use WP_CLI;

defined('ABSPATH') || exit;

/**
 * Fiscalization from the command line.
 *
 * ## EXAMPLES
 *
 *     wp montefiskal status
 *     wp montefiskal issue 1234
 *     wp montefiskal resend
 *     wp montefiskal deposit --amount=0
 *     wp montefiskal report daily --from=2026-11-13
 *     wp montefiskal export-pairs ~/fiscal-pairs --from=2026-11-01 --to=2026-11-30
 */
final class Command
{
    /**
     * Shows the queue and configuration state.
     *
     * [--format=<format>]
     * : table, json or yaml. Default table.
     */
    public function status(array $args, array $assoc): void
    {
        $rows = [];
        foreach (StatusPage::facts() as $k => $v) {
            $rows[] = ['item' => $k, 'value' => $v];
        }
        WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['item', 'value']);
    }

    /**
     * Issues the invoice of an order (or the advance invoice in the advance flow) and queues sending.
     *
     * <order-id>
     * : The order ID.
     *
     * [--deliver]
     * : Advance flow: issue the final invoice and the corrective of the advance.
     */
    public function issue(array $args, array $assoc): void
    {
        $order = wc_get_order((int) $args[0]);
        if (!$order instanceof \WC_Order) {
            WP_CLI::error('No such order.');
        }
        $id = isset($assoc['deliver']) ? Invoicing::deliver($order) : Invoicing::issueForOrder($order);
        $id === null ? WP_CLI::warning('Nothing issued; see the order notes.') : WP_CLI::success('Issued ' . $id);
    }

    /**
     * Sends one request now.
     *
     * <request-id>
     * : The fiscal request ID.
     */
    public function send(array $args): void
    {
        Queue::runSend($args[0]);
        $s = ClientFactory::store()->get($args[0]);
        WP_CLI::log($s === null ? 'Unknown request.' : $s->state->value . ($s->result !== null ? ' ' . $s->result : ''));
    }

    /**
     * Sends every pending request once.
     */
    public function resend(): void
    {
        $r = ClientFactory::client()->resendPending();
        WP_CLI::success(sprintf('attempted %d, fiscalized %d, still pending %d, rejected %d', $r->attempted, $r->fiscalized, $r->stillPending, $r->rejected));
    }

    /**
     * Registers a cash deposit (BR-D1 to BR-D5).
     *
     * [--amount=<amount>]
     * : Amount in EUR. Default 0.
     *
     * [--withdraw]
     * : Register a WITHDRAW instead of INITIAL.
     */
    public function deposit(array $args, array $assoc): void
    {
        $p = Settings::profile();
        $amount = (string) ($assoc['amount'] ?? '0');
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            WP_CLI::error('The amount must be a decimal number such as 0 or 150.00.');
        }
        $r = ClientFactory::client()->registerCashDeposit(new CashDeposit($p->issuerTin, $p->tcr, isset($assoc['withdraw']) ? CashDepositOperation::Withdraw : CashDepositOperation::Initial, $amount, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
        WP_CLI::log($r->state->value . ($r->fcdc !== null ? ' ' . $r->fcdc : ''));
    }

    /**
     * Registers the TCR of this shop with the internal ID from the settings and saves the TCR code.
     *
     * @subcommand register-tcr
     */
    public function registerTcr(): void
    {
        foreach (SetupWizard::registerTcr() as [$type, $message]) {
            $type === 'success' ? WP_CLI::success($message) : WP_CLI::warning($message);
        }
    }

    /**
     * Prints a report as CSV.
     *
     * <kind>
     * : state, daily, periodic, analytic, offline or corrective.
     *
     * [--from=<date>]
     * : YYYY-MM-DD. Default today.
     *
     * [--to=<date>]
     * : YYYY-MM-DD. Default the from date.
     */
    public function report(array $args, array $assoc): void
    {
        if (!isset(ReportsPage::kinds()[$args[0]])) {
            WP_CLI::error('Unknown report kind.');
        }
        $from = (string) ($assoc['from'] ?? gmdate('Y-m-d'));
        WP_CLI::line(ReportsPage::toCsv(ReportsPage::build($args[0], $from, (string) ($assoc['to'] ?? $from))));
    }

    /**
     * Writes the offline export of every invoice that is not fiscalized (BR-O11).
     *
     * <directory>
     * : Target folder.
     *
     * @subcommand export-offline
     */
    public function exportOffline(array $args): void
    {
        $client = ClientFactory::client();
        $p = Settings::profile();
        $paths = OfflineExchange::export(ClientFactory::store()->pending(10000), static fn ($s): string => $client->signForExport($s), $p->tcr, new DateTimeImmutable(), $p->timeZone, $args[0]);
        WP_CLI::success(implode("\n", $paths) ?: 'Nothing to export.');
    }

    /**
     * Imports a response archive of the offline exchange (BR-O11).
     *
     * <zip>
     * : The _response.zip file.
     *
     * @subcommand import-responses
     */
    public function importResponses(array $args): void
    {
        $n = 0;
        foreach (OfflineExchange::readResponses($args[0]) as [$iic, $xml]) {
            if (ClientFactory::client()->applyImportedResponse($iic, $xml)) {
                $n++;
                $s = ClientFactory::store()->findByIic($iic);
                if ($s !== null) {
                    Invoicing::syncOrder($s);
                }
            }
        }
        WP_CLI::success("Applied {$n} responses.");
    }

    /**
     * Writes the signed request and the response of every fiscalized invoice (BR-X7): the exchange that received the
     * JIKR, byte for byte as archived, as <yyyyMMddHHmmss>_<TCR>_<IIC>_request.xml and _response.xml. Nothing is sent.
     * The files contain the shop's and buyers' data: write them to a private folder.
     *
     * <directory>
     * : Target folder, created if missing.
     *
     * [--from=<date>]
     * : First issue date, YYYY-MM-DD in the shop's time zone. Default today.
     *
     * [--to=<date>]
     * : Last issue date, YYYY-MM-DD. Default the from date.
     *
     * ## EXAMPLES
     *
     *     wp montefiskal export-pairs ~/fiscal-pairs --from=2026-11-01 --to=2026-11-30
     *
     * @subcommand export-pairs
     */
    public function exportPairs(array $args, array $assoc): void
    {
        $profile = Settings::profile();
        $zone = new DateTimeZone($profile->timeZone);
        $from = (string) ($assoc['from'] ?? (new DateTimeImmutable('now', $zone))->format('Y-m-d'));
        $to = (string) ($assoc['to'] ?? $from);
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                WP_CLI::error('Dates are YYYY-MM-DD.');
            }
        }
        $result = InvoicePairs::export(
            ClientFactory::store(),
            ClientFactory::archive(),
            new DateTimeImmutable($from . ' 00:00:00', $zone),
            new DateTimeImmutable($to . ' 23:59:59', $zone),
            $args[0],
        );
        $missing = array_values(array_filter($result->skipped, static fn (array $s): bool => $s['reason'] !== InvoicePairs::NOT_FISCALIZED));
        foreach ($missing as $s) {
            WP_CLI::warning(sprintf('%s (%s): %s', (string) $s['iic'], $s['id'], $s['reason']));
        }
        WP_CLI::success(sprintf(
            'wrote %d pairs to %s; %d invoices not fiscalized; %d without a matching pair',
            $result->pairs(),
            $args[0],
            count($result->skipped) - count($missing),
            count($missing),
        ));
    }
}
