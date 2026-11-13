<?php

declare(strict_types=1);

namespace MonteFiskal\Model;

use DateTimeImmutable;
use MonteFiskal\Calculation\Dec;
use MonteFiskal\Numbering\FiscalTime;

/** @internal Helpers shared by the model records. */
final class Arr
{
    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    public static function compact(array $a): array
    {
        return array_filter($a, static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    public static function dec(mixed $v): ?string
    {
        return $v === null ? null : Dec::of(is_int($v) ? $v : (string) $v);
    }

    public static function bool(mixed $v): ?bool
    {
        return $v === null ? null : (bool) $v;
    }

    public static function time(mixed $v): ?DateTimeImmutable
    {
        return $v === null ? null : FiscalTime::parse((string) $v);
    }

    public static function iso(?DateTimeImmutable $d): ?string
    {
        return $d?->format('Y-m-d\TH:i:sP');
    }

    /**
     * A copy of a readonly record with some constructor arguments replaced.
     *
     * @template T of object
     * @param T $object
     * @param array<string, mixed> $changes
     * @return T
     */
    public static function with(object $object, array $changes): object
    {
        $r = new \ReflectionClass($object);
        $args = [];
        foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
            $n = $p->getName();
            $args[$n] = array_key_exists($n, $changes) ? $changes[$n] : $object->{$n};
        }
        return $r->newInstanceArgs($args);
    }
}
