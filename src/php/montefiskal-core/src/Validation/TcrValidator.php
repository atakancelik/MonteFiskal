<?php

declare(strict_types=1);

namespace MonteFiskal\Validation;

use MonteFiskal\Model\TcrRegistration;

/** Business rules for TCR registration (BR-R2, BR-R4). */
final class TcrValidator
{
    /** @param string $today yyyy-mm-dd in the profile's time zone */
    public static function validate(TcrRegistration $registration, string $today, bool $isNewRegistration = true): ValidationResult
    {
        $f = new FindingList();
        // BR-R2: software and maintainer codes are mandatory for a new registration.
        if ($isNewRegistration && (($registration->software ?? '') === '' || ($registration->maintainer ?? '') === '')) {
            $f->error('BR-R2', 'TCR/@SoftCode', 'A new TCR registration needs the software code and the maintainer code.');
        }
        // BR-R4: ValidFrom and ValidTo cannot be in the past and ValidTo cannot precede ValidFrom.
        $from = self::date($registration->validFrom);
        $to = self::date($registration->validTo);
        if ($from !== null && $from < $today) {
            $f->error('BR-R4', 'TCR/@ValidFrom', 'ValidFrom is in the past.');
        }
        if ($to !== null && $to < $today) {
            $f->error('BR-R4', 'TCR/@ValidTo', 'ValidTo is in the past.');
        }
        if ($from !== null && $to !== null && $to < $from) {
            $f->error('BR-R4', 'TCR/@ValidTo', 'ValidTo precedes ValidFrom.');
        }
        return $f->result();
    }

    private static function date(?string $s): ?string
    {
        if ($s === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y) ? $s : null;
    }
}
