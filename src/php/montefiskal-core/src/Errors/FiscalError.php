<?php

declare(strict_types=1);

namespace MonteFiskal\Errors;

/** A fault returned by CIS (a value, not an exception; architecture section 2.4). */
final readonly class FiscalError
{
    public function __construct(
        public ?int $code,
        public FiscalErrorClass $class,
        public bool $retryable,
        public bool $blocking,
        public string $faultCode,
        public string $faultString,
        public ?string $requestUuid,
        public ?string $responseUuid,
        public ?string $hint = null,
    ) {
    }

    public function withHint(string $hint): self
    {
        return new self($this->code, $this->class, $this->retryable, $this->blocking, $this->faultCode, $this->faultString, $this->requestUuid, $this->responseUuid, $hint);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'class' => $this->class->value, 'retryable' => $this->retryable, 'blocking' => $this->blocking,
            'faultCode' => $this->faultCode, 'faultString' => $this->faultString, 'requestUuid' => $this->requestUuid,
            'responseUuid' => $this->responseUuid, 'hint' => $this->hint];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            isset($a['code']) ? (int) $a['code'] : null,
            FiscalErrorClass::from($a['class']),
            (bool) $a['retryable'],
            (bool) $a['blocking'],
            (string) $a['faultCode'],
            (string) $a['faultString'],
            $a['requestUuid'] ?? null,
            $a['responseUuid'] ?? null,
            $a['hint'] ?? null
        );
    }

    public function __toString(): string
    {
        return ($this->code ?? '-') . ' ' . $this->class->value . ': ' . $this->faultString;
    }
}
