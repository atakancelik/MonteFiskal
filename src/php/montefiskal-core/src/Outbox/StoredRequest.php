<?php

declare(strict_types=1);

namespace MonteFiskal\Outbox;

use DateTimeImmutable;
use MonteFiskal\Errors\FiscalError;
use MonteFiskal\Model\Arr;
use MonteFiskal\Model\InvoiceKind;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Model\SubsequentDelivery;
use MonteFiskal\Model\TypeOfInvoice;
use MonteFiskal\Transport\FiscalOperation;
use MonteFiskal\Wire\SchemaSet;

/**
 * A stored request: the unsigned body element exactly as issued (BR-O7), its state, and every attempt.
 * Resending rebuilds only the header and the signature.
 */
final readonly class StoredRequest
{
    /** @param list<Attempt> $attempts */
    public function __construct(
        public string $id,
        public FiscalOperation $operation,
        /** The unsigned body element (Invoice, TCR or CashDeposit) in canonical form. */
        public string $body,
        public DateTimeImmutable $issuedAt,
        public InvoiceState $state = InvoiceState::Issued,
        public string $schemaRevision = SchemaSet::REVISION,
        public string $issuerTin = '',
        public string $businessUnit = '',
        public string $tcr = '',
        public ?string $sequenceKey = null,
        public ?int $ordinal = null,
        public ?string $invoiceNumber = null,
        public ?string $iic = null,
        public ?string $iicSignature = null,
        public ?string $issueDateTime = null,
        public ?InvoiceKind $kind = null,
        public ?TypeOfInvoice $typeOfInv = null,
        public ?string $total = null,
        public ?string $verificationUrl = null,
        /** For a corrective invoice: IKOF of the original, which must be fiscalized first (BR-C3). */
        public ?string $dependsOnIic = null,
        /** The draft as JSON, for corrections and reports. */
        public ?string $draftJson = null,
        /** JIKR (FIC), TCR code or FCDC. */
        public ?string $result = null,
        public ?DateTimeImmutable $resultAt = null,
        public ?SubsequentDelivery $subsequentDelivery = null,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?FiscalError $lastError = null,
        public ?string $lastSignedRequest = null,
        public ?string $lastResponse = null,
        public array $attempts = [],
        /** Concurrency token for durable stores. */
        public int $version = 0,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }

    public function isOpen(): bool
    {
        return in_array($this->state, [InvoiceState::Issued, InvoiceState::Pending, InvoiceState::Overdue], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'operation' => $this->operation->value, 'body' => $this->body, 'issuedAt' => Arr::iso($this->issuedAt),
            'state' => $this->state->value, 'schemaRevision' => $this->schemaRevision, 'issuerTin' => $this->issuerTin,
            'businessUnit' => $this->businessUnit, 'tcr' => $this->tcr, 'sequenceKey' => $this->sequenceKey, 'ordinal' => $this->ordinal,
            'invoiceNumber' => $this->invoiceNumber, 'iic' => $this->iic, 'iicSignature' => $this->iicSignature,
            'issueDateTime' => $this->issueDateTime, 'kind' => $this->kind?->value, 'typeOfInv' => $this->typeOfInv?->value,
            'total' => $this->total, 'verificationUrl' => $this->verificationUrl, 'dependsOnIic' => $this->dependsOnIic,
            'draftJson' => $this->draftJson, 'result' => $this->result, 'resultAt' => Arr::iso($this->resultAt),
            'subsequentDelivery' => $this->subsequentDelivery?->value, 'nextAttemptAt' => Arr::iso($this->nextAttemptAt),
            'lastError' => $this->lastError?->toArray(), 'lastSignedRequest' => $this->lastSignedRequest, 'lastResponse' => $this->lastResponse,
            'attempts' => array_map(static fn (Attempt $a): array => $a->toArray(), $this->attempts), 'version' => $this->version,
        ];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            id: (string) $a['id'],
            operation: FiscalOperation::from($a['operation']),
            body: (string) $a['body'],
            issuedAt: Arr::time($a['issuedAt']),
            state: InvoiceState::from($a['state'] ?? 'Issued'),
            schemaRevision: (string) ($a['schemaRevision'] ?? SchemaSet::REVISION),
            issuerTin: (string) ($a['issuerTin'] ?? ''),
            businessUnit: (string) ($a['businessUnit'] ?? ''),
            tcr: (string) ($a['tcr'] ?? ''),
            sequenceKey: $a['sequenceKey'] ?? null,
            ordinal: isset($a['ordinal']) ? (int) $a['ordinal'] : null,
            invoiceNumber: $a['invoiceNumber'] ?? null,
            iic: $a['iic'] ?? null,
            iicSignature: $a['iicSignature'] ?? null,
            issueDateTime: $a['issueDateTime'] ?? null,
            kind: isset($a['kind']) ? InvoiceKind::from($a['kind']) : null,
            typeOfInv: isset($a['typeOfInv']) ? TypeOfInvoice::from($a['typeOfInv']) : null,
            total: $a['total'] ?? null,
            verificationUrl: $a['verificationUrl'] ?? null,
            dependsOnIic: $a['dependsOnIic'] ?? null,
            draftJson: $a['draftJson'] ?? null,
            result: $a['result'] ?? null,
            resultAt: Arr::time($a['resultAt'] ?? null),
            subsequentDelivery: isset($a['subsequentDelivery']) ? SubsequentDelivery::from($a['subsequentDelivery']) : null,
            nextAttemptAt: Arr::time($a['nextAttemptAt'] ?? null),
            lastError: isset($a['lastError']) ? FiscalError::fromArray($a['lastError']) : null,
            lastSignedRequest: $a['lastSignedRequest'] ?? null,
            lastResponse: $a['lastResponse'] ?? null,
            attempts: array_map(static fn (array $x): Attempt => Attempt::fromArray($x), $a['attempts'] ?? []),
            version: (int) ($a['version'] ?? 0),
        );
    }
}
