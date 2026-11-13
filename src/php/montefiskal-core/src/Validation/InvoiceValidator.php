<?php

declare(strict_types=1);

namespace MonteFiskal\Validation;

use DateTimeImmutable;
use MonteFiskal\Calculation\Dec;
use MonteFiskal\Calculation\RoundingPolicy;
use MonteFiskal\FiscalProfile;
use MonteFiskal\Model\CorrectionType;
use MonteFiskal\Model\IdType;
use MonteFiskal\Model\InvoiceDraft;
use MonteFiskal\Model\InvoiceKind;
use MonteFiskal\Model\Payment;
use MonteFiskal\Model\PaymentMethod;
use MonteFiskal\Model\TypeOfInvoice;
use MonteFiskal\MonteFiskalOptions;
use MonteFiskal\Text\TextRules;
use MonteFiskal\TextPolicy;
use MonteFiskal\Wire\IssueValues;
use MonteFiskal\Wire\RequestBuilder;
use MonteFiskal\Wire\RequestHeader;
use MonteFiskal\Wire\SchemaSet;

/**
 * Local rules for invoices. Every check quotes its rule ID. Rules marked UNVERIFIED in the analysis produce
 * warnings, verified rules produce errors. The schema check (SCHEMA) runs only when no business rule failed.
 */
final class InvoiceValidator
{
    private const CASH_ALLOWED = [PaymentMethod::Banknote, PaymentMethod::Card, PaymentMethod::OtherCash, PaymentMethod::Order];
    private const NONCASH_ALLOWED = [PaymentMethod::BusinessCard, PaymentMethod::SingleVoucher, PaymentMethod::Company, PaymentMethod::Advance,
        PaymentMethod::Account, PaymentMethod::Factoring, PaymentMethod::Other, PaymentMethod::Order];

