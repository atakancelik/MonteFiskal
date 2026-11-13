// Cross-signature check (architecture section 7, cross-signature workflow).
//   sign   <cert.pem> <key.pem> <outDir>  builds every xml vector, signs it and computes an IKOF; writes <id>.xml and <id>.ikof
//   verify <dir>                          verifies every <id>.xml in dir with the platform SignedXml and every <id>.ikof
// The PHP twin is tools/cross-signature/php/cross-signature.php. Signatures are deterministic, so both sides must
// also produce identical bytes (compared by the workflow).
using System.Globalization;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using MonteFiskal;
using MonteFiskal.Model;
using MonteFiskal.Security;
using MonteFiskal.Testing;
using MonteFiskal.Wire;

if (args.Length < 2)
{
    Console.Error.WriteLine("usage: sign <cert.pem> <key.pem> <outDir> | verify <dir>");
    return 2;
}

if (args[0] == "sign")
{
    using var cert = X509Certificate2.CreateFromPemFile(args[1], args[2]);
    using var key = cert.GetRSAPrivateKey()!;
    Directory.CreateDirectory(args[3]);
    var n = 0;
    foreach (var v in VectorLoader.Load("xml"))
    {
        var xml = Build(v);
        File.WriteAllText(Path.Combine(args[3], v.Id + ".xml"), XmlSigner.Sign(xml, key, cert));
        n++;
    }
    foreach (var v in VectorLoader.Load("iic"))
    {
        var i = v.Input;
        string S(string name) => i.GetProperty(name).ValueKind == System.Text.Json.JsonValueKind.Number ? i.GetProperty(name).GetRawText() : i.GetProperty(name).GetString()!;
        var p = new IkofParameters(S("issuerTin"), S("issueDateTime"), S("ordinal"), S("businessUnit"), S("tcr"), S("software"), S("totalPrice"));
        var c = Ikof.Compute(p, key);
        File.WriteAllText(Path.Combine(args[3], v.Id + ".ikof"), c.Concatenation + "\n" + c.IicSignature + "\n" + c.Iic + "\n");
        n++;
    }
    Console.WriteLine($".NET signed {n} items into {args[3]}");
    return 0;
}

if (args[0] == "verify")
{
    var failures = 0;
    var count = 0;
    foreach (var f in Directory.GetFiles(args[1], "*.xml").Order(StringComparer.Ordinal))
    {
        var check = XmlSignatureVerifier.Verify(File.ReadAllText(f), "Request");
        var schema = SchemaSet.Validate(File.ReadAllText(f));
        count++;
        if (!check.Valid || schema.Count > 0)
        {
            failures++;
            Console.Error.WriteLine($"FAIL {Path.GetFileName(f)}: {check.Problem} {string.Join("; ", schema)}");
        }
    }
    foreach (var f in Directory.GetFiles(args[1], "*.ikof").Order(StringComparer.Ordinal))
    {
        var lines = File.ReadAllLines(f);
        var certPem = File.ReadAllText(Path.Combine(args[1], "cert.pem"));
        using var pub = X509Certificate2.CreateFromPem(certPem).GetRSAPublicKey()!;
        count++;
        if (!Ikof.Verify(lines[0], lines[1], pub) || Ikof.IicFromSignature(Convert.FromHexString(lines[1])) != lines[2])
        {
            failures++;
            Console.Error.WriteLine($"FAIL {Path.GetFileName(f)}: IKOF does not verify");
        }
    }
    Console.WriteLine($".NET verified {count} items from {args[1]}: {failures} failures");
    return failures == 0 && count > 0 ? 0 : 1;
}

Console.Error.WriteLine("unknown command " + args[0]);
return 2;

static string Build(TestVector v)
{
    var i = v.Input;
    var issue = i.GetProperty("issue");
    var header = new RequestHeader(Guid.Parse(issue.GetProperty("uuid").GetString()!),
        DateTimeOffset.Parse(issue.GetProperty("sendDateTime").GetString()!, CultureInfo.InvariantCulture),
        issue.TryGetProperty("subsequentDelivery", out var sd) ? WireName.Parse<SubsequentDelivery>(sd.GetString()!) : null);
    switch (i.GetProperty("message").GetString())
    {
        case "invoice":
            var profile = v.InputAs<FiscalProfile>("profile");
            var draft = v.InputAs<InvoiceDraft>("draft");
            var ordinal = issue.GetProperty("ordinal").GetInt64();
            var facts = RequestBuilder.Facts(profile, draft, ordinal);
            var inv = RequestBuilder.Invoice(profile, draft, facts, new IssueValues(ordinal, issue.GetProperty("iic").GetString()!, issue.GetProperty("iicSignature").GetString()!));
            return RequestBuilder.InvoiceRequest(inv.ToString(), header, profile.TimeZone);
        case "tcr":
            return RequestBuilder.Root("RegisterTCRRequest", RequestBuilder.Header(header, "Europe/Podgorica"), RequestBuilder.Tcr(v.InputAs<TcrRegistration>("registration"))).ToString();
        default:
            return RequestBuilder.Root("RegisterCashDepositRequest", RequestBuilder.Header(header, "Europe/Podgorica"), RequestBuilder.Deposit(v.InputAs<CashDeposit>("deposit"), "Europe/Podgorica")).ToString();
    }
}
