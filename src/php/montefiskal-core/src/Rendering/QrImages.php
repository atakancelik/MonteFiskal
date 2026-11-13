<?php

declare(strict_types=1);

namespace MonteFiskal\Rendering;

/** SVG and PNG output of a QrCode. Default quiet zone is four modules. */
final class QrImages
{
    /** SVG sized in millimetres; BR-Q8 asks for at least 21 mm x 21 mm on paper. */
    public static function svg(QrCode $qr, float $sizeMillimetres = 25, int $quietZone = 4): string
    {
        if ($sizeMillimetres < 21) {
            throw new \OutOfRangeException('BR-Q8: the printed QR code is at least 21 mm.');
        }
        $n = $qr->size + 2 * $quietZone;
        $mm = rtrim(rtrim(number_format($sizeMillimetres, 2, '.', ''), '0'), '.');
        $s = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$mm}mm\" height=\"{$mm}mm\" viewBox=\"0 0 {$n} {$n}\" shape-rendering=\"crispEdges\">"
            . "<rect width=\"{$n}\" height=\"{$n}\" fill=\"#fff\"/><path fill=\"#000\" d=\"";
        for ($y = 0; $y < $qr->size; $y++) {
            for ($x = 0; $x < $qr->size; $x++) {
                if ($qr->get($x, $y)) {
                    $s .= 'M' . ($x + $quietZone) . ' ' . ($y + $quietZone) . 'h1v1h-1z';
                }
            }
        }
        return $s . '"/></svg>';
    }

    /** Grayscale PNG with $scale pixels per module. */
    public static function png(QrCode $qr, int $scale = 8, int $quietZone = 4): string
    {
        $n = ($qr->size + 2 * $quietZone) * $scale;
        $raw = '';
        for ($py = 0; $py < $n; $py++) {
            $row = "\0"; // filter type none
            $my = intdiv($py, $scale) - $quietZone;
            for ($px = 0; $px < $n; $px++) {
                $mx = intdiv($px, $scale) - $quietZone;
                $dark = $mx >= 0 && $my >= 0 && $mx < $qr->size && $my < $qr->size && $qr->get($mx, $my);
                $row .= $dark ? "\0" : "\xFF";
            }
            $raw .= $row;
        }
        return PngWriter::grayscale($n, $n, $raw);
    }

    /** data: URI of the PNG, for e-mails that do not render SVG. */
    public static function pngDataUri(QrCode $qr, int $scale = 6): string
    {
        return 'data:image/png;base64,' . base64_encode(self::png($qr, $scale));
    }
}
