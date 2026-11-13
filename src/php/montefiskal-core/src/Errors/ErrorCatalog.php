<?php

declare(strict_types=1);

namespace MonteFiskal\Errors;

use MonteFiskal\Resources;

/** The data-driven fault catalogue shared with the .NET library (data/error-codes.json). */
final class ErrorCatalog
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $entries = null;

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return self::$entries ??= Resources::json('data/error-codes.json')['codes'];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $code): ?array
    {
        foreach (self::all() as $e) {
            if (($e['code'] ?? null) === $code) {
                return $e;
            }
        }
        foreach (self::all() as $e) {
            if (isset($e['range']) && $code >= $e['range'][0] && $code <= $e['range'][1]) {
                return $e;
            }
        }
        return null;
    }

    /** Classifies a fault. Unknown codes are permanent; a SERVER fault code is retryable. */
    public static function classify(?int $code, string $faultCode, string $faultString, ?string $requestUuid, ?string $responseUuid): FiscalError
    {
        $entry = $code !== null ? self::find($code) : null;
        $server = str_ends_with(strtoupper($faultCode), 'SERVER');
        if ($entry !== null) {
            return new FiscalError(
                $code,
                FiscalErrorClass::from($entry['class']),
                (bool) ($entry['retryable'] ?? false) || $server,
                (bool) ($entry['blocking'] ?? false),
                $faultCode,
                $faultString,
                $requestUuid,
                $responseUuid
            );
        }
        return new FiscalError($code, $server ? FiscalErrorClass::Server : FiscalErrorClass::Unknown, $server, false, $faultCode, $faultString, $requestUuid, $responseUuid);
    }
}
