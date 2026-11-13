<?php

declare(strict_types=1);

namespace MonteFiskal\Outbox;

use DateTimeImmutable;
use MonteFiskal\Model\Arr;
use MonteFiskal\Wire\ExchangeOutcome;

/** One send attempt (architecture section 2.5). */
final readonly class Attempt
{
    public function __construct(
        public DateTimeImmutable $at,
        public string $headerUuid,
        public ExchangeOutcome $outcome,
        public ?int $errorCode,
        public ?string $problem,
        public ?string $responseUuid,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['at' => Arr::iso($this->at), 'headerUuid' => $this->headerUuid, 'outcome' => $this->outcome->value,
            'errorCode' => $this->errorCode, 'problem' => $this->problem, 'responseUuid' => $this->responseUuid];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            Arr::time($a['at']),
            (string) $a['headerUuid'],
            ExchangeOutcome::from($a['outcome']),
            isset($a['errorCode']) ? (int) $a['errorCode'] : null,
            $a['problem'] ?? null,
            $a['responseUuid'] ?? null
        );
    }
}
