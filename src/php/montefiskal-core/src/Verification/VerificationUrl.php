<?php

declare(strict_types=1);

namespace MonteFiskal\Verification;

use MonteFiskal\Model\FiscalEnvironment;
use MonteFiskal\Security\IkofParameters;
use MonteFiskal\VerificationUrlEncoding;

/** Verification URL printed as QR code (BR-Q1 to BR-Q6). */
final class VerificationUrl
{
    /** BR-Q3. */
    public const TEST_BASE = 'https://efitest.tax.gov.me/ic/#/verify';
    /** BR-Q4. */
    public const PRODUCTION_BASE = 'https://mapr.tax.gov.me/ic/#/verify';

    public static function baseFor(FiscalEnvironment $environment): string
    {
        return $environment === FiscalEnvironment::Production ? self::PRODUCTION_BASE : self::TEST_BASE;
    }

    /** BR-Q2: parameters in the order iic, tin, crtd, ord, bu, cr, sw, prc. */
    public static function build(string $baseUrl, string $iic, IkofParameters $p, VerificationUrlEncoding $encoding = VerificationUrlEncoding::Percent): string
    {
        $values = ['iic' => $iic, 'tin' => $p->issuerTin, 'crtd' => $p->issueDateTime, 'ord' => $p->ordinal,
            'bu' => $p->businessUnit, 'cr' => $p->tcr, 'sw' => $p->software, 'prc' => $p->totalPrice];
        $parts = [];
        foreach ($values as $k => $v) {
            $parts[] = $k . '=' . ($encoding === VerificationUrlEncoding::Percent ? self::encode($v) : $v);
        }
        return $baseUrl . '?' . implode('&', $parts);
    }

    /** Percent-encoding of everything except RFC 3986 unreserved characters (BR-Q6, Q-06 default). */
    public static function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
