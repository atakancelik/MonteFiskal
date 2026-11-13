<?php

declare(strict_types=1);

namespace MonteFiskal\Wire;

use DateTimeImmutable;
use MonteFiskal\Calculation\CalculatedTotals;
use MonteFiskal\Calculation\Dec;
use MonteFiskal\Calculation\InvoiceCalculator;
use MonteFiskal\Calculation\RoundingPolicy;
use MonteFiskal\FiscalProfile;
use MonteFiskal\Model\CashDeposit;
use MonteFiskal\Model\InvoiceDraft;
use MonteFiskal\Model\Payment;
use MonteFiskal\Model\PaymentMethod;
use MonteFiskal\Model\TcrRegistration;
use MonteFiskal\Model\TypeOfInvoice;
use MonteFiskal\MonteFiskalOptions;
use MonteFiskal\Numbering\FiscalTime;
use MonteFiskal\Numbering\InvoiceNumber;
use MonteFiskal\Security\Ikof;

/** Builds the request elements of the three operations in canonical form (BR-T3, BR-S2). */
final class RequestBuilder
{
    public const NS = 'https://efi.tax.gov.me/fs/schema';

    private const CASH_METHODS = [PaymentMethod::Banknote, PaymentMethod::Card, PaymentMethod::OtherCash];

    /** BR-K3: an invoice with a cash method is a cash invoice; otherwise non-cash. */
    public static function deriveTypeOfInvoice(InvoiceDraft $draft): TypeOfInvoice
    {
        if ($draft->typeOfInv !== null) {
            return $draft->typeOfInv;
        }
        foreach ($draft->payments as $p) {
            if (in_array($p->method, self::CASH_METHODS, true)) {
                return TypeOfInvoice::Cash;
            }
        }
        return TypeOfInvoice::NonCash;
    }

    /** Computes the totals and formatted values that precede the IKOF. */
    public static function facts(FiscalProfile $profile, InvoiceDraft $draft, int $ordinal, ?MonteFiskalOptions $options = null, ?RoundingPolicy $rounding = null): InvoiceFacts
    {
        $options ??= new MonteFiskalOptions();
        $calc = ($rounding ?? InvoiceCalculator::default())->calculate($draft->lines, $profile->issuerInVat, $options->itemDecimals);
        $totals = $calc->totals;
        $sameTaxes = $calc->sameTaxes;
        if ($draft->lines === [] && $draft->summaryTotals !== null) {
            $st = $draft->summaryTotals;
            $totals = new CalculatedTotals(Dec::money($st->totalWithoutVat), $profile->issuerInVat ? Dec::money($st->vatAmount) : null, Dec::money($st->total));
            $groups = [];
            if ($profile->issuerInVat) {
                foreach ($st->sameTaxes as $g) {
                    $x = ['NumOfItems' => (string) $g->numberOfItems, 'PriceBefVAT' => Dec::money($g->priceBeforeVat)];
                    if ($g->vatRate !== null) {
                        $x['VATRate'] = Dec::money($g->vatRate);
                        $x['VATAmt'] = Dec::money($g->vatAmount);
                    } else {
                        $x['ExemptFromVAT'] = $g->exemption ?? '';
                    }
                    $groups[] = $x;
                }
            }
            $sameTaxes = $groups;
        }
        $calc = $calc->withTotals($totals, $sameTaxes);
        $issuedAt = $draft->issuedAt ?? throw new \InvalidArgumentException('issuedAt must be set before building.');
        $issued = FiscalTime::format($issuedAt, $profile->timeZone, $options->dateTimeFormat);
        $invNum = InvoiceNumber::format($profile->businessUnit, $ordinal, $issuedAt, $profile->tcr, $profile->timeZone);
        $concat = Ikof::concatenate($profile->issuerTin, $issued, (string) $ordinal, $profile->businessUnit, $profile->tcr, $profile->software, $totals->totPrice);
        return new InvoiceFacts($invNum, $issued, $concat, self::deriveTypeOfInvoice($draft), $totals, $calc);
    }

