<?php

declare(strict_types=1);

namespace MonteFiskal\Numbering;

use DateTimeImmutable;

/** Invoice number (BR-N1, BR-N2, BR-N4). */
final class InvoiceNumber
{
    /** BusinUnitCode/InvOrdNum/year/TCRCode, the year taken from local time. */
    public static function format(string $businessUnit, int $ordinal, DateTimeImmutable $issuedAt, string $tcr, string $timeZone): string
    {
        // BR-N2: the ordinal is a positive number.
        if ($ordinal <= 0) {
            throw new \OutOfRangeException('BR-N2: the ordinal must be at least 1.');
        }
        $year = FiscalTime::localYear($issuedAt, $timeZone);
        return "{$businessUnit}/{$ordinal}/{$year}/{$tcr}";
    }
}
