<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Identification type of a party (IDType). */
enum IdType: string
{
    case Tin = 'TIN';
    case PersonalId = 'ID';
    case Passport = 'PASS';
    case Vat = 'VAT';
    case Tax = 'TAX';
    case Social = 'SOC';
}
