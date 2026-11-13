<?php

declare(strict_types=1);

namespace MonteFiskal\Text;

/** Character checks and optional transliteration (BR-T8, Q-07). */
final class TextRules
{
    private const MAP = [
        'č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'dj', 'Č' => 'C', 'Ć' => 'C', 'Š' => 'S', 'Ž' => 'Z', 'Đ' => 'Dj',
        'ś' => 's', 'Ś' => 'S', 'ź' => 'z', 'Ź' => 'Z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U',
        'ß' => 'ss', 'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', 'ş' => 's', 'Ş' => 'S',
        '€' => 'EUR', '–' => '-', '—' => '-', '„' => '"', '“' => '"', '”' => '"', '‘' => "'", '’' => "'",
    ];

    /** @var array<int, string>|null */
    private static ?array $nfd = null;

    /** BR-T8: allowed codes are 9, 10, 13 and 32 to 126. Other control characters are never allowed. */
    public static function hasControlCharacter(?string $s): bool
    {
        return $s !== null && preg_match('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}-\x{9F}]/u', $s) === 1;
    }

    /** True when the text contains characters outside printable ASCII (BR-T8, UNVERIFIED enforcement). */
    public static function hasNonAscii(?string $s): bool
    {
        return $s !== null && preg_match('/[^\x00-\x7E]/', $s) === 1;
    }

    /** Montenegrin Latin to ASCII (č to c, đ to dj, ...); other letters lose their diacritics; the rest becomes '?'. */
    public static function transliterate(string $s): string
    {
        self::$nfd ??= require __DIR__ . '/nfd-ascii.php';
        $out = '';
        foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
            $cp = mb_ord($c, 'UTF-8');
            if ($cp <= 126) {
                $out .= $c;
            } elseif (isset(self::MAP[$c])) {
                $out .= self::MAP[$c];
            } elseif ($cp > 0xFFFF) {
                // .NET works on UTF-16 code units: a supplementary character becomes two '?'.
                $out .= '??';
            } else {
                $out .= self::$nfd[$cp] ?? '?';
            }
        }
        return $out;
    }
}
