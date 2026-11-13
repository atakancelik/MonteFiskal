<?php

declare(strict_types=1);

namespace MonteFiskal\Archive;

use DateTimeImmutable;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Numbering\FiscalTime;
use MonteFiskal\Outbox\StoredRequest;
use MonteFiskal\Transport\FiscalOperation;
use ZipArchive;

/**
 * Offline export and import (BR-O11): one <yyyyMMddHHmmss>_<TCR>_<IIC>_request.xml per invoice in a
 * <yyyyMMddHHmmss>_<TCR>_request.zip of at most 15 MB; responses come back as _response.xml files. Needs ext-zip.
 */
final class OfflineExchange
{
    /**
     * Writes the zip archives and returns their paths. Each further archive takes the next second in its name.
     *
     * @param list<StoredRequest> $invoices
     * @param callable(StoredRequest): string $signedRequest
     * @return list<string>
     */
    public static function export(array $invoices, callable $signedRequest, string $tcr, DateTimeImmutable $exportedAt, string $timeZone, string $directory): array
    {
        self::requireZip();
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $pending = array_values(array_filter($invoices, static fn (StoredRequest $i): bool => $i->operation === FiscalOperation::RegisterInvoice && $i->state !== InvoiceState::Fiscalized));
        usort($pending, static fn (StoredRequest $a, StoredRequest $b): int => $a->issuedAt <=> $b->issuedAt);
        $stamp = FiscalTime::local($exportedAt, $timeZone);
        $paths = [];
        $index = 0;
        while ($index < count($pending)) {
            $path = rtrim($directory, '/') . '/' . OfflineNames::requestZip($stamp, $tcr);
            $stamp = $stamp->modify('+1 second');
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create ' . $path);
            }
            $size = 22;
            while ($index < count($pending)) {
                $inv = $pending[$index];
                $xml = $signedRequest($inv);
                $name = OfflineNames::requestFile((string) $inv->issueDateTime, $tcr, (string) $inv->iic);
                $estimate = strlen($xml) + 2 * strlen($name) + 100;
                if ($size + $estimate > OfflineNames::MAX_ZIP_BYTES && $size > 22) {
                    break;
                }
                $zip->addFromString($name, $xml);
                $size += $estimate;
                $index++;
            }
            $zip->close();
            $paths[] = $path;
        }
        return $paths;
    }

    /**
     * Reads a response archive: IKOF from the file name and the response XML.
     *
     * @return list<array{iic: string, responseXml: string}>
     */
    public static function readResponses(string $zipPath): array
    {
        self::requireZip();
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Cannot open ' . $zipPath);
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = basename((string) $zip->getNameIndex($i));
            $parts = explode('_', $name);
            if (!str_ends_with($name, '_response.xml') || count($parts) < 4) {
                continue;
            }
            $out[] = ['iic' => $parts[2], 'responseXml' => (string) $zip->getFromIndex($i)];
        }
        $zip->close();
        return $out;
    }

    private static function requireZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('The offline export needs the PHP zip extension (ext-zip).');
        }
    }
}
