<?php

declare(strict_types=1);

namespace MonteFiskal;

use MonteFiskal\Model\Arr;

/**
 * Options with one switch for each question that only efitest can settle. The names match the .NET
 * MonteFiskalOptions and the intake questions of docs/ai (ai-assistant-kit.md section 5).
 */
final readonly class MonteFiskalOptions
{
    /** @param list<string> $trustedResponseCertificates SHA-1 thumbprints (upper-case hex) trusted to sign responses (Q-12). */
    public function __construct(
        /** Overrides the service endpoint (BR-T1). */
        public ?string $serviceEndpointOverride = null,
        /** Overrides the verification URL base (BR-Q3, BR-Q4). */
        public ?string $verificationBaseOverride = null,
        public VerificationUrlEncoding $verificationUrlEncoding = VerificationUrlEncoding::Percent,
        public TextPolicy $text = TextPolicy::Unchanged,
        public ResponseSignaturePolicy $responseSignature = ResponseSignaturePolicy::Verify,
        public DateTimeFormat $dateTimeFormat = DateTimeFormat::LocalOffset,
        public DuplicateIicHandling $duplicateIic = DuplicateIicHandling::Reject,
        public SequenceScope $sequenceScope = SequenceScope::TcrYear,
        /** Decimals of item amounts (Q-14); 2 to 4, default 4. */
        public int $itemDecimals = 4,
        /** Send the SOAPAction HTTP header (Q-22). */
        public bool $sendSoapAction = true,
        /** Banknote total at or above which no invoice is issued (Q-15, BR-A8). */
        public string $cashLimit = '10000',
        /** Register the day's INITIAL deposit of 0.00 before the first cash invoice (BR-D5). */
        public bool $autoInitialDeposit = true,
        /** Code 56 (INITIAL already registered) counts as success for the automatic deposit (Q-17). */
        public bool $treatInitialAlreadyRegisteredAsSuccess = true,
        /** Timeout of one send attempt in seconds (BR-T6). */
        public int $sendTimeoutSeconds = 15,
        /** Deadline for a late delivery in hours (BR-O12). */
        public int $deliveryDeadlineHours = 48,
        public array $trustedResponseCertificates = [],
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return Arr::with($this, $changes);
    }
}
