<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

use DateTimeImmutable;

/** Checks a certificate before use (BR-S5, architecture section 2.4). */
final class CertificateChecks
{
    /** @return array{errors: list<string>, warnings: list<string>} */
    public static function inspect(Certificate $certificate, ?string $issuerTin, DateTimeImmutable $now): array
    {
        $errors = [];
        $warnings = [];
        $details = openssl_pkey_get_details($certificate->privateKey()) ?: [];
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            $errors[] = 'The certificate key is not RSA.';
        } elseif (($details['bits'] ?? 0) < 2048) {
            $errors[] = 'The RSA key has fewer than 2048 bits.';
        }
        $info = $certificate->info();
        $to = (int) ($info['validTo_time_t'] ?? 0);
        $from = (int) ($info['validFrom_time_t'] ?? 0);
        if ($to < $now->getTimestamp()) {
            $errors[] = 'The certificate has expired.';
        } elseif ($to - $now->getTimestamp() < 30 * 86400) {
            $warnings[] = 'The certificate expires within 30 days.';
        }
        if ($from > $now->getTimestamp()) {
            $errors[] = 'The certificate is not valid yet.';
        }
        if ($issuerTin !== null && $issuerTin !== '') {
            $subject = json_encode($info['subject'] ?? []) ?: '';
            if (!str_contains($subject, $issuerTin)) {
                // BR-S5 (UNVERIFIED): the TIN is expected in the subject; a mismatch is a warning only.
                $warnings[] = 'The issuer TIN does not appear in the certificate subject (BR-S5).';
            }
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
