<?php

declare(strict_types=1);

namespace MonteFiskal\Rendering;

use MonteFiskal\Calculation\Dec;

/** Printable A4 HTML with the QR code (BR-P1, BR-P2, BR-Q8). The same layout as the .NET HtmlReceipt. */
final class HtmlReceipt
{
    public static function render(Receipt $r, string $language = 'me'): string
    {
        $t = new ReceiptLabels($language);
        $e = static fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $l = static fn (string $k): string => $e($t->get($k));
        $s = '<!doctype html><html lang="' . $e($language) . '"><head><meta charset="utf-8"><title>' . $e($r->invoiceNumber) . '</title><style>'
            . 'body{font:12pt/1.4 system-ui,sans-serif;margin:16mm;color:#111}table{border-collapse:collapse;width:100%}td,th{padding:2mm;border-bottom:1px solid #ccc;text-align:left}'
            . '.num{text-align:right}.codes td{border:0;padding:0 2mm 0 0;font-size:10pt}.qr{margin-top:8mm}.test{color:#b00;font-weight:700;border:2px solid #b00;padding:2mm;text-align:center}'
            . '@page{size:A4;margin:12mm}</style></head><body>';
        if ($r->testMode) {
            $s .= '<p class="test">' . $l('testMode') . '</p>';
        }
        $s .= '<h1>' . $l($r->invoiceType === 'CORRECTIVE' ? 'corrective' : 'fiscalInvoice') . '</h1>';
        $s .= '<p><strong>' . $e($r->sellerName) . '</strong><br>' . $e($r->sellerAddress) . ' ' . $e($r->sellerTown) . '<br>' . $l('tin') . ': ' . $e($r->sellerTin) . '</p>';
        if ($r->buyerName !== null) {
            $s .= '<p>' . $l('buyer') . ': ' . $e($r->buyerName) . ', ' . $l('tin') . ': ' . $e($r->buyerTin) . '</p>';
        }
        $s .= '<table class="codes">';
        $row = static fn (string $k, ?string $v): string => '<tr><td>' . $l($k) . '</td><td>' . $e($v) . '</td></tr>';
        $s .= $row('invoiceNumber', $r->invoiceNumber) . $row('issued', substr(str_replace('T', ' ', $r->issueDateTime), 0, 16)) . $row('businessUnit', $r->businessUnit)
            . $row('tcr', $r->tcr) . $row('software', $r->software) . $row('operator', $r->operator);
        if ($r->correctedIic !== null) {
            $s .= $row('reference', $r->correctedIic);
        }
        $s .= '</table><table><thead><tr><th>' . $l('item') . '</th><th class="num">' . $l('quantity') . '</th><th class="num">' . $l('unitPrice')
            . '</th><th class="num">' . $l('vatRate') . '</th><th class="num">' . $l('amount') . '</th></tr></thead><tbody>';
        foreach ($r->lines as $x) {
            $s .= '<tr><td>' . $e($x->name) . '</td><td class="num">' . $e($x->quantity) . ' ' . $e($x->unit) . '</td><td class="num">' . $e(self::money($x->unitPriceWithVat))
                . '</td><td class="num">' . $e($x->vatRate ?? $x->exemption ?? '') . '</td><td class="num">' . $e(self::money($x->amountWithVat)) . '</td></tr>';
        }
        $s .= '</tbody></table><table>';
        foreach ($r->vatGroups as $g) {
            $s .= '<tr><td>' . $l('vat') . ' ' . $e($g->key) . '</td><td class="num">' . $l('base') . ' ' . $e($g->base) . '</td><td class="num">' . $e($g->vat ?? '') . '</td></tr>';
        }
        $s .= '<tr><td>' . $l('totalWithoutVat') . '</td><td></td><td class="num">' . $e($r->totalWithoutVat) . '</td></tr>';
        if ($r->totalVat !== null) {
            $s .= '<tr><td>' . $l('totalVat') . '</td><td></td><td class="num">' . $e($r->totalVat) . '</td></tr>';
        }
        $s .= '<tr><th>' . $l('total') . '</th><td></td><th class="num">' . $e($r->total) . ' EUR</th></tr>';
        foreach ($r->payments as [$m, $a]) {
            $s .= '<tr><td>' . $l('payment') . ': ' . $e($t->method($m)) . '</td><td></td><td class="num">' . $e($a) . '</td></tr>';
        }
        if ($r->payDeadline !== null) {
            $s .= '<tr><td>' . $l('payDeadline') . '</td><td></td><td class="num">' . $e($r->payDeadline) . '</td></tr>';
        }
        $s .= '</table><p>' . $l('iic') . ': <code>' . $e($r->iic) . '</code><br>' . $l('fic') . ': ';
        $s .= ($r->fic === null ? $l('notRegistered') : '<code>' . $e($r->fic) . '</code>') . '</p>';
        $s .= '<div class="qr">' . QrImages::svg(QrCode::encode($r->verificationUrl), 30) . '<br><a href="' . $e($r->verificationUrl) . '">' . $l('verify') . '</a></div></body></html>';
        return $s;
    }

    private static function money(string $s): string
    {
        return is_numeric($s) ? Dec::money(Dec::of($s)) : $s;
    }
}
