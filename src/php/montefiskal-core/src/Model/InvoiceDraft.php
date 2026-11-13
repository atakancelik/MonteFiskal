<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

use DateTimeImmutable;

/**
 * An invoice before it is issued. Field names are the camel-case names of test-vectors/ and of the .NET
 * library. All amounts are numeric strings; nothing is floating point.
 */
final readonly class InvoiceDraft
{
    /**
     * @param list<InvoiceLine> $lines
     * @param list<Payment> $payments
     * @param list<Fee> $fees
     * @param list<InvoiceReference> $iicRefs
     * @param list<Approval> $approvals
     */
    public function __construct(
        public InvoiceKind $kind = InvoiceKind::Invoice,
        /** Optional. When null it is derived from the payment methods (BR-K3). */
        public ?TypeOfInvoice $typeOfInv = null,
        /** Time of issue; null means the clock of the client. */
        public ?DateTimeImmutable $issuedAt = null,
        public ?Party $buyer = null,
        public array $lines = [],
        public array $payments = [],
        public ?SelfIssuingKind $selfIssuing = null,
        public ?bool $reverseCharge = null,
        public ?string $payDeadline = null,
        public ?SupplyPeriod $supplyPeriod = null,
        public ?CurrencyInfo $currency = null,
        public array $fees = [],
        public array $iicRefs = [],
        public ?CorrectionInfo $correction = null,
        public ?BadDebtInfo $badDebt = null,
        public array $approvals = [],
        public ?string $note = null,
        public ?string $bankAccountNumber = null,
        public ?string $taxFreeAmount = null,
        public ?string $markUpAmount = null,
        public ?string $goodsExportAmount = null,
        public ?string $totalToPay = null,
        public ?string $taxPeriod = null,
        public ?string $paragonBlockNumber = null,
        /** Totals of a SUMMARY, PERIODICAL or CREDIT_NOTE invoice without items. */
        public ?SummaryTotals $summaryTotals = null,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $list = static fn (string $key, callable $f): array => array_values(array_map($f, $a[$key] ?? []));
        return new self(
            kind: isset($a['kind']) ? InvoiceKind::from($a['kind']) : InvoiceKind::Invoice,
            typeOfInv: isset($a['typeOfInv']) ? TypeOfInvoice::from($a['typeOfInv']) : null,
            issuedAt: Arr::time($a['issuedAt'] ?? null),
            buyer: isset($a['buyer']) ? Party::fromArray($a['buyer']) : null,
            lines: $list('lines', static fn (array $x): InvoiceLine => InvoiceLine::fromArray($x)),
            payments: $list('payments', static fn (array $x): Payment => Payment::fromArray($x)),
            selfIssuing: isset($a['selfIssuing']) ? SelfIssuingKind::from($a['selfIssuing']) : null,
            reverseCharge: Arr::bool($a['reverseCharge'] ?? null),
            payDeadline: $a['payDeadline'] ?? null,
            supplyPeriod: isset($a['supplyPeriod']) ? SupplyPeriod::fromArray($a['supplyPeriod']) : null,
            currency: isset($a['currency']) ? CurrencyInfo::fromArray($a['currency']) : null,
            fees: $list('fees', static fn (array $x): Fee => Fee::fromArray($x)),
            iicRefs: $list('iicRefs', static fn (array $x): InvoiceReference => InvoiceReference::fromArray($x)),
            correction: isset($a['correction']) ? CorrectionInfo::fromArray($a['correction']) : null,
            badDebt: isset($a['badDebt']) ? BadDebtInfo::fromArray($a['badDebt']) : null,
            approvals: $list('approvals', static fn (array $x): Approval => Approval::fromArray($x)),
            note: $a['note'] ?? null,
            bankAccountNumber: $a['bankAccountNumber'] ?? null,
            taxFreeAmount: Arr::dec($a['taxFreeAmount'] ?? null),
            markUpAmount: Arr::dec($a['markUpAmount'] ?? null),
            goodsExportAmount: Arr::dec($a['goodsExportAmount'] ?? null),
            totalToPay: Arr::dec($a['totalToPay'] ?? null),
            taxPeriod: $a['taxPeriod'] ?? null,
            paragonBlockNumber: $a['paragonBlockNumber'] ?? null,
            summaryTotals: isset($a['summaryTotals']) ? SummaryTotals::fromArray($a['summaryTotals']) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $map = static fn (array $xs): array => array_map(static fn (object $x): array => $x->toArray(), $xs);
        return Arr::compact([
            'kind' => $this->kind->value,
            'typeOfInv' => $this->typeOfInv?->value,
            'issuedAt' => Arr::iso($this->issuedAt),
            'buyer' => $this->buyer?->toArray(),
            'lines' => $map($this->lines),
            'payments' => $map($this->payments),
            'selfIssuing' => $this->selfIssuing?->value,
            'reverseCharge' => $this->reverseCharge,
            'payDeadline' => $this->payDeadline,
            'supplyPeriod' => $this->supplyPeriod?->toArray(),
            'currency' => $this->currency?->toArray(),
            'fees' => $map($this->fees),
            'iicRefs' => $map($this->iicRefs),
            'correction' => $this->correction?->toArray(),
            'badDebt' => $this->badDebt?->toArray(),
            'approvals' => $map($this->approvals),
            'note' => $this->note,
            'bankAccountNumber' => $this->bankAccountNumber,
            'taxFreeAmount' => $this->taxFreeAmount,
            'markUpAmount' => $this->markUpAmount,
            'goodsExportAmount' => $this->goodsExportAmount,
            'totalToPay' => $this->totalToPay,
            'taxPeriod' => $this->taxPeriod,
            'paragonBlockNumber' => $this->paragonBlockNumber,
            'summaryTotals' => $this->summaryTotals?->toArray(),
        ]);
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