    public static function validate(FiscalProfile $profile, InvoiceDraft $draft, DateTimeImmutable $now, ?MonteFiskalOptions $options = null, ?RoundingPolicy $rounding = null): ValidationResult
    {
        $options ??= new MonteFiskalOptions();
        $f = new FindingList();
        $issuedAt = $draft->issuedAt ?? $now;
        $type = RequestBuilder::deriveTypeOfInvoice($draft);

        // BR-K3: payment methods allowed for the invoice type.
        foreach ($draft->payments as $n => $p) {
            $i = $n + 1;
            $allowed = $type === TypeOfInvoice::Cash ? self::CASH_ALLOWED : self::NONCASH_ALLOWED;
            if (!in_array($p->method, $allowed, true)) {
                $f->error('BR-K3', "PayMethods/PayMethod[{$i}]", "{$p->method->value} is not allowed on a {$type->value} invoice.");
            }
            if ($p->method === PaymentMethod::BusinessCard && $draft->buyer?->idType !== IdType::Tin) {
                // BR-K3, Q-29: a business card belongs to a taxpayer; without a buyer TIN the choice is doubtful.
                $f->warn('BR-K3', "PayMethods/PayMethod[{$i}]", 'BUSINESSCARD without a buyer identified by TIN.');
            }
            // BR-K5: attributes that belong to a specific payment method.
            if ($p->method === PaymentMethod::Advance && ($p->advanceIic ?? '') === '') {
                $f->error('BR-K5', "PayMethods/PayMethod[{$i}]/@AdvIIC", 'ADVANCE needs the IKOF of the advance invoice.');
            }
            if ($p->method === PaymentMethod::SingleVoucher && ($p->vouchers === [] || ($p->advanceIic ?? '') === '')) {
                $f->error('BR-K5', "PayMethods/PayMethod[{$i}]", 'SVOUCHER needs the voucher numbers and the IKOF of the advance invoice.');
            }
            if (($p->companyCard ?? '') !== '' && $p->method !== PaymentMethod::Company) {
                $f->error('BR-K5', "PayMethods/PayMethod[{$i}]/@CompCard", 'CompCard is given only for COMPANY.');
            }
            if ($p->vouchers !== [] && $p->method !== PaymentMethod::SingleVoucher) {
                $f->error('BR-K5', "PayMethods/PayMethod[{$i}]/Vouchers", 'Vouchers are given only for SVOUCHER.');
            }
            if (($p->advanceIic ?? '') !== '' && $p->method !== PaymentMethod::Advance && $p->method !== PaymentMethod::SingleVoucher) {
                $f->error('BR-K5', "PayMethods/PayMethod[{$i}]/@AdvIIC", 'AdvIIC is given only for ADVANCE and SVOUCHER.');
            }
            if (($p->bankAccount ?? '') !== '' && $draft->kind !== InvoiceKind::CreditNote) {
                $f->warn('BR-K5', "PayMethods/PayMethod[{$i}]/@BankAcc", 'BankAcc is meant for the pay-out of a credit note.');
            }
        }

        // BR-K1: a cash invoice paid only with OTHER-CASH (UNVERIFIED).
        if ($type === TypeOfInvoice::Cash && $draft->payments !== [] && array_all($draft->payments, static fn (Payment $p): bool => $p->method === PaymentMethod::OtherCash)) {
            $f->warn('BR-K1', 'PayMethods', 'Cash invoice paid only with OTHER-CASH.');
        }

        // BR-K6: references and approvals of report-like invoice types.
        if (in_array($draft->kind, [InvoiceKind::Summary, InvoiceKind::Periodical, InvoiceKind::CreditNote], true)) {
            if ($draft->iicRefs === []) {
                $f->error('BR-K6', 'IICRefs', "{$draft->kind->value} needs IICRefs.");
            }
            if ($draft->kind === InvoiceKind::CreditNote && $draft->approvals === []) {
                $f->error('BR-K6', 'Approvals', 'CREDIT_NOTE needs Approvals (BR-K18).');
            }
            $f->warn('BR-K6', '@InvType', "{$draft->kind->value} is supported but not yet confirmed on efitest (Q-02).");
        }

        // BR-K8: periodic invoice needs a buyer and a tax period.
        if ($draft->kind === InvoiceKind::Periodical) {
            if ($draft->buyer === null) {
                $f->error('BR-K8', 'Buyer', 'PERIODICAL needs a buyer.');
            }
            if (($draft->taxPeriod ?? '') === '') {
                $f->error('BR-K8', '@TaxPeriod', 'PERIODICAL needs the tax period (MM/YYYY).');
            }
        }

        // BR-K10: self-issued invoice needs a buyer identified by TIN; who signs is UNVERIFIED (Q-11).
        if ($draft->selfIssuing !== null) {
            if ($draft->buyer?->idType !== IdType::Tin) {
                $f->error('BR-K10', 'Buyer/@IDType', 'A self-issued invoice needs the buyer identified by TIN (error 54).');
            }
            $f->warn('BR-K10', '@TypeOfSelfIss', 'Self-issued invoices are not yet confirmed on efitest (Q-11).');
        }

        // BR-K11: reverse charge must not be sent as true.
        if ($draft->reverseCharge === true) {
            $f->error('BR-K11', '@IsReverseCharge', 'Reverse charge is not applied; send false or omit it.');
        }

        // BR-C4: corrective invoices carry the reference to the original, and only they do.
        if ($draft->kind === InvoiceKind::Corrective && $draft->correction === null) {
            $f->error('BR-C4', 'CorrectiveInv', 'CORRECTIVE needs CorrectiveInv with the IKOF of the original.');
        }
        if ($draft->kind !== InvoiceKind::Corrective && $draft->correction !== null) {
            $f->error('BR-C4', 'CorrectiveInv', 'CorrectiveInv is given only on a CORRECTIVE invoice.');
        }
        if (
            $draft->correction !== null && $draft->correction->type === CorrectionType::ErrorCorrective
            && $issuedAt->getTimestamp() - $draft->correction->issuedAt->getTimestamp() > 8 * 86400
        ) {
            $f->warn('BR-C4', 'CorrectiveInv/@IssueDateTime', 'ERROR_CORRECTIVE is meant to be issued within 8 days of the original.');
        }

        // BR-V6, BR-V3, BR-A2: VAT data and signs of each line.
        foreach ($draft->lines as $n => $l) {
            $path = 'Items/I[' . ($n + 1) . ']';
            $hasEx = ($l->exemption ?? '') !== '';
            if (!$profile->issuerInVat && ($l->vatRate !== null || $hasEx)) {
                $f->error('BR-V6', $path . '/@VR', 'A seller outside the VAT system sends no VAT rate or exemption.');
            }
            if ($profile->issuerInVat && (($l->vatRate === null) === !$hasEx)) {
                $f->error('BR-V3', $path . '/@VR', 'A line of a VAT seller has either a VAT rate or an exemption code.');
            }
            if (Dec::cmp($l->unitPrice, '0') < 0) {
                $f->error('BR-A2', $path . '/@UPB', 'Unit prices are never negative; corrections use negative quantities.');
            }
            if ($l->vatRate !== null && Dec::cmp($l->vatRate, '0') < 0) {
                $f->error('BR-A2', $path . '/@VR', 'VAT rates are never negative.');
            }
            if ($l->rebate !== null && (Dec::cmp($l->rebate, '0') < 0 || Dec::cmp($l->rebate, '100') > 0)) {
                $f->error('BR-A2', $path . '/@R', 'A rebate is between 0 and 100 percent.');
            }
        }

        // BR-T8: characters of every text field.
        foreach (self::texts($profile, $draft) as [$path, $text]) {
            if (TextRules::hasControlCharacter($text)) {
                $f->error('BR-T8', $path, 'Control characters are not allowed.');
            } elseif ($options->text === TextPolicy::Unchanged && TextRules::hasNonAscii($text)) {
                $f->warn('BR-T8', $path, 'Text outside ASCII is sent unchanged; whether CIS accepts it is open (Q-07).');
            }
        }

        $dated = $draft->with(issuedAt: $issuedAt);
        $facts = RequestBuilder::facts($profile, $dated, 1, $options, $rounding);
        $payments = RequestBuilder::paymentsWithAmounts($draft, $facts->totals);

        // BR-A8, Q-15: physical cash of EUR 10,000 or more must go through a bank account.
        $banknotes = Dec::sum(array_map(static fn (Payment $p): string => $p->method === PaymentMethod::Banknote ? ($p->amount ?? '0') : '0', $payments));
        if (Dec::cmp($banknotes, $options->cashLimit) >= 0) {
            $f->error('BR-A8', 'PayMethods', 'Cash payments of EUR ' . Dec::money($options->cashLimit) . ' or more are not allowed.');
        }

        // BR-K4: the payments should add up to the total (UNVERIFIED).
        if ($payments !== [] && Dec::cmp(Dec::sum(array_map(static fn (Payment $p): string => $p->amount ?? '0', $payments)), $facts->totals->total()) !== 0) {
            $f->warn('BR-K4', 'PayMethods', 'The payment amounts do not add up to the invoice total.');
        }

        if (!$f->hasErrors()) {
            // BR-O10: schema-invalid data is a blocking error; checked against the bundled official XSD.
            $xml = RequestBuilder::invoiceRequest(
                (string) RequestBuilder::invoice($profile, $dated, $facts, new IssueValues(1, str_repeat('0', 32), str_repeat('0', 512)), $options),
                new RequestHeader('00000000-0000-4000-8000-000000000000', $now),
                $profile->timeZone,
                $options,
            );
            foreach (SchemaSet::validateUnsigned($xml) as $e) {
                $f->error('SCHEMA', 'RegisterInvoiceRequest', $e);
            }
        }
        return $f->result();
    }

    /** @return list<array{0: string, 1: ?string}> */
    public static function texts(FiscalProfile $p, InvoiceDraft $d): array
    {
        $t = [['Seller/@Name', $p->issuerName], ['Seller/@Address', $p->issuerAddress], ['Seller/@Town', $p->issuerTown]];
        if ($d->buyer !== null) {
            array_push($t, ['Buyer/@Name', $d->buyer->name], ['Buyer/@Address', $d->buyer->address], ['Buyer/@Town', $d->buyer->town], ['Buyer/@IDNum', $d->buyer->idNum]);
        }
        foreach ($d->lines as $n => $l) {
            $i = $n + 1;
            array_push($t, ["Items/I[{$i}]/@N", $l->name], ["Items/I[{$i}]/@U", $l->unit], ["Items/I[{$i}]/@C", $l->code]);
        }
        $t[] = ['@Note', $d->note];
        return $t;
    }
}
