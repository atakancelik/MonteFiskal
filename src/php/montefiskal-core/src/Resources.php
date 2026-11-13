<?php

declare(strict_types=1);

namespace MonteFiskal;

/**
 * Locates the shared data files (data/, schemas/) that both languages use. In the repository and in the Composer
 * package they sit at the package root; the WordPress plugin bundles them and calls setRoot().
 */
final class Resources
{
    private static ?string $root = null;

    public static function setRoot(string $root): void
    {
        self::$root = rtrim($root, '/\\');
    }

    public static function root(): string
    {
        return self::$root ??= dirname(__DIR__, 4);
    }

    public static function path(string $relative): string
    {
        return self::root() . '/' . ltrim($relative, '/');
    }

    public static function read(string $relative): string
    {
        $p = self::path($relative);
        $s = @file_get_contents($p);
        if ($s === false) {
            throw new \RuntimeException("MonteFiskal resource missing: {$relative}");
        }
        return $s;
    }

    /** @return array<mixed> */
    public static function json(string $relative): array
    {
        return json_decode(self::read($relative), true, 512, JSON_THROW_ON_ERROR);
    }
}
