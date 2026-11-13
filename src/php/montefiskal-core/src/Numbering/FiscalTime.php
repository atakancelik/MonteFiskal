<?php

declare(strict_types=1);

namespace MonteFiskal\Numbering;

use DateTimeImmutable;
use DateTimeZone;
use MonteFiskal\DateTimeFormat;

/** Date-time formatting of the wire (S-07, BR-I4, Q-23). */
final class FiscalTime
{
    /** @var array<string, DateTimeZone> */
    private static array $zones = [];

    public static function zone(string $id): DateTimeZone
    {
        return self::$zones[$id] ??= new DateTimeZone($id);
    }

    /** Parses an ISO 8601 date-time with offset or Z, keeping fractional seconds. */
    public static function parse(string $value): DateTimeImmutable
    {
        $v = trim($value);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', self::sixDigits($v))
            ?: DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $v)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sp', $v);
        if ($d === false) {
            throw new \InvalidArgumentException("'{$value}' is not an ISO 8601 date-time with offset.");
        }
        return $d;
    }

    private static function sixDigits(string $v): string
    {
        return (string) preg_replace_callback('/\.(\d+)/', static fn (array $m): string => '.' . substr(str_pad($m[1], 6, '0'), 0, 6), $v, 1);
    }

    /** The instant in the given zone, with fractional seconds truncated. */
    public static function local(DateTimeImmutable $instant, string $timeZone): DateTimeImmutable
    {
        $ts = $instant->getTimestamp();
        return (new DateTimeImmutable('@' . $ts))->setTimezone(self::zone($timeZone));
    }

    /**
     * Local time with offset (default) or UTC with Z; seconds precision. The same string is used for the XML,
     * the IKOF and the QR code (BR-I4).
     */
    public static function format(DateTimeImmutable $instant, string $timeZone, DateTimeFormat $format = DateTimeFormat::LocalOffset): string
    {
        $local = self::local($instant, $timeZone);
        if ($format === DateTimeFormat::Utc) {
            return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        return $local->format('Y-m-d\TH:i:sP');
    }

    /** Calendar year of the instant in local time (BR-N4). */
    public static function localYear(DateTimeImmutable $instant, string $timeZone): int
    {
        return (int) self::local($instant, $timeZone)->format('Y');
    }

    /** Local calendar date, yyyy-mm-dd. */
    public static function localDate(DateTimeImmutable $instant, string $timeZone): string
    {
        return self::local($instant, $timeZone)->format('Y-m-d');
    }
}
