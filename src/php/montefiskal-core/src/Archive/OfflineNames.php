<?php

declare(strict_types=1);

namespace MonteFiskal\Archive;

use DateTimeImmutable;
use MonteFiskal\Numbering\FiscalTime;

/** File and archive names of the offline export (BR-O11). */
final class OfflineNames
{
    /** Maximum size of one export archive: 15 MB. */
    public const MAX_ZIP_BYTES = 15 * 1024 * 1024;

    /** yyyyMMddHHmmss of the local time as written in the request. */
    public static function stamp(DateTimeImmutable $localTime): string
    {
        return $localTime->format('YmdHis');
    }

    /** Parses a wire date-time keeping its offset. */
    public static function parseWire(string $issueDateTime): DateTimeImmutable
    {
        return FiscalTime::parse($issueDateTime);
    }

    public static function requestFile(string $issueDateTime, string $tcr, string $iic): string
    {
        return self::stamp(self::parseWire($issueDateTime)) . "_{$tcr}_{$iic}_request.xml";
    }

    public static function responseFile(string $issueDateTime, string $tcr, string $iic): string
    {
        return self::stamp(self::parseWire($issueDateTime)) . "_{$tcr}_{$iic}_response.xml";
    }

    public static function requestZip(DateTimeImmutable $exportedAtLocal, string $tcr): string
    {
        return self::stamp($exportedAtLocal) . "_{$tcr}_request.zip";
    }

    public static function responseZip(DateTimeImmutable $exportedAtLocal, string $tcr): string
    {
        return self::stamp($exportedAtLocal) . "_{$tcr}_response.zip";
    }
}
