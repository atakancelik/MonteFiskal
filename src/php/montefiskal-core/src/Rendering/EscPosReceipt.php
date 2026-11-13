<?php

declare(strict_types=1);

namespace MonteFiskal\Rendering;

use MonteFiskal\Text\TextRules;

/** ESC/POS receipt for 58 mm (32 columns) and 80 mm (48 columns) printers. Text is transliterated to ASCII. */
final class EscPosReceipt
{
    public static function render(Receipt $r, int $paperWidthMm = 80, string $language = 'me'): string
    {
        $cols = $paperWidthMm <= 58 ? 32 : 48;
        $t = new ReceiptLabels($language);
        $out = '';
        $raw = static function (int ...$b) use (&$out): void {
            $out .= pack('C*', ...$b);
        };
        $line = static function (string $s = '') use (&$out): void {
            $out .= TextRules::transliterate($s) . "\n";
        };
        $pair = static function (string $left, string $right) use ($line, $cols): void {
            $left = TextRules::transliterate($left);
            $right = TextRules::transliterate($right);
            if (strlen($left) + strlen($right) + 1 > $cols) {
                $line($left);
                $line(str_repeat(' ', max(0, $cols - strlen($right))) . $right);
            } else {
                $line($left . str_repeat(' ', max(1, $cols - strlen($left) - strlen($right))) . $right);
            }
        };
        $raw(0x1B, 0x40);
        $raw(0x1B, 0x61, 1);
        if ($r->testMode) {
            $line($t->get('testMode'));
        }
        $raw(0x1B, 0x45, 1);
        $line($r->sellerName);
        $raw(0x1B, 0x45, 0);
        $line(trim($r->sellerAddress . ' ' . $r->sellerTown));
        $line($t->get('tin') . ': ' . $r->sellerTin);
        $line($t->get($r->invoiceType === 'CORRECTIVE' ? 'corrective' : 'fiscalInvoice'));
        $raw(0x1B, 0x61, 0);
        $line(str_repeat('-', $cols));
        foreach ($r->lines as $l) {
            $line($l->name);
            $pair('  ' . $l->quantity . ' x ' . $l->unitPriceWithVat, $l->amountWithVat);
        }
        $line(str_repeat('-', $cols));
        foreach ($r->vatGroups as $g) {
            $pair($t->get('vat') . ' ' . $g->key . ' / ' . $g->base, $g->vat ?? '');
        }
        $raw(0x1B, 0x45, 1);
        $pair($t->get('total'), $r->total . ' EUR');
        $raw(0x1B, 0x45, 0);
        foreach ($r->payments as [$m, $a]) {
            $pair($t->method($m), $a);
        }
        $line(str_repeat('-', $cols));
        $line($t->get('invoiceNumber') . ': ' . $r->invoiceNumber);
        $line($t->get('issued') . ': ' . substr(str_replace('T', ' ', $r->issueDateTime), 0, 16));
        $line($t->get('businessUnit') . ': ' . $r->businessUnit);
        $line($t->get('tcr') . ': ' . $r->tcr);
        $line($t->get('software') . ': ' . $r->software);
        $line($t->get('operator') . ': ' . $r->operator);
        $line($t->get('iic') . ': ' . $r->iic);
        $line($t->get('fic') . ': ' . ($r->fic ?? $t->get('notRegistered')));
        $raw(0x1B, 0x61, 1);
        // GS ( k: QR model 2, module size, error correction M (BR-Q5), store, print.
        $data = $r->verificationUrl;
        $raw(0x1D, 0x28, 0x6B, 4, 0, 0x31, 0x41, 0x32, 0);
        $raw(0x1D, 0x28, 0x6B, 3, 0, 0x31, 0x43, $cols === 32 ? 4 : 6);
        $raw(0x1D, 0x28, 0x6B, 3, 0, 0x31, 0x45, 0x31);
        $len = strlen($data) + 3;
        $raw(0x1D, 0x28, 0x6B, $len % 256, intdiv($len, 256), 0x31, 0x50, 0x30);
        $out .= $data;
        $raw(0x1D, 0x28, 0x6B, 3, 0, 0x31, 0x51, 0x30);
        $line();
        $raw(0x1B, 0x64, 4);
        $raw(0x1D, 0x56, 0x42, 0);
        return $out;
    }
}
