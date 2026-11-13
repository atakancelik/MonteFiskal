using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Security.Cryptography.Xml;
using System.Text;
using System.Xml;

namespace MonteFiskal.Security;

/// <summary>
/// Enveloped XML-DSig with the fixed profile of BR-S1 to BR-S4: one reference to <c>#Request</c>, transforms
/// enveloped-signature and exclusive C14N, RSA-SHA256, SHA-256 digest, one <c>X509Certificate</c>.
/// The unsigned element written by <see cref="Wire.XNode"/> is already canonical, so the digest is taken over
/// its bytes and the output is byte-controlled.
/// </summary>
public static class XmlSigner
{
    public const string DsigNamespace = "http://www.w3.org/2000/09/xmldsig#";

    /// <summary>Signs a canonical request element and returns it with the signature as its last child.</summary>
    public static string Sign(string canonicalRequest, RSA key, X509Certificate2 certificate, string referenceId = "Request")
    {
        ArgumentNullException.ThrowIfNull(canonicalRequest);
        ArgumentNullException.ThrowIfNull(key);
        ArgumentNullException.ThrowIfNull(certificate);
        var digest = Convert.ToBase64String(SHA256.HashData(Encoding.UTF8.GetBytes(canonicalRequest)));
        var signedInfo = SignedInfo(digest, withNamespace: true, referenceId);
        var signature = Convert.ToBase64String(key.SignData(Encoding.UTF8.GetBytes(signedInfo), HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1));
        var cert = Convert.ToBase64String(certificate.RawData);
        var element = "<Signature xmlns=\"" + DsigNamespace + "\">" + SignedInfo(digest, withNamespace: false, referenceId) +
                      "<SignatureValue>" + signature + "</SignatureValue><KeyInfo><X509Data><X509Certificate>" + cert +
                      "</X509Certificate></X509Data></KeyInfo></Signature>";
        var close = canonicalRequest.LastIndexOf("</", StringComparison.Ordinal);
        return canonicalRequest[..close] + element + canonicalRequest[close..];
    }

    /// <summary>The canonical <c>SignedInfo</c>. Inside the document the namespace is inherited from <c>Signature</c>.</summary>
    internal static string SignedInfo(string digestBase64, bool withNamespace, string referenceId = "Request") =>
        "<SignedInfo" + (withNamespace ? " xmlns=\"" + DsigNamespace + "\"" : "") + ">" +
        "<CanonicalizationMethod Algorithm=\"http://www.w3.org/2001/10/xml-exc-c14n#\"></CanonicalizationMethod>" +
        "<SignatureMethod Algorithm=\"http://www.w3.org/2001/04/xmldsig-more#rsa-sha256\"></SignatureMethod>" +
        "<Reference URI=\"#" + referenceId + "\"><Transforms>" +
        "<Transform Algorithm=\"http://www.w3.org/2000/09/xmldsig#enveloped-signature\"></Transform>" +
        "<Transform Algorithm=\"http://www.w3.org/2001/10/xml-exc-c14n#\"></Transform></Transforms>" +
        "<DigestMethod Algorithm=\"http://www.w3.org/2001/04/xmlenc#sha256\"></DigestMethod>" +
        "<DigestValue>" + digestBase64 + "</DigestValue></Reference></SignedInfo>";

    /// <summary>A dummy signature that satisfies the schema; used only for local schema validation of unsigned requests.</summary>
    internal static string WithPlaceholderSignature(string canonicalRequest)
    {
        var element = "<Signature xmlns=\"" + DsigNamespace + "\">" + SignedInfo("AAAA", false) +
                      "<SignatureValue>AAAA</SignatureValue><KeyInfo><X509Data><X509Certificate>AAAA</X509Certificate></X509Data></KeyInfo></Signature>";
        var close = canonicalRequest.LastIndexOf("</", StringComparison.Ordinal);
        return canonicalRequest[..close] + element + canonicalRequest[close..];
    }
}

/// <summary>Result of verifying an enveloped signature.</summary>
public sealed record SignatureCheck(bool Present, bool Valid, X509Certificate2? Certificate, string? Problem);

/// <summary>Verifies enveloped signatures of requests and CIS responses (BR-S6) with the platform implementation.</summary>
public static class XmlSignatureVerifier
{
    /// <summary>Verifies the signature of the element with the given <c>Id</c> (Request or Response) in <paramref name="xml"/>.</summary>
    public static SignatureCheck Verify(string xml, string? expectedId = null)
    {
        var doc = new XmlDocument { PreserveWhitespace = true, XmlResolver = null };
        using (var reader = XmlReader.Create(new StringReader(xml), new XmlReaderSettings { DtdProcessing = DtdProcessing.Prohibit, XmlResolver = null }))
        {
            doc.Load(reader);
        }
        var ns = new XmlNamespaceManager(doc.NameTable);
        ns.AddNamespace("ds", XmlSigner.DsigNamespace);
        var sigs = doc.SelectNodes("//ds:Signature", ns);
        if (sigs is null || sigs.Count == 0)
        {
            return new SignatureCheck(false, false, null, "no signature");
        }
        if (sigs.Count > 1)
        {
            return new SignatureCheck(true, false, null, "more than one signature");
        }
        var sigEl = (XmlElement)sigs[0]!;
        var signed = new SignedXml(doc);
        signed.LoadXml(sigEl);
        if (signed.SignedInfo is null || signed.SignedInfo.References.Count != 1)
        {
            return new SignatureCheck(true, false, null, "exactly one reference expected");
        }
        var reference = (Reference)signed.SignedInfo.References[0]!;
        if (expectedId is not null && reference.Uri != "#" + expectedId)
        {
            return new SignatureCheck(true, false, null, $"reference {reference.Uri} is not #{expectedId}");
        }
        X509Certificate2? cert = null;
        foreach (var clause in signed.KeyInfo)
        {
            if (clause is KeyInfoX509Data data && data.Certificates is { Count: > 0 })
            {
                cert = (X509Certificate2)data.Certificates[0]!;
            }
        }
        if (cert is null)
        {
            return new SignatureCheck(true, false, null, "no X509Certificate in KeyInfo");
        }
        var ok = signed.CheckSignature(cert, verifySignatureOnly: true);
        return new SignatureCheck(true, ok, cert, ok ? null : "signature value or digest does not match");
    }
}
