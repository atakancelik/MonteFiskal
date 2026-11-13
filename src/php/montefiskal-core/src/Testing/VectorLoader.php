<?php

declare(strict_types=1);

namespace MonteFiskal\Testing;

use MonteFiskal\Resources;

/** Loads test-vectors/ (environment variable MONTEFISKAL_VECTORS overrides the location). */
final class VectorLoader
{
    public static function directory(): string
    {
        $env = getenv('MONTEFISKAL_VECTORS');
        return $env !== false && $env !== '' ? rtrim($env, '/') : Resources::path('test-vectors');
    }

    /** @return list<array<string, mixed>> */
    public static function load(string $kind): array
    {
        $files = glob(self::directory() . '/' . $kind . '/*.json') ?: [];
        sort($files, SORT_STRING);
        return array_map(static fn (string $f): array => json_decode((string) file_get_contents($f), true, 512, JSON_THROW_ON_ERROR), $files);
    }

    /** @return array<string, mixed> */
    public static function get(string $id): array
    {
        $kind = explode('-', $id, 2)[0];
        foreach (self::load($kind) as $v) {
            if ($v['id'] === $id) {
                return $v;
            }
        }
        throw new \InvalidArgumentException('Unknown vector ' . $id);
    }
}
