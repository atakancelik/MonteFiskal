<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Admin;

use DateTimeImmutable;
use MonteFiskal\Numbering\FiscalTime;
use MonteFiskal\Reports\InspectorReports;
use MonteFiskal\Reports\ReportInvoice;
use MonteFiskal\Reports\TurnoverReport;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Settings;

defined('ABSPATH') || exit;

/** The inspector reports with CSV export: state cut, daily, periodic, analytic, offline and corrective journals. */
final class ReportsPage
{
    public const SLUG = 'montefiskal-reports';

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_submenu_page('woocommerce', __('Fiscal reports', 'montefiskal-woocommerce'), __('Fiscal reports', 'montefiskal-woocommerce'), 'manage_woocommerce', self::SLUG, [self::class, 'render']);
        }, 61);
        add_action('admin_post_montefiskal_report_csv', [self::class, 'csv']);
    }

    /** @return array<string, string> */
    public static function kinds(): array
    {
        return [
            'state' => __('Fiscal state cut (today until now)', 'montefiskal-woocommerce'),
            'daily' => __('Daily fiscal report', 'montefiskal-woocommerce'),
            'periodic' => __('Periodic turnover report', 'montefiskal-woocommerce'),
            'analytic' => __('Analytic journal', 'montefiskal-woocommerce'),
            'offline' => __('Journal of invoices not yet fiscalized', 'montefiskal-woocommerce'),
            'corrective' => __('Journal of corrective invoices', 'montefiskal-woocommerce'),
        ];
    }

    /**
     * @return TurnoverReport|list<array{day: string, invoices: list<ReportInvoice>, subtotal: string}>
     */
    public static function build(string $kind, string $from, string $to): TurnoverReport|array
    {
        $profile = Settings::profile();
        $zone = FiscalTime::zone($profile->timeZone);
        $start = new DateTimeImmutable($from . ' 00:00:00', $zone);
        $end = new DateTimeImmutable($to . ' 23:59:59', $zone);
        $data = InspectorReports::fromStore(ClientFactory::store()->list($start, $end), $profile->tcr);
        return match ($kind) {
            'state' => InspectorReports::stateCut(new DateTimeImmutable('now', $zone), $profile->tcr, $profile->businessUnit, $data['invoices'], $data['deposits']),
            'daily' => InspectorReports::daily($from, $profile->tcr, $profile->businessUnit, $data['invoices'], $data['deposits']),
            'periodic' => InspectorReports::periodic($from, $to, $profile->tcr, $profile->businessUnit, $data['invoices'], $data['deposits']),
            default => InspectorReports::journal($from, $to, $data['invoices'], $kind),
        };
    }

    /** @return array{0: string, 1: string, 2: string} kind, from, to */
    private static function request(): array
    {
        $kinds = self::kinds();
        $kind = sanitize_key((string) ($_REQUEST['kind'] ?? 'state'));
        $kind = isset($kinds[$kind]) ? $kind : 'state';
        $today = FiscalTime::localDate(new DateTimeImmutable(), Settings::profile()->timeZone);
        $date = static function (string $key) use ($today): string {
            $v = sanitize_text_field(wp_unslash((string) ($_REQUEST[$key] ?? $today)));
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $today;
        };
        $from = $kind === 'state' ? $today : $date('from');
        $to = in_array($kind, ['state', 'daily'], true) ? $from : $date('to');
        return [$kind, $from, $to];
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        [$kind, $from, $to] = self::request();
        echo '<div class="wrap montefiskal"><h1>' . esc_html__('Fiscal reports', 'montefiskal-woocommerce') . '</h1>';
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><select name="kind">';
        foreach (self::kinds() as $k => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($kind, $k, false), esc_html($label));
        }
        printf('</select> <input type="date" name="from" value="%s"> <input type="date" name="to" value="%s"> ', esc_attr($from), esc_attr($to));
        submit_button(__('Show', 'montefiskal-woocommerce'), 'secondary', '', false);
        $csv = wp_nonce_url(admin_url('admin-post.php?action=montefiskal_report_csv&kind=' . $kind . '&from=' . $from . '&to=' . $to), 'montefiskal_report_csv');
        echo ' <a class="button" href="' . esc_url($csv) . '">' . esc_html__('Download CSV', 'montefiskal-woocommerce') . '</a></form>';
        $report = self::build($kind, $from, $to);
        if ($report instanceof TurnoverReport) {
            self::turnover($report);
        } else {
            self::journal($report);
        }
        echo '</div>';
    }

    private static function turnover(TurnoverReport $r): void
    {
        echo '<h2>' . esc_html($r->title) . '</h2><table class="widefat striped montefiskal-report"><tbody>';
        $rows = [
            __('TCR', 'montefiskal-woocommerce') => $r->tcr,
            __('Business unit', 'montefiskal-woocommerce') => $r->businessUnit,
            __('Period', 'montefiskal-woocommerce') => $r->from . ' – ' . $r->to,
            __('Invoices', 'montefiskal-woocommerce') => (string) $r->invoiceCount,
            __('Corrective invoices', 'montefiskal-woocommerce') => (string) $r->correctiveCount,
            __('Not yet fiscalized', 'montefiskal-woocommerce') => (string) $r->offlineCount,
            __('Total without VAT', 'montefiskal-woocommerce') => $r->totalWithoutVat,
            __('VAT', 'montefiskal-woocommerce') => $r->vatAmount,
            __('Total', 'montefiskal-woocommerce') => $r->total,
            __('Initial deposit', 'montefiskal-woocommerce') => $r->initialDeposit,
            __('Withdrawals', 'montefiskal-woocommerce') => $r->withdrawals,
            __('Expected cash', 'montefiskal-woocommerce') => $r->expectedCash,
        ];
        foreach ($rows as $k => $v) {
            echo '<tr><th>' . esc_html($k) . '</th><td>' . esc_html($v) . '</td></tr>';
        }
        echo '</tbody></table><h3>' . esc_html__('By VAT rate', 'montefiskal-woocommerce') . '</h3>' . self::table($r->byVatRate);
        echo '<h3>' . esc_html__('By payment method', 'montefiskal-woocommerce') . '</h3>' . self::table($r->byPaymentMethod);
    }

    /** @param array<mixed> $rows */
    private static function table(array $rows): string
    {
        if ($rows === []) {
            return '<p>–</p>';
        }
        $html = '<table class="widefat striped"><tbody>';
        foreach ($rows as $k => $v) {
            $html .= '<tr><th>' . esc_html((string) $k) . '</th><td>' . esc_html(is_array($v) ? implode(' / ', array_map('strval', $v)) : (string) $v) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /** @param list<array{day: string, invoices: list<ReportInvoice>, subtotal: string}> $days */
    private static function journal(array $days): void
    {
        echo '<table class="widefat striped montefiskal-report"><thead><tr><th>' . esc_html__('Number', 'montefiskal-woocommerce') . '</th><th>' . esc_html__('Time', 'montefiskal-woocommerce') . '</th><th>' . esc_html__('Kind', 'montefiskal-woocommerce') . '</th><th>' . esc_html__('Type', 'montefiskal-woocommerce') . '</th><th>IKOF</th><th>JIKR</th><th>' . esc_html__('Total', 'montefiskal-woocommerce') . '</th></tr></thead><tbody>';
        foreach ($days as $day) {
            foreach ($day['invoices'] as $i) {
                printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>', esc_html($i->number), esc_html($i->issueDateTime), esc_html($i->kind), esc_html($i->typeOfInv), esc_html((string) $i->iic), esc_html((string) $i->fic), esc_html($i->total));
            }
            printf('<tr class="montefiskal-subtotal"><th colspan="6">%s %s</th><th>%s</th></tr>', esc_html__('Subtotal', 'montefiskal-woocommerce'), esc_html($day['day']), esc_html($day['subtotal']));
        }
        echo '</tbody></table>';
    }

    /** @param TurnoverReport|list<array{day: string, invoices: list<ReportInvoice>, subtotal: string}> $report */
    public static function toCsv(TurnoverReport|array $report): string
    {
        if ($report instanceof TurnoverReport) {
            return InspectorReports::toCsv($report);
        }
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['number', 'issueDateTime', 'kind', 'typeOfInv', 'iic', 'fic', 'total', 'fiscalized'], ',', '"', '');
        foreach ($report as $day) {
            foreach ($day['invoices'] as $i) {
                fputcsv($out, [$i->number, $i->issueDateTime, $i->kind, $i->typeOfInv, (string) $i->iic, (string) $i->fic, $i->total, $i->fiscalized ? 'yes' : 'no'], ',', '"', '');
            }
            fputcsv($out, ['subtotal ' . $day['day'], '', '', '', '', '', $day['subtotal'], ''], ',', '"', '');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    public static function csv(): void
    {
        check_admin_referer('montefiskal_report_csv');
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'montefiskal-woocommerce'), 403);
        }
        [$kind, $from, $to] = self::request();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="montefiskal-' . $kind . '-' . $from . '-' . $to . '.csv"');
        echo self::toCsv(self::build($kind, $from, $to)); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV download
        exit;
    }
}
