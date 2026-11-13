<?php

declare(strict_types=1);

namespace MonteFiskal\Reports;

use MonteFiskal\Calculation\Dec;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Numbering\FiscalTime;
use MonteFiskal\Outbox\StoredRequest;
use MonteFiskal\Transport\FiscalOperation;

/**
 * The inspector reports (BR-X3) and their CSV export; the same results as the .NET InspectorReports.
 * A deposit is {operation, amount, changeDateTime}.
 */
final class InspectorReports
{
    /**
     * (2) Daily fiscal report for one TCR, 00:00 to 23:59:59 local time.
     *
     * @param list<ReportInvoice> $invoices
     * @param list<array{operation: string, amount: string, changeDateTime: string}> $deposits
     */
    public static function daily(string $date, string $tcr, string $businessUnit, array $invoices, array $deposits): TurnoverReport
    {
        return self::turnover('Daily fiscal report', $date, $date, $tcr, $businessUnit, $invoices, $deposits);
    }

    /**
     * (3) Periodic fiscal turnover report.
     *
     * @param list<ReportInvoice> $invoices
     * @param list<array{operation: string, amount: string, changeDateTime: string}> $deposits
     */
    public static function periodic(string $from, string $to, string $tcr, string $businessUnit, array $invoices, array $deposits): TurnoverReport
    {
        return self::turnover('Periodic turnover report', $from, $to, $tcr, $businessUnit, $invoices, $deposits);
    }

    /**
     * (1) Fiscal state cut: current turnover of the day up to now.
     *
     * @param list<ReportInvoice> $invoices
     * @param list<array{operation: string, amount: string, changeDateTime: string}> $deposits
     */
    public static function stateCut(\DateTimeImmutable $nowLocal, string $tcr, string $businessUnit, array $invoices, array $deposits): TurnoverReport
    {
        $day = $nowLocal->format('Y-m-d');
        $before = static fn (string $wire): bool => FiscalTime::parse($wire) <= $nowLocal;
        return self::turnover(
            'Fiscal state cut',
            $day,
            $day,
            $tcr,
            $businessUnit,
            array_values(array_filter($invoices, static fn (ReportInvoice $i): bool => $before($i->issueDateTime))),
            array_values(array_filter($deposits, static fn (array $d): bool => $before($d['changeDateTime'])))
        );
    }

    /**
     * (4) Analytic journal, (5) offline journal, (6) corrective journal, each with daily subtotals.
     *
     * @param list<ReportInvoice> $invoices
     * @param 'analytic'|'offline'|'corrective' $kind
     * @return list<array{day: string, invoices: list<ReportInvoice>, subtotal: string}>
     */
    public static function journal(string $from, string $to, array $invoices, string $kind = 'analytic'): array
    {
        $days = [];
        foreach ($invoices as $i) {
            if (!self::inRange($i->issueDateTime, $from, $to)) {
                continue;
            }
            if (($kind === 'offline' && $i->fiscalized) || ($kind === 'corrective' && $i->kind !== 'CORRECTIVE')) {
                continue;
            }
            $days[substr($i->issueDateTime, 0, 10)][] = $i;
        }
        ksort($days, SORT_STRING);
        $out = [];
        foreach ($days as $day => $list) {
            usort($list, static fn (ReportInvoice $a, ReportInvoice $b): int => strcmp($a->issueDateTime, $b->issueDateTime));
            $out[] = ['day' => (string) $day, 'invoices' => $list, 'subtotal' => Dec::sum(array_map(static fn (ReportInvoice $x): string => $x->total, $list))];
        }
        return $out;
    }

