<?php

declare(strict_types=1);

namespace MonteFiskal\Reports;

use DOMElement;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Outbox\StoredRequest;

/**
 * One invoice as the reports see it. Amounts are decimal strings.
 *
 * sameTaxes: list of {vatRate, priceBeforeVat, vatAmount}; payments: list of {method, amount};
 * items: list of {name, unit, quantity, priceAfterVat, vatRate}.
 */
final readonly class ReportInvoice
{
    /**
     * @param list<array{vatRate: string, priceBeforeVat: string, vatAmount: string}> $sameTaxes
     * @param list<array{method: string, amount: string}> $payments
     * @param list<array{name: string, unit: string, quantity: string, priceAfterVat: string, vatRate: ?string}> $items
     */
    public function __construct(
        public string $number,
        public string $issueDateTime,
        public string $kind = 'INVOICE',
        public string $typeOfInv = 'CASH',
        public string $operator = '',
        public bool $fiscalized = false,
        public string $totalWithoutVat = '0',
        public string $vatAmount = '0',
        public string $total = '0',
        public array $sameTaxes = [],
        public array $payments = [],
        public array $items = [],
        public ?string $iic = null,
        public ?string $fic = null,
    ) {
    }

    /** @param array<string, mixed> $a The JSON shape of the report vectors. */
    public static function fromArray(array $a): self
    {
        $d = static fn (mixed $v): string => $v === null || $v === '' ? '0' : (string) $v;
        return new self(
            (string) $a['number'],
            (string) $a['issueDateTime'],
            (string) ($a['kind'] ?? 'INVOICE'),
            (string) ($a['typeOfInv'] ?? 'CASH'),
            (string) ($a['operator'] ?? ''),
            (bool) ($a['fiscalized'] ?? false),
            $d($a['totalWithoutVat'] ?? null),
            $d($a['vatAmount'] ?? null),
            $d($a['total'] ?? null),
            array_map(static fn (array $g): array => [
                'vatRate' => (string) $g['vatRate'],
                'priceBeforeVat' => $d($g['priceBeforeVat'] ?? null),
                'vatAmount' => $d($g['vatAmount'] ?? null),
            ], $a['sameTaxes'] ?? []),
            array_map(static fn (array $p): array => ['method' => (string) $p['method'], 'amount' => $d($p['amount'] ?? null)], $a['payments'] ?? []),
            [],
            $a['iic'] ?? null,
            $a['fic'] ?? null,
        );
    }

    public static function fromStored(StoredRequest $s): self
    {
        $doc = new \DOMDocument();
        $doc->loadXML($s->body, LIBXML_NONET);
        $inv = $doc->documentElement;
        $kids = static function (string $parent, string $child) use ($inv): array {
            $out = [];
            foreach ($inv->childNodes as $p) {
                if ($p instanceof DOMElement && $p->localName === $parent) {
                    foreach ($p->childNodes as $c) {
                        if ($c instanceof DOMElement && $c->localName === $child) {
                            $out[] = $c;
                        }
                    }
                }
            }
            return $out;
        };
        $d = static fn (string $v): string => $v === '' ? '0' : $v;
        return new self(
            number: $inv->getAttribute('InvNum'),
            issueDateTime: $inv->getAttribute('IssueDateTime'),
            kind: $inv->getAttribute('InvType'),
            typeOfInv: $inv->getAttribute('TypeOfInv'),
            operator: $inv->getAttribute('OperatorCode'),
            fiscalized: $s->state === InvoiceState::Fiscalized,
            totalWithoutVat: $d($inv->getAttribute('TotPriceWoVAT')),
            vatAmount: $d($inv->getAttribute('TotVATAmt')),
            total: $d($inv->getAttribute('TotPrice')),
            sameTaxes: array_map(static fn (DOMElement $g): array => ['vatRate' => $g->hasAttribute('VATRate') ? $g->getAttribute('VATRate') : $g->getAttribute('ExemptFromVAT'),
                'priceBeforeVat' => $d($g->getAttribute('PriceBefVAT')), 'vatAmount' => $d($g->getAttribute('VATAmt'))], $kids('SameTaxes', 'SameTax')),
            payments: array_map(static fn (DOMElement $p): array => ['method' => $p->getAttribute('Type'), 'amount' => $d($p->getAttribute('Amt'))], $kids('PayMethods', 'PayMethod')),
            items: array_map(static fn (DOMElement $i): array => ['name' => $i->getAttribute('N'), 'unit' => $i->getAttribute('U'), 'quantity' => $d($i->getAttribute('Q')),
                'priceAfterVat' => $d($i->getAttribute('PA')), 'vatRate' => $i->hasAttribute('VR') ? $i->getAttribute('VR') : null], $kids('Items', 'I')),
            iic: $inv->getAttribute('IIC'),
            fic: $s->result,
        );
    }
}
