<?php

declare(strict_types=1);

namespace MonteFiskal\Rendering;

/**
 * QR code encoder following ISO/IEC 18004: byte mode, error correction level M (BR-Q5), versions 1 to 40,
 * mask chosen by the penalty rules of section 7.8.3. Written for this project; a line-by-line twin of the .NET
 * MonteFiskal.Rendering.QrCode, checked by the shared qr vectors.
 */
final class QrCode
{
    private const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
    private const BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];

    public readonly int $size;
    public private(set) int $mask = 0;
    /** @var array<int, array<int, int>> [y][x] */
    private array $modules;
    /** @var array<int, array<int, bool>> [y][x] */
    private array $function;

    private function __construct(public readonly int $version)
    {
        $this->size = $version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, 0));
        $this->function = array_fill(0, $this->size, array_fill(0, $this->size, false));
    }

    /** True when the module at column $x, row $y is dark. */
    public function get(int $x, int $y): bool
    {
        return $this->modules[$y][$x] === 1;
    }

    /** Encodes UTF-8 text in byte mode at level M with the smallest version that fits. */
    public static function encode(string $data): self
    {
        $len = strlen($data);
        $version = 1;
        for (; $version <= 40; $version++) {
            if (4 + self::countBits($version) + $len * 8 <= self::dataCodewords($version) * 8) {
                break;
            }
        }
        if ($version > 40) {
            throw new \InvalidArgumentException('Data too long for a QR code at level M.');
        }
        $bits = [];
        self::append($bits, 0b0100, 4);
        self::append($bits, $len, self::countBits($version));
        for ($i = 0; $i < $len; $i++) {
            self::append($bits, ord($data[$i]), 8);
        }
        $capacity = self::dataCodewords($version) * 8;
        self::append($bits, 0, min(4, $capacity - count($bits)));
        self::append($bits, 0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            self::append($bits, $pad, 8);
        }
        $codewords = array_fill(0, intdiv(count($bits), 8), 0);
        foreach ($bits as $i => $b) {
            if ($b) {
                $codewords[$i >> 3] |= 1 << (7 - ($i & 7));
            }
        }
        $qr = new self($version);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords(self::addEccAndInterleave($codewords, $version));
        $best = -1;
        $bestPenalty = PHP_INT_MAX;
        // Masks are evaluated with format and version areas still light (ISO/IEC 18004:2015 section 7.8).
        for ($m = 0; $m < 8; $m++) {
            $qr->applyMask($m);
            $p = $qr->penalty();
            if ($p < $bestPenalty) {
                $best = $m;
                $bestPenalty = $p;
            }
            $qr->applyMask($m);
        }
        $qr->mask = $best;
        $qr->applyMask($best);
        $qr->drawFormatBits($best);
        $qr->drawVersion();
        return $qr;
    }

    /** @return list<string> Rows of 0/1, as in the qr vectors. */
    public function rows(): array
    {
        return array_map(static fn (array $r): string => implode('', $r), $this->modules);
    }

    private static function countBits(int $version): int
    {
        return $version <= 9 ? 8 : 16;
    }

    private static function rawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function dataCodewords(int $ver): int
    {
        return intdiv(self::rawDataModules($ver), 8) - self::ECC_PER_BLOCK[$ver] * self::BLOCKS[$ver];
    }

    /** @param list<int> $bits */
    private static function append(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function addEccAndInterleave(array $data, int $ver): array
    {
        $numBlocks = self::BLOCKS[$ver];
        $eccLen = self::ECC_PER_BLOCK[$ver];
        $raw = intdiv(self::rawDataModules($ver), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $divisor = self::reedSolomonDivisor($eccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $block = array_pad($dat, $shortLen + 1 - $eccLen, 0);
            $blocks[] = array_merge($block, self::reedSolomonRemainder($dat, $divisor));
        }
        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $b) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $result[] = $b[$i];
                }
            }
        }
        return $result;
    }

    /** @return list<int> */
    private static function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::multiply($root, 0x02);
        }
        return $result;
    }

    /**
     * @param list<int> $data
     * @param list<int> $divisor
     * @return list<int>
     */
    private static function reedSolomonRemainder(array $data, array $divisor): array
    {
        $n = count($divisor);
        $result = array_fill(0, $n, 0);
        foreach ($data as $b) {
            $factor = $b ^ $result[0];
            array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $n; $i++) {
                $result[$i] ^= self::multiply($divisor[$i], $factor);
            }
        }
        return $result;
    }

    private static function multiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark ? 1 : 0;
        $this->function[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);
        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (!(($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0))) {
                    $this->drawAlignment($pos[$i], $pos[$j]);
                }
            }
        }
        // Reserve format and version areas as light function modules; they are drawn after masking.
        $this->drawFormatBits(0);
        $this->drawVersion();
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFormatOrVersion($x, $y)) {
                    $this->modules[$y][$x] = 0;
                }
            }
        }
    }

    private function isFormatOrVersion(int $x, int $y): bool
    {
        $s = $this->size;
        return ($y === 8 && $x !== 6 && ($x <= 8 || $x >= $s - 8)) || ($x === 8 && $y !== 6 && ($y <= 8 || $y >= $s - 8))
            || ($this->version >= 7 && (($x >= $s - 11 && $x < $s - 8 && $y < 6) || ($y >= $s - 11 && $y < $s - 8 && $x < 6)));
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : intdiv($this->version * 4 + $numAlign * 2 + 1, $numAlign * 2 - 2) * 2;
        $result = array_fill(0, $numAlign, 0);
        $result[0] = 6;
        for ($i = $numAlign - 1, $p = $this->size - 7; $i >= 1; $i--, $p -= $step) {
            $result[$i] = $p;
        }
        return $result;
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->set($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->set($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0 << 3) | $mask; // level M has format bits 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) !== 0;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($s - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $s - 15 + $i, $bit($i));
        }
        $this->set(8, $s - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->set($a, $b, $bit);
            $this->set($b, $a, $bit);
        }
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        $s = $this->size;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $s - 1 - $vert : $vert;
                    if (!$this->function[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = ($data[$i >> 3] >> (7 - ($i & 7))) & 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        $s = $this->size;
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
                };
                if ($invert && !$this->function[$y][$x]) {
                    $this->modules[$y][$x] ^= 1;
                }
            }
        }
    }

    /** Penalty score of ISO/IEC 18004 section 7.8.3 (N1 = 3, N2 = 3, N3 = 40, N4 = 10). */
    private function penalty(): int
    {
        $s = $this->size;
        $m = $this->modules;
        $score = 0;
        $dark = 0;
        for ($i = 0; $i < $s; $i++) {
            $row = $m[$i];
            $col = array_column($m, $i);
            $dark += array_sum($row);
            $score += self::runPenalty($row) + self::runPenalty($col) + self::finderPenalty($row) + self::finderPenalty($col);
        }
        for ($y = 1; $y < $s; $y++) {
            for ($x = 1; $x < $s; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x - 1] && $c === $m[$y - 1][$x] && $c === $m[$y - 1][$x - 1]) {
                    $score += 3;
                }
            }
        }
        $total = $s * $s;
        return $score + 10 * intdiv(abs($dark * 100 - 50 * $total), 5 * $total);
    }

    /** @param list<int> $line */
    private static function runPenalty(array $line): int
    {
        $score = 0;
        $run = 1;
        $n = count($line);
        for ($i = 1; $i < $n; $i++) {
            if ($line[$i] === $line[$i - 1]) {
                $run++;
            } else {
                if ($run >= 5) {
                    $score += $run - 2;
                }
                $run = 1;
            }
        }
        return $run >= 5 ? $score + $run - 2 : $score;
    }

    /**
     * 1:1:3:1:1 pattern with four light modules (or the symbol edge) on at least one side.
     *
     * @param list<int> $line
     */
    private static function finderPenalty(array $line): int
    {
        $n = count($line);
        $score = 0;
        $idx = self::find($line, 0);
        while ($idx !== -1) {
            $next = $idx + 7;
            if ($idx === 0 || $idx === $n - 7 || self::allLight($line, max($idx - 4, 0), $idx) || self::allLight($line, $next, min($next + 4, $n))) {
                $score += 40;
            } else {
                $next = $idx + 4;
            }
            $idx = self::find($line, $next);
        }
        return $score;
    }

    /** @param list<int> $line */
    private static function find(array $line, int $from): int
    {
        $n = count($line);
        for ($i = $from; $i + 7 <= $n; $i++) {
            if ($line[$i] === 1 && $line[$i + 1] === 0 && $line[$i + 2] === 1 && $line[$i + 3] === 1 && $line[$i + 4] === 1 && $line[$i + 5] === 0 && $line[$i + 6] === 1) {
                return $i;
            }
        }
        return -1;
    }

    /** @param list<int> $line */
    private static function allLight(array $line, int $from, int $to): bool
    {
        for ($i = $from; $i < $to; $i++) {
            if ($line[$i] === 1) {
                return false;
            }
        }
        return true;
    }
}
