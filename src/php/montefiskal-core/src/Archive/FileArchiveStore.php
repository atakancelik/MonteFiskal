<?php

declare(strict_types=1);

namespace MonteFiskal\Archive;

/** Archive on disk: root/xx/<id without hyphens>/<UTC stamp>_<kind>.xml, the same layout as the .NET FileArchiveStore. */
final class FileArchiveStore implements ArchiveStore
{
    public function __construct(private readonly string $root)
    {
    }

    public function save(string $requestId, string $kind, string $content): void
    {
        $dir = $this->dir($requestId);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('YmdHisu') . '0';
        file_put_contents($dir . '/' . $stamp . '_' . preg_replace('/[^a-z0-9-]/i', '', $kind) . '.xml', $content, LOCK_EX);
    }

    public function load(string $requestId): array
    {
        $out = [];
        $files = glob($this->dir($requestId) . '/*.xml') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $f) {
            $name = basename($f, '.xml');
            $out[] = ['kind' => substr($name, strpos($name, '_') + 1), 'content' => (string) file_get_contents($f)];
        }
        return $out;
    }

    private function dir(string $id): string
    {
        $id = strtolower(preg_replace('/[^0-9a-f]/i', '', $id) ?? '');
        return rtrim($this->root, '/') . '/' . substr($id, 0, 2) . '/' . $id;
    }
}
