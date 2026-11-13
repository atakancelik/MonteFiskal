<?php

declare(strict_types=1);

namespace MonteFiskal\Transport;

use MonteFiskal\Model\FiscalEnvironment;

/** Endpoints and SOAP actions (BR-T1, BR-T4). */
final class FiscalEndpoints
{
    public const TEST = 'https://efitest.tax.gov.me/fs-v1';
    public const PRODUCTION = 'https://efi.tax.gov.me/fs-v1';

    public static function for(FiscalEnvironment $environment): string
    {
        return $environment === FiscalEnvironment::Production ? self::PRODUCTION : self::TEST;
    }

    public static function soapAction(FiscalOperation $op): string
    {
        return match ($op) {
            FiscalOperation::RegisterInvoice => 'https://efi.tax.gov.me/fs/RegisterInvoice',
            FiscalOperation::RegisterTcr => 'https://efi.tax.gov.me/fs/RegisterTCR',
            FiscalOperation::RegisterCashDeposit => 'https://efi.tax.gov.me/fs/RegisterCashDeposit',
        };
    }

    public static function requestElement(FiscalOperation $op): string
    {
        return match ($op) {
            FiscalOperation::RegisterInvoice => 'RegisterInvoiceRequest',
            FiscalOperation::RegisterTcr => 'RegisterTCRRequest',
            FiscalOperation::RegisterCashDeposit => 'RegisterCashDepositRequest',
        };
    }
}