    /**
     * @param list<ReportInvoice> $invoices
     * @param list<array{operation: string, amount: string, changeDateTime: string}> $deposits
     */
    private static function turnover(string $title, string $from, string $to, string $tcr, string $bu, array $invoices, array $deposits): TurnoverReport
    {
        $inv = array_values(array_filter($invoices, static fn (ReportInvoice $i): bool => self::inRange($i->issueDateTime, $from, $to)));
        $dep = array_values(array_filter($deposits, static fn (array $d): bool => self::inRange($d['changeDateTime'], $from, $to)));
        $rates = [];
        $methods = [];
        $banknotes = '0';
        foreach ($inv as $i) {
            foreach ($i->sameTaxes as $g) {
                $r = $rates[$g['vatRate']] ?? ['vatRate' => $g['vatRate'], 'priceBeforeVat' => '0', 'vatAmount' => '0'];
                $r['priceBeforeVat'] = Dec::add($r['priceBeforeVat'], $g['priceBeforeVat']);
                $r['vatAmount'] = Dec::add($r['vatAmount'], $g['vatAmount']);
                $rates[$g['vatRate']] = $r;
            }
            foreach ($i->payments as $p) {
                $methods[$p['method']] = Dec::add($methods[$p['method']] ?? '0', $p['amount']);
                if ($p['method'] === 'BANKNOTE') {
                    $banknotes = Dec::add($banknotes, $p['amount']);
                }
            }
        }
        $byRate = array_values($rates);
        usort($byRate, static function (array $a, array $b): int {
            $na = is_numeric($a['vatRate']);
            $nb = is_numeric($b['vatRate']);
            if ($na && $nb && ($c = Dec::cmp($a['vatRate'], $b['vatRate'])) !== 0) {
                return $c;
            }
            if ($na !== $nb) {
                return $na ? -1 : 1;
            }
            return strcmp($a['vatRate'], $b['vatRate']);
        });
        ksort($methods, SORT_STRING);
        $byMethod = [];
        foreach ($methods as $m => $a) {
            $byMethod[] = ['method' => (string) $m, 'amount' => $a];
        }
        $initials = array_values(array_filter($dep, static fn (array $d): bool => $d['operation'] === 'INITIAL'));
        usort($initials, static fn (array $a, array $b): int => strcmp($a['changeDateTime'], $b['changeDateTime']));
        $initial = $initials === [] ? '0' : $initials[count($initials) - 1]['amount'];
        $withdrawals = Dec::sum(array_map(static fn (array $d): string => $d['operation'] === 'WITHDRAW' ? $d['amount'] : '0', $dep));
        $operators = array_values(array_unique(array_filter(array_map(static fn (ReportInvoice $i): string => $i->operator, $inv), static fn (string $o): bool => $o !== '')));
        sort($operators, SORT_STRING);
        $sum = static fn (string $field): string => Dec::sum(array_map(static fn (ReportInvoice $i): string => $i->{$field}, $inv));
        return new TurnoverReport(
            $title,
            $tcr,
            $bu,
            $from,
            $to,
            count($inv),
            count(array_filter($inv, static fn (ReportInvoice $i): bool => $i->kind === 'CORRECTIVE')),
            count(array_filter($inv, static fn (ReportInvoice $i): bool => !$i->fiscalized)),
            $sum('totalWithoutVat'),
            $sum('vatAmount'),
            $sum('total'),
            $byRate,
            $byMethod,
            // BR-D3: expected cash = initial + BANKNOTE sales - withdrawals; card payments do not change it.
            $initial,
            $withdrawals,
            Dec::sub(Dec::add($initial, $banknotes), $withdrawals),
            $operators
        );
    }

    /** CSV (semicolon separated, UTF-8) of a turnover report. */
    public static function toCsv(TurnoverReport $r): string
    {
        $rows = [['report', $r->title], ['tcr', $r->tcr], ['businessUnit', $r->businessUnit], ['from', $r->from], ['to', $r->to],
            ['operators', implode(' ', $r->operators)], ['invoiceCount', (string) $r->invoiceCount], ['correctiveCount', (string) $r->correctiveCount],
            ['offlineCount', (string) $r->offlineCount], ['totalWithoutVat', Dec::money($r->totalWithoutVat)], ['vatAmount', Dec::money($r->vatAmount)],
            ['total', Dec::money($r->total)]];
        foreach ($r->byVatRate as $v) {
            $rows[] = ['vat', $v['vatRate'], Dec::money($v['priceBeforeVat']), Dec::money($v['vatAmount'])];
        }
        foreach ($r->byPaymentMethod as $p) {
            $rows[] = ['payment', $p['method'], Dec::money($p['amount'])];
        }
        $rows[] = ['initialDeposit', Dec::money($r->initialDeposit)];
        $rows[] = ['withdrawals', Dec::money($r->withdrawals)];
        $rows[] = ['expectedCash', Dec::money($r->expectedCash)];
        $csv = static fn (string $v): string => strpbrk($v, ";\"\n\r") !== false ? '"' . str_replace('"', '""', $v) . '"' : $v;
        return implode('', array_map(static fn (array $row): string => implode(';', array_map($csv, $row)) . "\n", $rows));
    }

    /**
     * Reads stored requests of a TCR into report records.
     *
     * @param list<StoredRequest> $stored
     * @return array{invoices: list<ReportInvoice>, deposits: list<array{operation: string, amount: string, changeDateTime: string}>}
     */
    public static function fromStore(array $stored, string $tcr): array
    {
        $invoices = [];
        $deposits = [];
        foreach ($stored as $s) {
            if ($s->tcr !== $tcr) {
                continue;
            }
            if ($s->operation === FiscalOperation::RegisterInvoice) {
                $invoices[] = ReportInvoice::fromStored($s);
            } elseif ($s->operation === FiscalOperation::RegisterCashDeposit && $s->state === InvoiceState::Fiscalized) {
                $doc = new \DOMDocument();
                $doc->loadXML($s->body, LIBXML_NONET);
                $e = $doc->documentElement;
                $deposits[] = ['operation' => $e->getAttribute('Operation'), 'amount' => $e->getAttribute('CashAmt'), 'changeDateTime' => $e->getAttribute('ChangeDateTime')];
            }
        }
        return ['invoices' => $invoices, 'deposits' => $deposits];
    }

    private static function inRange(string $wire, string $from, string $to): bool
    {
        $d = substr($wire, 0, 10);
        return $d >= $from && $d <= $to;
    }
}
