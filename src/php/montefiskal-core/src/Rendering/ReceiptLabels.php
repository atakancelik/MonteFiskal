<?php

declare(strict_types=1);

namespace MonteFiskal\Rendering;

use MonteFiskal\Resources;

/** Printed labels from data/receipt-labels.json (me, en, tr). */
final class ReceiptLabels
{
    /** @var array<string, mixed>|null */
    private static ?array $doc = null;
    /** @var array<string, mixed> */
    private array $lang;

    public function __construct(string $language = 'me')
    {
        self::$doc ??= Resources::json('data/receipt-labels.json');
        $this->lang = self::$doc['languages'][$language] ?? self::$doc['languages']['en'];
    }

    public function get(string $key): string
    {
        return (string) $this->lang[$key];
    }

    public function method(string $wire): string
    {
        return (string) ($this->lang['methods'][$wire] ?? $wire);
    }
}
