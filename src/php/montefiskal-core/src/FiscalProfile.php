<?php

declare(strict_types=1);

namespace MonteFiskal;

use MonteFiskal\Model\Arr;
use MonteFiskal\Model\FiscalEnvironment;

/**
 * The taxpayer and device data that every request needs. All codes come from the user's registration on the
 * Tax Administration portal; the library never invents them (architecture section 1, principle 6).
 */
final readonly class FiscalProfile
{
    public function __construct(
        /** Tax identification number (PIB, 8 digits, or JMB, 13 digits). */
        public string $issuerTin,
        public string $issuerName,
        /** Business unit code (BusinUnitCode). */
        public string $businessUnit,
        /** Software code of the registered software version (BR-R7). */
        public string $software,
        /** Operator code of the person issuing invoices (BR-K16). */
        public string $operator,
        /** TCR code returned by RegisterTCR (BR-R5). Empty only while the TCR is being registered. */
        public string $tcr = '',
        public FiscalEnvironment $environment = FiscalEnvironment::Test,
        public ?string $issuerAddress = null,
        public ?string $issuerTown = null,
        /** ISO 3166 alpha-3 code, MNE for Montenegro. */
        public ?string $issuerCountry = 'MNE',
        /** Must equal the status in the VAT register (BR-V1). */
        public bool $issuerInVat = false,
        /** Maintainer code (BR-R7); needed for a new TCR registration. */
        public ?string $maintainer = null,
        /** IANA time zone for IssueDateTime and the year in the invoice number (BR-N4). */
        public string $timeZone = 'Europe/Podgorica',
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            issuerTin: (string) $a['issuerTin'],
            issuerName: (string) $a['issuerName'],
            businessUnit: (string) $a['businessUnit'],
            software: (string) $a['software'],
            operator: (string) $a['operator'],
            tcr: (string) ($a['tcr'] ?? ''),
            environment: isset($a['environment']) ? FiscalEnvironment::from($a['environment']) : FiscalEnvironment::Test,
            issuerAddress: $a['issuerAddress'] ?? null,
            issuerTown: $a['issuerTown'] ?? null,
            issuerCountry: array_key_exists('issuerCountry', $a) ? $a['issuerCountry'] : 'MNE',
            issuerInVat: (bool) ($a['issuerInVat'] ?? false),
            maintainer: $a['maintainer'] ?? null,
            timeZone: (string) ($a['timeZone'] ?? 'Europe/Podgorica'),
        );
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
