<?php

declare(strict_types=1);

namespace MonteFiskal;

use DateInterval;
use DateTimeImmutable;
use MonteFiskal\Archive\ArchiveStore;
use MonteFiskal\Archive\NullArchiveStore;
use MonteFiskal\Calculation\CalculatedTotals;
use MonteFiskal\Calculation\Dec;
use MonteFiskal\Calculation\InvoiceCalculator;
use MonteFiskal\Calculation\RoundingPolicy;
use MonteFiskal\Errors\FiscalError;
use MonteFiskal\Model\CashDeposit;
use MonteFiskal\Model\CashDepositOperation;
use MonteFiskal\Model\CorrectionInfo;
use MonteFiskal\Model\CorrectionType;
use MonteFiskal\Model\InvoiceDraft;
use MonteFiskal\Model\InvoiceKind;
use MonteFiskal\Model\InvoiceLine;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Model\Payment;
use MonteFiskal\Model\SubsequentDelivery;
use MonteFiskal\Model\TcrRegistration;
use MonteFiskal\Model\TypeOfInvoice;
use MonteFiskal\Numbering\FiscalTime;
use MonteFiskal\Outbox\Attempt;
use MonteFiskal\Outbox\OutboxStore;
use MonteFiskal\Outbox\StoredRequest;
use MonteFiskal\Security\CertificateProvider;
use MonteFiskal\Security\Ikof;
use MonteFiskal\Security\IkofCheck;
use MonteFiskal\Security\IkofParameters;
use MonteFiskal\Security\XmlSigner;
use MonteFiskal\Text\TextRules;
use MonteFiskal\Transport\FiscalOperation;
use MonteFiskal\Transport\FiscalTransport;
use MonteFiskal\Transport\FiscalTransportException;
use MonteFiskal\Transport\SoapEnvelope;
use MonteFiskal\Validation\DepositValidator;
use MonteFiskal\Validation\Finding;
use MonteFiskal\Validation\FiscalValidationException;
use MonteFiskal\Validation\InvoiceValidator;
use MonteFiskal\Validation\Severity;
use MonteFiskal\Validation\TcrValidator;
use MonteFiskal\Validation\ValidationResult;
use MonteFiskal\Verification\VerificationUrl;
use MonteFiskal\Wire\ExchangeOutcome;
use MonteFiskal\Wire\IssueValues;
use MonteFiskal\Wire\ParsedResponse;
use MonteFiskal\Wire\RequestBuilder;
use MonteFiskal\Wire\RequestHeader;
use MonteFiskal\Wire\ResponseParser;
use MonteFiskal\Wire\SchemaSet;
use MonteFiskal\Wire\XNode;

/**
 * Entry point, the same methods as the .NET IFiscalClient (architecture section 3, API parity table), synchronous.
 * Issuing is local; sending is a separate step that never throws for a fiscal fault.
 */
final class FiscalClient
{
    private readonly MonteFiskalOptions $options;
    private readonly Clock $clock;
    private readonly UuidGenerator $uuids;
    private readonly RoundingPolicy $rounding;
    private readonly ArchiveStore $archive;

    public function __construct(
        private readonly FiscalProfile $profile,
        private readonly CertificateProvider $certificates,
        private readonly FiscalTransport $transport,
        private readonly OutboxStore $store,
        ?MonteFiskalOptions $options = null,
        ?Clock $clock = null,
        ?UuidGenerator $uuids = null,
        ?RoundingPolicy $rounding = null,
        ?ArchiveStore $archive = null,
    ) {
        $this->options = $options ?? new MonteFiskalOptions();
        $this->clock = $clock ?? new SystemClock();
        $this->uuids = $uuids ?? new RandomUuidGenerator();
        $this->rounding = $rounding ?? InvoiceCalculator::default();
        $this->archive = $archive ?? new NullArchiveStore();
    }

