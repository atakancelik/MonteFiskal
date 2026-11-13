<?php

declare(strict_types=1);

namespace MonteFiskal\Wire;

/**
 * A minimal element tree that serializes directly into exclusive-C14N form: attributes sorted by name in
 * code-point order, empty elements written as start and end tag, no whitespace, C14N escaping.
 * Because the output is already canonical, its SHA-256 is the digest of the signature reference (BR-S3).
 */
final class XNode
{
    /** @var array<string, string> */
    private array $attributes = [];
    /** @var list<XNode> */
    private array $children = [];
    private ?string $text = null;
    private ?string $raw = null;

    public function __construct(public readonly string $name, public readonly ?string $defaultNamespace = null)
    {
    }

    /** A child that is already serialized in canonical form (a stored body element). */
    public static function fromRaw(string $canonicalXml): self
    {
        $n = new self('#raw');
        $n->raw = $canonicalXml;
        return $n;
    }

    public function attr(string $name, ?string $value): self
    {
        if ($value !== null) {
            $this->attributes[$name] = $value;
        }
        return $this;
    }

    /** @param array<string, string> $attributes */
    public function attrs(array $attributes): self
    {
        foreach ($attributes as $k => $v) {
            $this->attr((string) $k, $v);
        }
        return $this;
    }

    public function add(?XNode $child): self
    {
        if ($child !== null) {
            $this->children[] = $child;
        }
        return $this;
    }

    public function withText(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function __toString(): string
    {
        return $this->write();
    }

    public function write(): string
    {
        if ($this->raw !== null) {
            return $this->raw;
        }
        $s = '<' . $this->name;
        if ($this->defaultNamespace !== null) {
            $s .= ' xmlns="' . self::escapeAttribute($this->defaultNamespace) . '"';
        }
        $attrs = $this->attributes;
        uksort($attrs, 'strcmp');
        foreach ($attrs as $k => $v) {
            $s .= ' ' . $k . '="' . self::escapeAttribute($v) . '"';
        }
        $s .= '>';
        if ($this->text !== null) {
            $s .= self::escapeText($this->text);
        }
        foreach ($this->children as $c) {
            $s .= $c->write();
        }
        return $s . '</' . $this->name . '>';
    }

    /** Attribute escaping of Canonical XML 1.0 section 2.3. */
    public static function escapeAttribute(string $value): string
    {
        return strtr($value, ['&' => '&amp;', '<' => '&lt;', '"' => '&quot;', "\t" => '&#x9;', "\n" => '&#xA;', "\r" => '&#xD;']);
    }

    /** Text escaping of Canonical XML 1.0 section 2.3. */
    public static function escapeText(string $value): string
    {
        return strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\r" => '&#xD;']);
    }
}
