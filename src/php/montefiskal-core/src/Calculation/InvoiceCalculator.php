<?php

declare(strict_types=1);

namespace MonteFiskal\Calculation;

use MonteFiskal\Model\InvoiceLine;

/**
 * Default calculation. Reproduces the production behaviour described in BR-A5: item amounts with four
 * decimals, rounded half away from zero, VA = PA - PB; two decimals only for groups and totals.
 * For gross-priced lines the price with VAT is authoritative (BR-A7), so the total equals what was charged.
 */
final class InvoiceCalculator implements RoundingPolicy
{
    private static ?self $default = null;

    public static function default(): self
    {
        return self::$default ??= new self();
    }

    public function calculate(array $lines, bool $issuerInVat, int $itemDecimals = 4): CalculationResult
    {
        if ($itemDecimals < 2 || $itemDecimals > 4) {
            throw new \OutOfRangeException('BR-A1: item amounts have two to four decimals.');
        }
        $allGross = $lines !== [] && array_all($lines, static fn (InvoiceLine $l): bool => $l->priceIncludesVat);
        $out = [];
        foreach ($lines as $l) {
            $out[] = $this->line($l, $issuerInVat, $itemDecimals);
        }
        $spb = Dec::sum(array_map(static fn (CalculatedLine $x): string => $x->priceBeforeVat, $out));
        $sva = Dec::sum(array_map(static fn (CalculatedLine $x): string => $x->vatAmount, $out));
        $spa = Dec::sum(array_map(static fn (CalculatedLine $x): string => $x->priceAfterVat, $out));
        if (!$issuerInVat) {
            // BR-V6: outside VAT the prices before and after VAT are equal and there is no VAT total.
            $totals = new CalculatedTotals(Dec::money($spb), null, Dec::money($spb));
        } elseif ($allGross) {
            // BR-A7: the charged total is authoritative for gross-priced invoices.
            $tp = Dec::round($spa, 2);
            $tv = Dec::round($sva, 2);
            $totals = new CalculatedTotals(Dec::money(Dec::sub($tp, $tv)), Dec::money($tv), Dec::money($tp));
        } else {
            // BR-A5, BR-A6: round to two decimals only at total level.
            $tw = Dec::round($spb, 2);
            $tv = Dec::round($sva, 2);
            $totals = new CalculatedTotals(Dec::money($tw), Dec::money($tv), Dec::money(Dec::add($tw, $tv)));
        }

        $groups = [];
        if ($issuerInVat) {
            // BR-V3: one SameTax per rate or exemption, in order of first appearance.
            $keys = [];
            foreach ($out as $x) {
                if (!in_array($x->groupKey, $keys, true)) {
                    $keys[] = $x->groupKey;
                }
            }
            foreach ($keys as $key) {
                $g = array_values(array_filter($out, static fn (CalculatedLine $x): bool => $x->groupKey === $key));
                $st = [
                    'NumOfItems' => (string) count($g),
                    'PriceBefVAT' => Dec::money(Dec::sum(array_map(static fn (CalculatedLine $x): string => $x->priceBeforeVat, $g))),
                ];
                if ($key !== null && str_starts_with($key, 'VR:')) {
                    $st['VATRate'] = substr($key, 3);
                    $st['VATAmt'] = Dec::money(Dec::sum(array_map(static fn (CalculatedLine $x): string => $x->vatAmount, $g)));
                } elseif ($key !== null) {
                    $st['ExemptFromVAT'] = substr($key, 3);
                }
                $groups[] = $st;
            }
        }
        return new CalculationResult($out, $totals, $groups);
    }

    private function line(InvoiceLine $l, bool $inVat, int $dec): CalculatedLine
    {
        $q = $l->quantity;
        $p = $l->unitPrice;
        $f = $l->rebate !== null ? Dec::sub('1', Dec::div($l->rebate, '100')) : '1';
        $vr = $l->vatRate;
        $va = null;
        if (!$inVat || $vr === null) {
            $upb = Dec::round($p, 4);
            $upa = $upb;
            $pb = Dec::round(Dec::mul(Dec::mul($upb, $q), $f), 4);
            $pa = $pb;
        } elseif (!$l->priceIncludesVat) {
            // BR-A5: UPA = UPB x (1 + VR/100), PB = UPB x Q, PA = UPA x Q, VA = PA - PB.
            $upb = Dec::round($p, 4);
            $upa = Dec::round(Dec::mul($upb, Dec::add('1', Dec::div($vr, '100'))), 4);
            $pb = Dec::round(Dec::mul(Dec::mul($upb, $q), $f), 4);
            $pa = Dec::round(Dec::mul(Dec::mul($upa, $q), $f), 4);
            $va = Dec::sub($pa, $pb);
        } else {
            // BR-A7: from the gross price; the line total with VAT is authoritative.
            $k = Dec::add('1', Dec::div($vr, '100'));
            $upa = Dec::round($p, 4);
            $upb = Dec::round(Dec::div($upa, $k), 4);
            $pa = Dec::round(Dec::mul(Dec::mul($upa, $q), $f), 4);
            $pb = Dec::round(Dec::div($pa, $k), 4);
            $va = Dec::sub($pa, $pb);
        }

        $a = ['N' => $l->name];
        if ($l->code !== null && $l->code !== '') {
            $a['C'] = $l->code;
        }
        $a['U'] = $l->unit;
        $a['Q'] = Dec::quantity($q);
        $a['UPB'] = Dec::fixed($upb, $dec);
        $a['UPA'] = Dec::fixed($upa, $dec);
        if ($l->rebate !== null) {
            $a['R'] = Dec::money($l->rebate);
            // BR-A5: RR states that the rebate reduces the base; only "true" is supported (see docs/STATUS.md).
            $a['RR'] = 'true';
        }
        $a['PB'] = Dec::fixed($pb, $dec);
        $group = null;
        if ($inVat && $vr !== null) {
            $a['VR'] = Dec::money($vr);
            $a['VA'] = Dec::fixed((string) $va, $dec);
            $group = 'VR:' . Dec::money($vr);
        } elseif ($inVat) {
            $group = 'EX:' . $l->exemption;
        }
        if ($l->investment === true) {
            $a['IN'] = 'true';
        }
        $a['PA'] = Dec::fixed($pa, $dec);
        if ($inVat && $l->exemption !== null && $l->exemption !== '') {
            $a['EX'] = $l->exemption;
        }
        if ($l->voucherExpiry !== null && $l->voucherExpiry !== '') {
            $a['VD'] = $l->voucherExpiry;
        }
        if ($l->voucherSerial !== null && $l->voucherSerial !== '') {
            $a['VSN'] = $l->voucherSerial;
        }
        return new CalculatedLine($a, $pb, $va ?? '0', $pa, $group);
    }
}
