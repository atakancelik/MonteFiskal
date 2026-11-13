<?php

declare(strict_types=1);

namespace MonteFiskal\Wire;

use DOMDocument;
use MonteFiskal\Resources;
use MonteFiskal\Security\XmlSigner;

/**
 * The bundled official schema revision (schemas/fs-v1/2026-11/official). libxml2 cannot compile the two \/
 * escapes of the official file (S-23), so a copy with them replaced by / is written to a temporary directory at
 * run time. The copy is never committed; its source digest is checked first (Q-20).
 */
final class SchemaSet
{
    public const REVISION = 'fs-v1@2026-11';
    public const XSD_SHA256 = '242c657173ec62f2193447ebc52e3ef26fc4eaecaf7e335c9b9631935591243a';
    private const DIR = 'schemas/fs-v1/2026-11/official/';

    private static ?string $dir = null;

    /** Directory with the patched XSD and the unmodified xmldsig schema. */
    public static function directory(): string
    {
        if (self::$dir !== null && is_file(self::$dir . '/eficg-fiscalization-service.xsd')) {
            return self::$dir;
        }
        $xsd = Resources::read(self::DIR . 'eficg-fiscalization-service.xsd');
        if (hash('sha256', $xsd) !== self::XSD_SHA256) {
            throw new \RuntimeException('The bundled XSD does not match its recorded SHA-256 (' . self::REVISION . ').');
        }
        $patched = str_replace('\\/', '/', $xsd);
        $dir = sys_get_temp_dir() . '/montefiskal-xsd-' . substr(hash('sha256', $patched), 0, 16);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        self::writeOnce($dir . '/eficg-fiscalization-service.xsd', $patched);
        self::writeOnce($dir . '/xmldsig-core-schema.xsd', Resources::read(self::DIR . 'xmldsig-core-schema.xsd'));
        return self::$dir = $dir;
    }

    private static function writeOnce(string $path, string $content): void
    {
        if (is_file($path) && hash_file('sha256', $path) === hash('sha256', $content)) {
            return;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);
        rename($tmp, $path);
    }

    /**
     * Validates a complete request element (with signature). Returns schema errors; empty when valid.
     *
     * @return list<string>
     */
    public static function validate(string $xml): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (preg_match('/<!DOCTYPE/i', $xml) || !$doc->loadXML($xml, LIBXML_NONET)) {
                return self::errors() ?: ['not well-formed XML'];
            }
            $ok = $doc->schemaValidate(self::directory() . '/eficg-fiscalization-service.xsd');
            $errors = self::errors();
            return $ok ? [] : ($errors ?: ['schema validation failed']);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /** @return list<string> */
    public static function validateUnsigned(string $canonicalRequest): array
    {
        return self::validate(XmlSigner::withPlaceholderSignature($canonicalRequest));
    }

    /** @var array<string, string> */
    private static array $typeSchemas = [];

    /** True when $value is valid for the named simple type of the schema (for example InvNumSType). */
    public static function isValid(string $simpleType, string $value): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $simpleType)) {
            throw new \InvalidArgumentException('Unknown simple type ' . $simpleType);
        }
        $dir = self::directory();
        $file = self::$typeSchemas[$simpleType] ??= (function () use ($dir, $simpleType): string {
            if (!str_contains((string) file_get_contents($dir . '/eficg-fiscalization-service.xsd'), 'simpleType name="' . $simpleType . '"')) {
                throw new \InvalidArgumentException('Unknown simple type ' . $simpleType);
            }
            $f = $dir . '/type-' . $simpleType . '.xsd';
            self::writeOnce($f, '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:fs="' . RequestBuilder::NS
                . '" targetNamespace="' . RequestBuilder::NS . '" elementFormDefault="qualified"><xs:include schemaLocation="eficg-fiscalization-service.xsd"/>'
                . '<xs:element name="MonteFiskalValue" type="fs:' . $simpleType . '"/></xs:schema>');
            return $f;
        })();
        $doc = new DOMDocument();
        $doc->loadXML('<MonteFiskalValue xmlns="' . RequestBuilder::NS . '">' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</MonteFiskalValue>');
        $prev = libxml_use_internal_errors(true);
        try {
            return $doc->schemaValidate($file);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /** @return list<string> */
    private static function errors(): array
    {
        return array_values(array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors()));
    }
}
