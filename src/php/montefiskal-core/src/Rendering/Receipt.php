<?php

declare(strict_types=1);

namespace MonteFiskal\Rendering;

use DOMElement;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Outbox\StoredRequest;

/** Everything printed on a fiscal invoice (BR-P1, BR-P2, BR-P3), read from the stored request. */
final readonly class Receipt
{
    /**
     * @param list<ReceiptLine> $lines
     * @param list<ReceiptVatGroup> $vatGroups
     * @param list<array{0: string, 1: string}> $payments method wire name and amount
     */
    public function __construct(
        public string $sellerName,
        public ?string $sellerAddress,
        public ?string $sellerTown,
        public string $sellerTin,
        public ?string $buyerName,
        public ?string $buyerTin,
        public string $invoiceNumber,
        public string $issueDateTime,
        public string $invoiceType,
        public string $businessUnit,
        public string $tcr,
        public string $software,
        public string $operator,
        public array $lines,
        public array $vatGroups,
        public array $payments,
        public string $totalWithoutVat,
        public ?string $totalVat,
        public string $total,
        public ?string $payDeadline,
        public string $iic,
        /** JIKR; null while the invoice is not registered (BR-J2). */
        public ?string $fic,
        public ?string $correctedIic,
        public string $verificationUrl,
        public bool $testMode,
    ) {
    }

    public static function fromStored(StoredRequest $stored, bool $testMode): self
    {
        $doc = new \DOMDocument();
        $doc->loadXML($stored->body, LIBXML_NONET);
        $inv = $doc->documentElement;
        $child = static function (string $name) use ($inv): ?DOMElement {
            foreach ($inv->childNodes as $c) {
                if ($c instanceof DOMElement && $c->localName === $name) {
                    return $c;
                }
            }
            return null;
        };
        $a = static fn (?DOMElement $e, string $n): ?string => $e !== null && $e->hasAttribute($n) ? $e->getAttribute($n) : null;
        $kids = static function (?DOMElement $e): array {
            $out = [];
            foreach ($e === null ? [] : $e->childNodes as $c) {
                if ($c instanceof DOMElement) {
                    $out[] = $c;
                }
            }
            return $out;
        };
        $seller = $child('Seller');
        $buyer = $child('Buyer');
        return new self(
            sellerName: $a($seller, 'Name') ?? '',
            sellerAddress: $a($seller, 'Address'),
            sellerTown: $a($seller, 'Town'),
            sellerTin: $a($seller, 'IDNum') ?? '',
            buyerName: $a($buyer, 'Name'),
            buyerTin: $a($buyer, 'IDNum'),
            invoiceNumber: $inv->getAttribute('InvNum'),
            issueDateTime: $inv->getAttribute('IssueDateTime'),
            invoiceType: $inv->getAttribute('InvType'),
            businessUnit: $inv->getAttribute('BusinUnitCode'),
            tcr: $inv->getAttribute('TCRCode'),
            software: $inv->getAttribute('SoftCode'),
            operator: $inv->getAttribute('OperatorCode'),
            lines: array_map(static fn (DOMElement $i): ReceiptLine => new ReceiptLine(
                $i->getAttribute('N'),
                $i->getAttribute('U'),
                $i->getAttribute('Q'),
                $i->getAttribute('UPA'),
                $i->getAttribute('PA'),
                $a($i, 'VR'),
                $a($i, 'EX')
            ), $kids($child('Items'))),
            vatGroups: array_map(static fn (DOMElement $g): ReceiptVatGroup => new ReceiptVatGroup(
                $a($g, 'VATRate') ?? $a($g, 'ExemptFromVAT') ?? '',
                $g->getAttribute('PriceBefVAT'),
                $a($g, 'VATAmt')
            ), $kids($child('SameTaxes'))),
            payments: array_map(static fn (DOMElement $p): array => [$p->getAttribute('Type'), $p->getAttribute('Amt')], $kids($child('PayMethods'))),
            totalWithoutVat: $inv->getAttribute('TotPriceWoVAT'),
            totalVat: $a($inv, 'TotVATAmt'),
            total: $inv->getAttribute('TotPrice'),
            payDeadline: $a($inv, 'PayDeadline'),
            iic: $inv->getAttribute('IIC'),
            fic: $stored->state === InvoiceState::Fiscalized ? $stored->result : null,
            correctedIic: $a($child('CorrectiveInv'), 'IICRef'),
            verificationUrl: $stored->verificationUrl ?? '',
            testMode: $testMode,
        );
    }
}