    /** Builds the Invoice element (without namespace declaration; it inherits it from the root). */
    public static function invoice(FiscalProfile $profile, InvoiceDraft $draft, InvoiceFacts $facts, IssueValues $issue, ?MonteFiskalOptions $options = null): XNode
    {
        $options ??= new MonteFiskalOptions();
        $tz = $profile->timeZone;
        $t = static fn (DateTimeImmutable $d): string => FiscalTime::format($d, $tz, $options->dateTimeFormat);
        $money = static fn (?string $v): ?string => $v === null ? null : Dec::money($v);

        $inv = (new XNode('Invoice'))
            ->attr('InvType', $draft->kind->value)
            ->attr('TypeOfInv', $facts->typeOfInv->value)
            ->attr('IssueDateTime', $facts->issueDateTime)
            ->attr('InvNum', $facts->invNum)
            ->attr('InvOrdNum', (string) $issue->ordinal)
            ->attr('TCRCode', $profile->tcr)
            ->attr('IsIssuerInVAT', $profile->issuerInVat ? 'true' : 'false')
            ->attr('OperatorCode', $profile->operator)
            ->attr('BusinUnitCode', $profile->businessUnit)
            ->attr('SoftCode', $profile->software)
            ->attr('IIC', $issue->iic)
            ->attr('IICSignature', $issue->iicSignature)
            ->attrs($facts->totals->attributes());
        if ($draft->selfIssuing !== null) {
            $inv->attr('TypeOfSelfIss', $draft->selfIssuing->value);
        }
        $inv->attr('TaxFreeAmt', $money($draft->taxFreeAmount))
            ->attr('MarkUpAmt', $money($draft->markUpAmount))
            ->attr('GoodsExAmt', $money($draft->goodsExportAmount))
            ->attr('TotPriceToPay', $money($draft->totalToPay));
        if ($draft->reverseCharge !== null) {
            $inv->attr('IsReverseCharge', $draft->reverseCharge ? 'true' : 'false');
        }
        $inv->attr('PayDeadline', $draft->payDeadline)
            ->attr('ParagonBlockNum', $draft->paragonBlockNumber)
            ->attr('TaxPeriod', $draft->taxPeriod)
            ->attr('BankAccNum', $draft->bankAccountNumber)
            ->attr('Note', $draft->note);

        // Children in the order of the schema's xs:all group as listed in the catalog.
        if ($draft->supplyPeriod !== null) {
            $inv->add((new XNode('SupplyDateOrPeriod'))->attr('Start', $draft->supplyPeriod->start)->attr('End', $draft->supplyPeriod->end));
        }
        if ($draft->correction !== null) {
            $c = $draft->correction;
            $inv->add((new XNode('CorrectiveInv'))->attr('IICRef', $c->iicRef)->attr('IssueDateTime', $t($c->issuedAt))->attr('Type', $c->type->value));
        }
        $pm = new XNode('PayMethods');
        foreach (self::paymentsWithAmounts($draft, $facts->totals) as $p) {
            $x = (new XNode('PayMethod'))->attr('Type', $p->method->value)->attr('Amt', Dec::money((string) $p->amount));
            if ($p->companyCard !== null && $p->companyCard !== '') {
                $x->attr('CompCard', $p->companyCard);
            }
            if ($p->advanceIic !== null && $p->advanceIic !== '') {
                $x->attr('AdvIIC', $p->advanceIic);
            }
            if ($p->bankAccount !== null && $p->bankAccount !== '') {
                $x->attr('BankAcc', $p->bankAccount);
            }
            if ($p->vouchers !== []) {
                $v = new XNode('Vouchers');
                foreach ($p->vouchers as $n) {
                    $v->add((new XNode('Voucher'))->attr('Num', $n));
                }
                $x->add($v);
            }
            $pm->add($x);
        }
        $inv->add($pm);
        if ($draft->currency !== null) {
            $inv->add((new XNode('Currency'))->attr('Code', $draft->currency->code)->attr('ExRate', Dec::plain($draft->currency->exchangeRate)));
        }
        $seller = (new XNode('Seller'))->attr('IDType', 'TIN')->attr('IDNum', $profile->issuerTin)->attr('Name', $profile->issuerName);
        foreach (['Address' => $profile->issuerAddress, 'Town' => $profile->issuerTown, 'Country' => $profile->issuerCountry] as $k => $v) {
            if ($v !== null && $v !== '') {
                $seller->attr($k, $v);
            }
        }
        $inv->add($seller);
        if ($draft->buyer !== null) {
            $b = $draft->buyer;
            $bx = (new XNode('Buyer'))->attr('IDType', $b->idType->value)->attr('IDNum', $b->idNum)->attr('Name', $b->name);
            foreach (['Address' => $b->address, 'Town' => $b->town, 'Country' => $b->country, 'TIC' => $b->tic] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $bx->attr($k, $v);
                }
            }
            $inv->add($bx);
        }
        if ($facts->calculation->lines !== []) {
            $items = new XNode('Items');
            foreach ($facts->calculation->lines as $l) {
                $items->add((new XNode('I'))->attrs($l->attributes));
            }
            $inv->add($items);
        }
        if ($facts->calculation->sameTaxes !== []) {
            $sts = new XNode('SameTaxes');
            foreach ($facts->calculation->sameTaxes as $g) {
                $sts->add((new XNode('SameTax'))->attrs($g));
            }
            $inv->add($sts);
        }
        if ($draft->approvals !== []) {
            $aps = new XNode('Approvals');
            foreach ($draft->approvals as $ap) {
                $aps->add((new XNode('Approval'))->attr('TotalAmt', Dec::money($ap->totalAmount))->attr('DiscountAmt', $money($ap->discountAmount))
                    ->attr('ReturnAmt', $money($ap->returnAmount))->attr('VATRate', $money($ap->vatRate))->attr('ExemptFromVAT', $money($ap->exemptAmount))
                    ->attr('VATAmt', $money($ap->vatAmount)));
            }
            $inv->add($aps);
        }
        if ($draft->fees !== []) {
            $fees = new XNode('Fees');
            foreach ($draft->fees as $f) {
                $fees->add((new XNode('Fee'))->attr('Type', $f->type->value)->attr('Amt', Dec::money($f->amount)));
            }
            $inv->add($fees);
        }
        if ($draft->iicRefs !== []) {
            $refs = new XNode('IICRefs');
            foreach ($draft->iicRefs as $r) {
                $refs->add((new XNode('IICRef'))->attr('IIC', $r->iic)->attr('IssueDateTime', $t($r->issuedAt))->attr('Amount', Dec::money($r->amount)));
            }
            $inv->add($refs);
        }
        if ($draft->badDebt !== null) {
            $inv->add((new XNode('BadDebtInv'))->attr('IICRef', $draft->badDebt->iicRef)->attr('IssueDateTime', $t($draft->badDebt->issuedAt)));
        }
        return $inv;
    }

    /**
     * Payments with every amount filled in: a payment without amount takes the remainder of the total.
     *
     * @return list<Payment>
     */
    public static function paymentsWithAmounts(InvoiceDraft $draft, CalculatedTotals $totals): array
    {
        $known = Dec::sum(array_map(static fn (Payment $p): string => $p->amount ?? '0', $draft->payments));
        return array_map(static fn (Payment $p): Payment => $p->amount === null ? $p->with(amount: Dec::sub($totals->total(), $known)) : $p, $draft->payments);
    }

    public static function header(RequestHeader $header, string $timeZone, ?MonteFiskalOptions $options = null): XNode
    {
        $h = (new XNode('Header'))
            ->attr('UUID', strtolower($header->uuid))
            ->attr('SendDateTime', FiscalTime::format($header->sendDateTime, $timeZone, ($options ?? new MonteFiskalOptions())->dateTimeFormat));
        if ($header->subsequentDelivery !== null) {
            $h->attr('SubseqDelivType', $header->subsequentDelivery->value);
        }
        return $h;
    }

    /** Root element with Id="Request" and Version="1" (BR-S2, BR-S8). */
    public static function root(string $element, XNode $header, XNode $body): XNode
    {
        return (new XNode($element, self::NS))->attr('Id', 'Request')->attr('Version', '1')->add($header)->add($body);
    }

    /** Unsigned RegisterInvoiceRequest from a stored Invoice element. */
    public static function invoiceRequest(string $invoiceElement, RequestHeader $header, string $timeZone, ?MonteFiskalOptions $options = null): string
    {
        $root = (string) (new XNode('RegisterInvoiceRequest', self::NS))->attr('Id', 'Request')->attr('Version', '1');
        $open = substr($root, 0, strpos($root, '>') + 1);
        return $open . self::header($header, $timeZone, $options) . $invoiceElement . '</RegisterInvoiceRequest>';
    }

    public static function tcr(TcrRegistration $reg): XNode
    {
        $t = (new XNode('TCR'))->attr('IssuerTIN', $reg->issuerTin)->attr('BusinUnitCode', $reg->businessUnit)->attr('TCRIntID', $reg->internalId);
        foreach (['SoftCode' => $reg->software, 'MaintainerCode' => $reg->maintainer, 'ValidFrom' => $reg->validFrom, 'ValidTo' => $reg->validTo] as $k => $v) {
            if ($v !== null && $v !== '') {
                $t->attr($k, $v);
            }
        }
        if ($reg->type !== null) {
            $t->attr('Type', $reg->type->value);
        }
        return $t;
    }

    public static function deposit(CashDeposit $dep, string $timeZone, ?MonteFiskalOptions $options = null): XNode
    {
        $at = $dep->changeDateTime ?? throw new \InvalidArgumentException('changeDateTime must be set.');
        return (new XNode('CashDeposit'))
            ->attr('ChangeDateTime', FiscalTime::format($at, $timeZone, ($options ?? new MonteFiskalOptions())->dateTimeFormat))
            ->attr('Operation', $dep->operation->value)
            ->attr('CashAmt', Dec::money($dep->amount))
            ->attr('TCRCode', $dep->tcr)
            ->attr('IssuerTIN', $dep->issuerTin);
    }
}