    public function profile(): FiscalProfile
    {
        return $this->profile;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** Validates, calculates, allocates the ordinal, computes the IKOF and stores the request. No network. */
    public function issueInvoice(InvoiceDraft $draft): IssuedInvoice
    {
        $issuedAt = $draft->issuedAt ?? $this->now();
        $draft = $this->prepare($draft->with(issuedAt: $issuedAt));
        $result = InvoiceValidator::validate($this->profile, $draft, $this->now(), $this->options, $this->rounding);
        if (!$result->isValid()) {
            throw new FiscalValidationException($result);
        }
        $stored = $this->store->issue($this->sequenceKey($issuedAt), fn (int $ordinal): StoredRequest => $this->build($draft, $ordinal));
        return self::toIssued($stored, $result->warnings());
    }

    private function build(InvoiceDraft $draft, int $ordinal): StoredRequest
    {
        $facts = RequestBuilder::facts($this->profile, $draft, $ordinal, $this->options, $this->rounding);
        // BR-I4: the IKOF is computed from the same formatted strings that are sent.
        $ikof = Ikof::compute($facts->iicConcat, $this->certificates->certificate()->privateKey());
        $invoice = (string) RequestBuilder::invoice($this->profile, $draft, $facts, new IssueValues($ordinal, $ikof->iic, $ikof->iicSignature), $this->options);
        $probe = RequestBuilder::invoiceRequest($invoice, new RequestHeader($this->uuids->newUuid(), $this->now()), $this->profile->timeZone, $this->options);
        $schemaErrors = SchemaSet::validateUnsigned($probe);
        if ($schemaErrors !== []) {
            // BR-O10: never store a request the schema rejects; the ordinal is not consumed because build throws.
            throw new FiscalValidationException(new ValidationResult(array_map(
                static fn (string $e): Finding => new Finding('SCHEMA', Severity::Error, 'RegisterInvoiceRequest', $e),
                $schemaErrors,
            )));
        }
        $parameters = new IkofParameters(
            $this->profile->issuerTin,
            $facts->issueDateTime,
            (string) $ordinal,
            $this->profile->businessUnit,
            $this->profile->tcr,
            $this->profile->software,
            $facts->totals->totPrice
        );
        return new StoredRequest(
            id: $this->uuids->newUuid(),
            operation: FiscalOperation::RegisterInvoice,
            body: $invoice,
            issuedAt: $draft->issuedAt,
            issuerTin: $this->profile->issuerTin,
            businessUnit: $this->profile->businessUnit,
            tcr: $this->profile->tcr,
            invoiceNumber: $facts->invNum,
            iic: $ikof->iic,
            iicSignature: $ikof->iicSignature,
            issueDateTime: $facts->issueDateTime,
            kind: $draft->kind,
            typeOfInv: $facts->typeOfInv,
            total: $facts->totals->totPrice,
            verificationUrl: $this->buildVerificationUrl($parameters, $ikof->iic),
            dependsOnIic: $draft->correction?->iicRef,
            draftJson: json_encode($draft->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    private function prepare(InvoiceDraft $d): InvoiceDraft
    {
        if ($this->options->text !== TextPolicy::Transliterate) {
            return $d;
        }
        // Q-07: optional transliteration; never applied by default (BR-T8).
        $t = static fn (?string $s): ?string => $s === null ? null : TextRules::transliterate($s);
        return $d->with(
            buyer: $d->buyer?->with(name: $t($d->buyer->name), address: $t($d->buyer->address), town: $t($d->buyer->town)),
            lines: array_map(static fn (InvoiceLine $l): InvoiceLine => $l->with(name: $t($l->name), unit: $t($l->unit), code: $t($l->code)), $d->lines),
            note: $t($d->note),
        );
    }

    private function sequenceKey(DateTimeImmutable $issuedAt): string
    {
        // BR-N3: one sequence per TCR (default) or per business unit, and per calendar year in local time (BR-N4).
        $year = FiscalTime::localYear($issuedAt, $this->profile->timeZone);
        $p = $this->profile;
        return $this->options->sequenceScope === SequenceScope::BusinessUnitYear
            ? "{$p->issuerTin}/{$p->businessUnit}/{$year}"
            : "{$p->issuerTin}/{$p->businessUnit}/{$p->tcr}/{$year}";
    }

    /** @param list<Finding> $warnings */
    public static function toIssued(StoredRequest $s, array $warnings = []): IssuedInvoice
    {
        return new IssuedInvoice(
            $s->id,
            (string) $s->invoiceNumber,
            (int) $s->ordinal,
            (string) $s->iic,
            (string) $s->iicSignature,
            (string) $s->verificationUrl,
            (string) $s->issueDateTime,
            $s->state,
            self::totalsFromBody($s->body),
            $s->typeOfInv ?? TypeOfInvoice::Cash,
            $warnings
        );
    }

    private static function totalsFromBody(string $body): CalculatedTotals
    {
        $head = substr($body, 0, (int) strpos($body, '>'));
        $a = static function (string $name) use ($head): ?string {
            return preg_match('/ ' . $name . '="([^"]*)"/', $head, $m) ? $m[1] : null;
        };
        return new CalculatedTotals($a('TotPriceWoVAT') ?? '0.00', $a('TotVATAmt'), $a('TotPrice') ?? '0.00');
    }

    /** Sends one stored request. Returns Fiscalized, Pending/Overdue (will be resent) or Rejected. */
    public function send(string $requestId): SendResult
    {
        $stored = $this->store->get($requestId) ?? throw new \InvalidArgumentException('Unknown request ' . $requestId);
        return $this->sendStored($stored);
    }

    private function sendStored(StoredRequest $stored): SendResult
    {
        if ($stored->state === InvoiceState::Fiscalized) {
            return new SendResult($stored->state, $stored->result, null);
        }
        if ($stored->state === InvoiceState::Rejected) {
            return new SendResult($stored->state, null, $stored->lastError, 'rejected; needs a person');
        }
        if ($stored->operation === FiscalOperation::RegisterInvoice) {
            // BR-C3: the original must be registered before its corrective invoice is sent.
            if ($stored->dependsOnIic !== null) {
                $orig = $this->store->findByIic($stored->dependsOnIic);
                if ($orig !== null && $orig->state !== InvoiceState::Fiscalized) {
                    return new SendResult($stored->state, null, null, 'waiting for the original invoice to be fiscalized (BR-C3)');
                }
            }
            // BR-D5: no cash invoice before the day's INITIAL deposit.
            if ($stored->typeOfInv === TypeOfInvoice::Cash && $this->options->autoInitialDeposit && !$this->ensureInitialDeposit($stored->issuedAt)) {
                // The invoice could not be delivered at issue; when it goes out later it is a subsequent delivery (BR-O7).
                $waiting = $stored->with(state: $this->pendingState($stored), subsequentDelivery: $stored->subsequentDelivery ?? SubsequentDelivery::NoInternet);
                $this->store->update($waiting);
                return new SendResult($waiting->state, null, null, "waiting for the day's INITIAL cash deposit (BR-D5)");
            }
        }

        $cert = $this->certificates->certificate();
        $late = $stored->attempts !== [] || $stored->subsequentDelivery !== null ? ($stored->subsequentDelivery ?? SubsequentDelivery::NoInternet) : null;
        $header = new RequestHeader($this->uuids->newUuid(), $this->now(), $late);
        $tz = $this->profile->timeZone;
        $unsigned = match ($stored->operation) {
            FiscalOperation::RegisterInvoice => RequestBuilder::invoiceRequest($stored->body, $header, $tz, $this->options),
            FiscalOperation::RegisterTcr => (string) RequestBuilder::root(
                'RegisterTCRRequest',
                RequestBuilder::header(new RequestHeader($header->uuid, $header->sendDateTime), $tz, $this->options),
                XNode::fromRaw($stored->body),
            ),
            FiscalOperation::RegisterCashDeposit => (string) RequestBuilder::root('RegisterCashDepositRequest', RequestBuilder::header($header, $tz, $this->options), XNode::fromRaw($stored->body)),
        };
        // BR-O7, BR-I5: the body is unchanged; header and signature are rebuilt with the current certificate.
        $signed = XmlSigner::sign($unsigned, $cert);
        $raw = null;
        try {
            $response = $this->transport->send($stored->operation, SoapEnvelope::wrap($signed));
            $raw = $response->body;
            $parsed = ResponseParser::parse($response->statusCode, $response->body, $this->options->responseSignature);
        } catch (FiscalTransportException $e) {
            $parsed = new ParsedResponse(ExchangeOutcome::Transport, true, problem: $e->getMessage());
        }
        $this->archive->save($stored->id, 'request', $signed);
        if ($raw !== null) {
            $this->archive->save($stored->id, 'response', $raw);
        }

        $attempt = new Attempt($this->now(), $header->uuid, $parsed->outcome, $parsed->error?->code, $parsed->problem, $parsed->responseUuid);
        $updated = $stored->with(lastSignedRequest: $signed, lastResponse: $raw, attempts: [...$stored->attempts, $attempt]);
        if ($parsed->outcome === ExchangeOutcome::Success) {
            $updated = $updated->with(state: InvoiceState::Fiscalized, result: $parsed->result, resultAt: $this->now(), lastError: null, nextAttemptAt: null);
            $result = new SendResult(InvoiceState::Fiscalized, $parsed->result, null);
        } elseif ($parsed->outcome === ExchangeOutcome::Fault && !$parsed->retryable) {
            $error = $this->hint($parsed->error);
            if ($stored->operation === FiscalOperation::RegisterCashDeposit && $error->code === 56 && $this->options->treatInitialAlreadyRegisteredAsSuccess) {
                // Q-17 default: an INITIAL that is already registered counts as registered.
                $updated = $updated->with(state: InvoiceState::Fiscalized, lastError: $error, resultAt: $this->now());
                $result = new SendResult(InvoiceState::Fiscalized, null, $error);
            } else {
                $updated = $updated->with(state: InvoiceState::Rejected, lastError: $error, nextAttemptAt: null);
                $result = new SendResult(InvoiceState::Rejected, null, $error);
            }
        } else {
            $state = $this->pendingState($stored);
            $reason = $parsed->outcome === ExchangeOutcome::Transport ? SubsequentDelivery::NoInternet : SubsequentDelivery::Service;
            $delay = (int) min(3600, 30 * 2 ** min(count($updated->attempts) - 1, 7));
            $updated = $updated->with(
                state: $state,
                lastError: $parsed->error,
                subsequentDelivery: $stored->subsequentDelivery ?? $reason,
                nextAttemptAt: $this->now()->add(new DateInterval('PT' . $delay . 'S'))
            );
            $result = new SendResult($state, null, $parsed->error, $parsed->problem);
        }
        $this->store->update($updated);
        return $result;
    }

    private function pendingState(StoredRequest $s): InvoiceState
    {
        return $this->now()->getTimestamp() - $s->issuedAt->getTimestamp() > $this->options->deliveryDeadlineHours * 3600 ? InvoiceState::Overdue : InvoiceState::Pending;
    }

    private function hint(FiscalError $e): FiscalError
    {
        return $e->code === 11
            ? $e->withHint('Schema fault: if the live XSD differs from the bundled revision ' . SchemaSet::REVISION . ', the schema may have changed on the server (run the schema-drift check).')
            : $e;
    }

    private function ensureInitialDeposit(DateTimeImmutable $issuedAt): bool
    {
        $zone = FiscalTime::zone($this->profile->timeZone);
        $day = FiscalTime::localDate($issuedAt, $this->profile->timeZone);
        $start = new DateTimeImmutable($day . ' 00:00:00', $zone);
        $end = $start->modify('+1 day')->modify('-1 second');
        $deposits = array_values(array_filter($this->store->list($start, $end), fn (StoredRequest $r): bool =>
            $r->operation === FiscalOperation::RegisterCashDeposit && $r->tcr === $this->profile->tcr && str_contains($r->body, 'Operation="INITIAL"')));
        foreach ($deposits as $d) {
            if ($d->state === InvoiceState::Fiscalized) {
                return true;
            }
        }
        foreach ($deposits as $d) {
            if ($d->isOpen()) {
                return $this->sendStored($d)->state === InvoiceState::Fiscalized;
            }
        }
        // BR-D5: INITIAL of 0.00 registered automatically before the first cash invoice of the day.
        $now = $this->now();
        $stamp = $issuedAt < $start ? $start : ($now < $end && $now >= $start ? $now : $start);
        $result = $this->registerCashDeposit(new CashDeposit($this->profile->issuerTin, $this->profile->tcr, CashDepositOperation::Initial, '0', $stamp));
        return $result->state === InvoiceState::Fiscalized;
    }

    /** Convenience for online use: issue, then one send attempt. Null when not fiscalized now. */
    public function issueAndSend(InvoiceDraft $draft): ?FiscalizedInvoice
    {
        $issued = $this->issueInvoice($draft);
        $sent = $this->send($issued->id);
        if ($sent->state !== InvoiceState::Fiscalized || $sent->fic === null) {
            return null;
        }
        $i = $issued;
        return new FiscalizedInvoice(new IssuedInvoice(
            $i->id,
            $i->number,
            $i->ordinal,
            $i->iic,
            $i->iicSignature,
            $i->verificationUrl,
            $i->issueDateTime,
            InvoiceState::Fiscalized,
            $i->totals,
            $i->typeOfInv,
            $i->warnings
        ), $sent->fic, $this->now());
    }

    /** Builds a full or partial reversal from a stored invoice (BR-C2, BR-C5) and issues it. */
    public function issueCorrective(CorrectionRequest $request): IssuedInvoice
    {
        $original = $this->store->get($request->originalId) ?? throw new \InvalidArgumentException('Unknown invoice ' . $request->originalId);
        $od = InvoiceDraft::fromArray(json_decode((string) $original->draftJson, true, 512, JSON_THROW_ON_ERROR));
        if ($request->type === CorrectionType::ErrorCorrective && $request->lines === null) {
            throw new \InvalidArgumentException('ERROR_CORRECTIVE carries all items again with the correct values (BR-C4); pass the corrected lines.');
        }
        // BR-C2: full reversal keeps unit prices positive and negates quantities and payments. BR-C5: partial uses the given lines.
        $lines = $request->lines ?? array_map(static fn (InvoiceLine $l): InvoiceLine => $l->with(quantity: Dec::neg($l->quantity)), $od->lines);
        $payments = $request->payments ?? array_map(static fn (Payment $p): Payment => $p->with(amount: $p->amount === null ? null : Dec::neg($p->amount)), $od->payments);
        $draft = $od->with(
            kind: InvoiceKind::Corrective,
            issuedAt: $this->now(),
            lines: $lines,
            payments: $payments,
            correction: new CorrectionInfo((string) $original->iic, FiscalTime::parse((string) $original->issueDateTime), $request->type),
        );
        return $this->issueInvoice($draft);
    }

    /** Registers a TCR (BR-R1 to BR-R5); the same internal ID returns the same TCR code (BR-R3). */
    public function registerTcr(TcrRegistration $registration): TcrRegistrationResult
    {
        $today = FiscalTime::localDate($this->now(), $this->profile->timeZone);
        $v = TcrValidator::validate($registration, $today, ($registration->validTo ?? '') === '');
        if (!$v->isValid()) {
            throw new FiscalValidationException($v);
        }
        $body = (string) RequestBuilder::tcr($registration);
        $probe = (string) RequestBuilder::root(
            'RegisterTCRRequest',
            RequestBuilder::header(new RequestHeader($this->uuids->newUuid(), $this->now()), $this->profile->timeZone, $this->options),
            XNode::fromRaw($body),
        );
        $schema = SchemaSet::validateUnsigned($probe);
        if ($schema !== []) {
            throw new FiscalValidationException(new ValidationResult(array_map(static fn (string $e): Finding => new Finding('SCHEMA', Severity::Error, 'RegisterTCRRequest', $e), $schema)));
        }
        $stored = $this->store->add(new StoredRequest(
            $this->uuids->newUuid(),
            FiscalOperation::RegisterTcr,
            $body,
            $this->now(),
            issuerTin: $registration->issuerTin,
            businessUnit: $registration->businessUnit
        ));
        $r = $this->sendStored($stored);
        return new TcrRegistrationResult($r->state === InvoiceState::Fiscalized, $r->fic, $r->error, $r->problem);
    }

    /** BR-R4: deregistration sends ValidTo; the same TCRIntID returns the same TCR code (BR-R3). */
    public function deregisterTcr(string $tcrInternalId, string $validTo): TcrRegistrationResult
    {
        $p = $this->profile;
        return $this->registerTcr(new TcrRegistration($p->issuerTin, $p->businessUnit, $tcrInternalId, $p->software, $p->maintainer, validTo: $validTo));
    }

    public function registerCashDeposit(CashDeposit $deposit): CashDepositResult
    {
        $deposit = $deposit->with(changeDateTime: $deposit->changeDateTime ?? $this->now());
        $v = DepositValidator::validate($deposit);
        if (!$v->isValid()) {
            throw new FiscalValidationException($v);
        }
        $body = (string) RequestBuilder::deposit($deposit, $this->profile->timeZone, $this->options);
        $stored = $this->store->add(new StoredRequest(
            $this->uuids->newUuid(),
            FiscalOperation::RegisterCashDeposit,
            $body,
            $deposit->changeDateTime,
            issuerTin: $deposit->issuerTin,
            businessUnit: $this->profile->businessUnit,
            tcr: $deposit->tcr,
            total: Dec::money($deposit->amount)
        ));
        $r = $this->sendStored($stored);
        return new CashDepositResult($stored->id, $r->state, $r->fic, $r->error, $r->problem);
    }

    /** BR-O8: oldest first, deposits before invoices, within a budget so that live issuing is not delayed. */
    public function resendPending(?ResendOptions $options = null): ResendSummary
    {
        $options ??= new ResendOptions();
        $started = $this->now()->getTimestamp();
        $pending = $this->store->pending($options->maxItems);
        usort($pending, static fn (StoredRequest $a, StoredRequest $b): int =>
            [(int) ($a->operation === FiscalOperation::RegisterInvoice), $a->issuedAt] <=> [(int) ($b->operation === FiscalOperation::RegisterInvoice), $b->issuedAt]);
        $attempted = $fiscalized = $rejected = $still = 0;
        foreach ($pending as $p) {
            if ($options->budgetSeconds !== null && $this->now()->getTimestamp() - $started > $options->budgetSeconds) {
                break;
            }
            if ($p->nextAttemptAt !== null && $p->nextAttemptAt > $this->now()) {
                $still++;
                continue;
            }
            $current = $this->store->get($p->id);
            if ($current === null || !$current->isOpen()) {
                continue;
            }
            $attempted++;
            $r = $this->sendStored($current);
            match ($r->state) {
                InvoiceState::Fiscalized => $fiscalized++,
                InvoiceState::Rejected => $rejected++,
                default => $still++,
            };
        }
        return new ResendSummary($attempted, $fiscalized, $still, $rejected);
    }

    /** Signs a stored request for the offline export (BR-O11): body unchanged, new header with $reason. */
    public function signForExport(StoredRequest $stored, SubsequentDelivery $reason = SubsequentDelivery::NoInternet): string
    {
        $header = new RequestHeader($this->uuids->newUuid(), $this->now(), $reason);
        $tz = $this->profile->timeZone;
        $unsigned = match ($stored->operation) {
            FiscalOperation::RegisterInvoice => RequestBuilder::invoiceRequest($stored->body, $header, $tz, $this->options),
            FiscalOperation::RegisterCashDeposit => (string) RequestBuilder::root('RegisterCashDepositRequest', RequestBuilder::header($header, $tz, $this->options), XNode::fromRaw($stored->body)),
            default => throw new \InvalidArgumentException('Only invoices and cash deposits are exported.'),
        };
        return XmlSigner::sign($unsigned, $this->certificates->certificate());
    }

    /** Records a JIKR received through the offline import (BR-O11). */
    public function applyImportedResponse(string $iic, string $responseXml): bool
    {
        $stored = $this->store->findByIic($iic);
        if ($stored === null) {
            return false;
        }
        if (str_starts_with($responseXml, '<?xml')) {
            $responseXml = ltrim(substr($responseXml, strpos($responseXml, '?>') + 2));
        }
        $body = str_contains($responseXml, ':Envelope') ? $responseXml
            : '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $responseXml . '</s:Body></s:Envelope>';
        $parsed = ResponseParser::parse(200, $body, $this->options->responseSignature);
        if ($parsed->outcome !== ExchangeOutcome::Success) {
            return false;
        }
        $this->store->update($stored->with(state: InvoiceState::Fiscalized, result: $parsed->result, resultAt: $this->now(), lastResponse: $responseXml));
        return true;
    }

    /** Pure function for the inspector screen (BR-I6). */
    public function recomputeIkof(IkofParameters $parameters): IkofCheck
    {
        return Ikof::computeParameters($parameters, $this->certificates->certificate()->privateKey());
    }

    public function buildVerificationUrl(IkofParameters $parameters, string $iic): string
    {
        return VerificationUrl::build($this->options->verificationBaseOverride ?? VerificationUrl::baseFor($this->profile->environment), $iic, $parameters, $this->options->verificationUrlEncoding);
    }
}
