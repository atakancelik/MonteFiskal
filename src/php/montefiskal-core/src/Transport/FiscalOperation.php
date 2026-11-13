<?php

declare(strict_types=1);

namespace MonteFiskal\Transport;

/** The three operations of the service (WSDL, BR-T4). */
enum FiscalOperation: string
{
    case RegisterInvoice = 'RegisterInvoice';
    case RegisterTcr = 'RegisterTcr';
    case RegisterCashDeposit = 'RegisterCashDeposit';
}
