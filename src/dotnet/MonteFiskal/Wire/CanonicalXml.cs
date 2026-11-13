using System.Text;

namespace MonteFiskal.Wire;

/// <summary>
/// A minimal element tree that serializes directly into exclusive-C14N form: attributes sorted by name in
/// code-point order, empty elements written as start and end tag, no whitespace, C14N escaping.
/// Because the output is already canonical, its SHA-256 is the digest of the signature reference (BR-S3).
/// </summary>
public sealed class XNode
{
    private readonly List<KeyValuePair<string, string>> _attributes = [];
    private readonly List<XNode> _children = [];

    public XNode(string name, string? defaultNamespace = null)
    {
        Name = name;
        DefaultNamespace = defaultNamespace;
    }

    /// <summary>A child that is already serialized in canonical form (a stored body element).</summary>
    public static XNode FromRaw(string canonicalXml) => new("#raw") { RawXml = canonicalXml };

    public string Name { get; }
    public string? RawXml { get; private init; }
    public string? DefaultNamespace { get; }
    public string? Text { get; private set; }

    public XNode Attr(string name, string? value)
    {
        if (value is not null)
        {
            _attributes.RemoveAll(a => a.Key == name);
            _attributes.Add(new(name, value));
        }
        return this;
    }

    public XNode Attrs(IEnumerable<KeyValuePair<string, string>> attributes)
    {
        foreach (var a in attributes)
        {
            Attr(a.Key, a.Value);
        }
        return this;
    }

    public XNode Add(XNode? child)
    {
        if (child is not null)
        {
            _children.Add(child);
        }
        return this;
    }

    public XNode WithText(string text)
    {
        Text = text;
        return this;
    }

    public override string ToString()
    {
        var sb = new StringBuilder();
        Write(sb);
        return sb.ToString();
    }

    public void Write(StringBuilder sb)
    {
        ArgumentNullException.ThrowIfNull(sb);
        if (RawXml is not null)
        {
            sb.Append(RawXml);
            return;
        }
        sb.Append('<').Append(Name);
        if (DefaultNamespace is not null)
        {
            sb.Append(" xmlns=\"").Append(EscapeAttribute(DefaultNamespace)).Append('"');
        }
        foreach (var a in _attributes.OrderBy(a => a.Key, StringComparer.Ordinal))
        {
            sb.Append(' ').Append(a.Key).Append("=\"").Append(EscapeAttribute(a.Value)).Append('"');
        }
        sb.Append('>');
        if (Text is not null)
        {
            sb.Append(EscapeText(Text));
        }
        foreach (var c in _children)
        {
            c.Write(sb);
        }
        sb.Append("</").Append(Name).Append('>');
    }

    /// <summary>Attribute escaping of Canonical XML 1.0 §2.3.</summary>
    public static string EscapeAttribute(string value)
    {
        var sb = new StringBuilder(value.Length + 8);
        foreach (var ch in value)
        {
            sb.Append(ch switch
            {
                '&' => "&amp;",
                '<' => "&lt;",
                '"' => "&quot;",
                '\t' => "&#x9;",
                '\n' => "&#xA;",
                '\r' => "&#xD;",
                _ => ch.ToString(),
            });
        }
        return sb.ToString();
    }

    /// <summary>Text escaping of Canonical XML 1.0 §2.3.</summary>
    public static string EscapeText(string value) =>
        value.Replace("&", "&amp;", StringComparison.Ordinal).Replace("<", "&lt;", StringComparison.Ordinal)
             .Replace(">", "&gt;", StringComparison.Ordinal).Replace("\r", "&#xD;", StringComparison.Ordinal);
}
