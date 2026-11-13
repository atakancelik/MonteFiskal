using System.Reflection;
using System.Security.Cryptography;
using System.Xml;
using System.Xml.Schema;

namespace MonteFiskal.Wire;

/// <summary>
/// The bundled official schema revision (schemas/fs-v1/2026-11), compiled unmodified. The .NET schema engine
/// accepts the <c>\/</c> escapes of the official file (S-23), so no patched copy is needed here.
/// </summary>
public static class SchemaSet
{
    public const string Revision = "fs-v1@2026-11";
    private const string Prefix = "MonteFiskal.Schemas.fs-v1.2026-11.";
    private static readonly Lazy<XmlSchemaSet> Compiled = new(Compile);

    /// <summary>SHA-256 of the bundled XSD, as in <c>SHA256SUMS</c> and the catalog.</summary>
    public static string XsdSha256 { get; } = Convert.ToHexStringLower(SHA256.HashData(Resource("eficg-fiscalization-service.xsd")));

    public static XmlSchemaSet Schemas => Compiled.Value;

    internal static byte[] Resource(string name)
    {
        using var s = Assembly.GetExecutingAssembly().GetManifestResourceStream(Prefix + name) ?? throw new InvalidOperationException("Missing resource " + name);
        using var ms = new MemoryStream();
        s.CopyTo(ms);
        return ms.ToArray();
    }

    private static XmlSchemaSet Compile()
    {
        var set = new XmlSchemaSet { XmlResolver = null };
        var dsig = new XmlReaderSettings { DtdProcessing = DtdProcessing.Parse, XmlResolver = null };
        using (var r = XmlReader.Create(new MemoryStream(Resource("xmldsig-core-schema.xsd")), dsig))
        {
            set.Add("http://www.w3.org/2000/09/xmldsig#", r);
        }
        using (var r = XmlReader.Create(new MemoryStream(Resource("eficg-fiscalization-service.xsd")), new XmlReaderSettings { DtdProcessing = DtdProcessing.Prohibit, XmlResolver = null }))
        {
            set.Add(RequestBuilder.Namespace, r);
        }
        set.Compile();
        return set;
    }

    /// <summary>Validates a complete request element (with signature). Returns schema errors; empty when valid.</summary>
    public static IReadOnlyList<string> Validate(string xml)
    {
        var errors = new List<string>();
        var settings = new XmlReaderSettings
        {
            ValidationType = ValidationType.Schema,
            Schemas = Schemas,
            DtdProcessing = DtdProcessing.Prohibit,
            XmlResolver = null,
        };
        settings.ValidationFlags |= XmlSchemaValidationFlags.ReportValidationWarnings;
        settings.ValidationEventHandler += (_, e) => errors.Add(e.Message);
        try
        {
            using var reader = XmlReader.Create(new StringReader(xml), settings);
            while (reader.Read())
            {
            }
        }
        catch (XmlException ex)
        {
            errors.Add(ex.Message);
        }
        return errors;
    }

    /// <summary>Validates an unsigned request by adding a placeholder signature first.</summary>
    public static IReadOnlyList<string> ValidateUnsigned(string canonicalRequest) => Validate(Security.XmlSigner.WithPlaceholderSignature(canonicalRequest));

    /// <summary>True when <paramref name="value"/> is valid for the named simple type of the schema (for example <c>InvNumSType</c>).</summary>
    public static bool IsValid(string simpleType, string value)
    {
        if (Schemas.GlobalTypes[new XmlQualifiedName(simpleType, RequestBuilder.Namespace)] is not XmlSchemaSimpleType t || t.Datatype is null)
        {
            throw new ArgumentException("Unknown simple type " + simpleType, nameof(simpleType));
        }
        try
        {
            t.Datatype.ParseValue(value, new NameTable(), null);
            return true;
        }
        catch (XmlSchemaException)
        {
            return false;
        }
        catch (FormatException)
        {
            return false;
        }
        catch (OverflowException)
        {
            return false;
        }
    }
}
