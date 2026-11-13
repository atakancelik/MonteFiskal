<?php

declare(strict_types=1);

namespace MonteFiskal\Calculation;

use RoundingMode;

/**
 * Decimal arithmetic on numeric strings with BCMath. Money is never a float (architecture section 3).
 * Rounding is half away from zero (BR-A5, Q-14 default), the same as .NET MidpointRounding.AwayFromZero.
 */
final class Dec
{
    /** Working scale; .NET decimal carries 28 significant digits. */
    public const SCALE = 28;

    /** Normalises an int or numeric string; floats are refused. */
    public static function of(int|string $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        $v = trim($value);
        if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $v)) {
            throw new \InvalidArgumentException("'{$value}' is not a decimal number.");
        }
        if ($v[0] === '+') {
            $v = substr($v, 1);
        }
        if ($v[0] === '.' || str_starts_with($v, '-.')) {
            $v = str_replace('.', '0.', $v);
        }
        return $v;
    }

    public static function ofNullable(int|string|null $value): ?string
    {
        return $value === null ? null : self::of($value);
    }

    public static function add(string $a, string $b): string
    {
        return self::trim(bcadd($a, $b, self::SCALE));
    }

    public static function sub(string $a, string $b): string
    {
        return self::trim(bcsub($a, $b, self::SCALE));
    }

    public static function mul(string $a, string $b): string
    {
        return self::trim(bcmul($a, $b, self::SCALE));
    }

    public static function div(string $a, string $b): string
    {
        return self::trim(bcdiv($a, $b, self::SCALE));
    }

    public static function neg(string $a): string
    {
        return self::sub('0', $a);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    /** @param iterable<string> $values */
    public static function sum(iterable $values): string
    {
        $s = '0';
        foreach ($values as $v) {
            $s = self::add($s, $v);
        }
        return $s;
    }

    /** Rounds half away from zero to $decimals places. */
    public static function round(string $value, int $decimals): string
    {
        return self::trim(bcround($value, $decimals, RoundingMode::HalfAwayFromZero));
    }

    /** Exactly $decimals places; negative zero becomes zero. */
    public static function fixed(string $value, int $decimals): string
    {
        $r = bcround($value, $decimals, RoundingMode::HalfAwayFromZero);
        if (bccomp($r, '0', self::SCALE) === 0) {
            $r = '0';
        }
        return bcadd($r, '0', $decimals);
    }

    /** Totals and rates: two decimals. */
    public static function money(string $value): string
    {
        return self::fixed($value, 2);
    }

    /** Quantity in the shortest form, not rounded. */
    public static function quantity(string $value): string
    {
        $t = self::trim($value);
        return $t === '-0' ? '0' : $t;
    }

    /** Shortest form (exchange rates). */
    public static function plain(string $value): string
    {
        return self::quantity($value);
    }

    /** Removes trailing zeros of the fraction. */
    public static function trim(string $v): string
    {
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }
        if ($v === '' || $v === '-' || $v === '-0') {
            return '0';
        }
        return $v;
    }
}
