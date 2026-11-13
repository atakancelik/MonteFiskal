<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

/** Payment method (PayMethod/@Type, BR-K2). */
enum PaymentMethod: string
{
    case Banknote = 'BANKNOTE';
    case Card = 'CARD';
    case BusinessCard = 'BUSINESSCARD';
    case SingleVoucher = 'SVOUCHER';
    case Company = 'COMPANY';
    case Order = 'ORDER';
    case Advance = 'ADVANCE';
    case Account = 'ACCOUNT';
    case Factoring = 'FACTORING';
    case Other = 'OTHER';
    case OtherCash = 'OTHER-CASH';
}
