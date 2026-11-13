<?php

declare(strict_types=1);

namespace MonteFiskal\Rendering;

/** Minimal PNG writer (8-bit grayscale), used for QR codes. */
final class PngWriter
{
    /** Writes a PNG from filtered scanlines (each row starts with its filter byte). */
    public static function grayscale(int $width, int $height, string $filteredScanlines): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0);
        return "\x89PNG\r\n\x1A\n" . self::chunk('IHDR', $ihdr) . self::chunk('IDAT', (string) gzcompress($filteredScanlines, 9)) . self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
