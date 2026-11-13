using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Xml;
using MonteFiskal.Archive;
using MonteFiskal.Calculation;
using MonteFiskal.Model;
using MonteFiskal.Numbering;
using MonteFiskal.Testing;
using MonteFiskal.Validation;
using MonteFiskal.Verification;
using MonteFiskal.Wire;

namespace MonteFiskal.Tests;

/// <summary>Runs every shared vector. Vector IDs are logged so that CI can compare the .NET and PHP sets (vectors-parity).</summary>
public sealed class VectorTests
{
    public static TheoryData<string> Ids(string kind)
    {
        var data = new TheoryData<string>();
        foreach (var v in VectorLoader.Load(kind))
        {
            data.Add(v.Id);
        }
        return data;
    }

    public static TheoryData<string> Iic => Ids("iic");
    public static TheoryData<string> Url => Ids("url");
    public static TheoryData<string> Number => Ids("number");
    public static TheoryData<string> Time => Ids("time");
    public static TheoryData<string> Amounts => Ids("amounts");
    public static TheoryData<string> Xml => Ids("xml");
    public static TheoryData<string> Validate => Ids("validate");
    public static TheoryData<string> Fault => Ids("fault");
    public static TheoryData<string> Response => Ids("response");
    public static TheoryData<string> Offline => Ids("offline");

    internal static TestVector Run(string id)
    {
        var log = Environment.GetEnvironmentVariable("MONTEFISKAL_VECTOR_LOG");
        if (!string.IsNullOrEmpty(log))
        {
            lock (typeof(VectorTests))
            {
                File.AppendAllText(log, id + "\n");
            }
        }
        return VectorLoader.Get(id);
    }

    private static string S(JsonElement e, string name) => e.GetProperty(name).ValueKind == JsonValueKind.Number ? e.GetProperty(name).GetRawText() : e.GetProperty(name).GetString()!;

    [Theory, MemberData(nameof(Iic))]
    public void Iic_vector(string id)
    {
        var v = Run(id);
        var i = v.Input;
        var p = new IkofParameters(S(i, "issuerTin"), S(i, "issueDateTime"), S(i, "ordinal"), S(i, "businessUnit"), S(i, "tcr"), S(i, "software"), S(i, "totalPrice"));
        Assert.Equal(v.Expected.GetProperty("concat").GetString(), Ikof.Concatenate(p));
        if (v.Expected.TryGetProperty("publicKeyPem", out var pem))
        {
            using var rsa = RSA.Create();
            rsa.ImportFromPem(pem.GetString());
            var sig = v.Expected.GetProperty("signatureHex").GetString()!;
            Assert.Equal(v.Expected.GetProperty("signatureVerifies").GetBoolean(), Ikof.Verify(Ikof.Concatenate(p), sig, rsa));
            if (v.Expected.TryGetProperty("iic", out var iic))
            {
                // BR-I3: IIC is the MD5 of the signature bytes.
                Assert.Equal(iic.GetString(), Ikof.IicFromSignature(Convert.FromHexString(sig)));
            }
        }
        // BR-I7: signing is deterministic with any key.
        using var key = RSA.Create(2048);
        Assert.Equal(Ikof.Compute(p, key), Ikof.Compute(p, key));
        Assert.True(Ikof.Verify(Ikof.Concatenate(p), Ikof.Compute(p, key).IicSignature, key));
    }

    [Theory, MemberData(nameof(Url))]
    public void Url_vector(string id)
    {
        var v = Run(id);
        var i = v.Input;
        var p = new IkofParameters(S(i, "issuerTin"), S(i, "issueDateTime"), S(i, "ordinal"), S(i, "businessUnit"), S(i, "tcr"), S(i, "software"), S(i, "totalPrice"));
        var env = i.GetProperty("environment").GetString() == "production" ? FiscalEnvironment.Production : FiscalEnvironment.Test;
        var enc = i.GetProperty("encoding").GetString() == "none" ? VerificationUrlEncoding.None : VerificationUrlEncoding.Percent;
        Assert.Equal(v.Expected.GetProperty("url").GetString(), VerificationUrl.Build(VerificationUrl.BaseFor(env), i.GetProperty("iic").GetString()!, p, enc));
    }

    [Theory, MemberData(nameof(Number))]
    public void Number_vector(string id)
    {
        var v = Run(id);
        var i = v.Input;
        var issued = DateTimeOffset.Parse(i.GetProperty("issuedAt").GetString()!, CultureInfo.InvariantCulture);
        var tz = i.GetProperty("timeZone").GetString()!;
        string? error = null;
        string? num = null;
        try
        {
            num = InvoiceNumber.Format(i.GetProperty("businessUnit").GetString()!, i.GetProperty("ordinal").GetInt64(), issued, i.GetProperty("tcr").GetString()!, tz);
            if (!SchemaSet.IsValid("InvNumSType", num))
            {
                error = "SCHEMA";
            }
        }
        catch (ArgumentOutOfRangeException)
        {
            error = "BR-N2";
        }
        if (v.Expected.TryGetProperty("error", out var e))
        {
            Assert.Equal(e.GetString(), error);
            return;
        }
        Assert.Null(error);
        Assert.Equal(v.Expected.GetProperty("invNum").GetString(), num);
        if (v.Expected.TryGetProperty("issueDateTime", out var dt))
        {
            Assert.Equal(dt.GetString(), FiscalTime.Format(issued, tz));
        }
    }

    [Theory, MemberData(nameof(Time))]
    public void Time_vector(string id)
    {
        var v = Run(id);
        var i = v.Input;
        var instant = DateTimeOffset.Parse(i.GetProperty("instant").GetString()!, CultureInfo.InvariantCulture);
        var format = i.GetProperty("policy").GetString() == "utc" ? DateTimeFormat.Utc : DateTimeFormat.LocalOffset;
        Assert.Equal(v.Expected.GetProperty("formatted").GetString(), FiscalTime.Format(instant, i.GetProperty("timeZone").GetString()!, format));
    }

    [Theory, MemberData(nameof(Amounts))]
    public void Amounts_vector(string id)
    {
        var v = Run(id);
        var lines = v.InputAs<List<InvoiceLine>>("lines");
        var r = InvoiceCalculator.Default.Calculate(lines, v.Input.GetProperty("issuerInVat").GetBoolean(), v.Input.GetProperty("itemDecimals").GetInt32());
        var items = v.Expected.GetProperty("items").Deserialize<List<Dictionary<string, string>>>()!;
        Assert.Equal(items.Count, r.Lines.Count);
        for (var n = 0; n < items.Count; n++)
        {
            Assert.Equal(items[n].OrderBy(x => x.Key, StringComparer.Ordinal), r.Lines[n].Attributes.OrderBy(x => x.Key, StringComparer.Ordinal));
        }
        Assert.Equal(v.Expected.GetProperty("totals").Deserialize<Dictionary<string, string>>()!.OrderBy(x => x.Key, StringComparer.Ordinal),
            r.Totals.Attributes.OrderBy(x => x.Key, StringComparer.Ordinal));
        var groups = v.Expected.GetProperty("sameTaxes").Deserialize<List<Dictionary<string, string>>>()!;
        Assert.Equal(groups.Count, r.SameTaxes.Count);
        for (var n = 0; n < groups.Count; n++)
        {
            Assert.Equal(groups[n].OrderBy(x => x.Key, StringComparer.Ordinal), r.SameTaxes[n].OrderBy(x => x.Key, StringComparer.Ordinal));
        }
    }

    internal static string BuildXml(TestVector v, out InvoiceFacts? facts)
    {
        var i = v.Input;
        var issue = i.GetProperty("issue");
        var header = new RequestHeader(Guid.Parse(issue.GetProperty("uuid").GetString()!),
            DateTimeOffset.Parse(issue.GetProperty("sendDateTime").GetString()!, CultureInfo.InvariantCulture),
            issue.TryGetProperty("subsequentDelivery", out var sd) ? WireName.Parse<SubsequentDelivery>(sd.GetString()!) : null);
        facts = null;
        switch (i.GetProperty("message").GetString())
        {
            case "invoice":
                var profile = v.InputAs<FiscalProfile>("profile");
                var draft = v.InputAs<InvoiceDraft>("draft");
                var ordinal = issue.GetProperty("ordinal").GetInt64();
                facts = RequestBuilder.Facts(profile, draft, ordinal);
                var inv = RequestBuilder.Invoice(profile, draft, facts, new IssueValues(ordinal, issue.GetProperty("iic").GetString()!, issue.GetProperty("iicSignature").GetString()!));
                return RequestBuilder.InvoiceRequest(inv.ToString(), header, profile.TimeZone);
            case "tcr":
                return RequestBuilder.Root("RegisterTCRRequest", RequestBuilder.Header(header, "Europe/Podgorica"), RequestBuilder.Tcr(v.InputAs<TcrRegistration>("registration"))).ToString();
            default:
                return RequestBuilder.Root("RegisterCashDepositRequest", RequestBuilder.Header(header, "Europe/Podgorica"), RequestBuilder.Deposit(v.InputAs<CashDeposit>("deposit"), "Europe/Podgorica")).ToString();
        }
    }

    [Theory, MemberData(nameof(Xml))]
    public void Xml_vector(string id)
    {
        var v = Run(id);
        var xml = BuildXml(v, out var facts);
        var e = v.Expected;
        Assert.Equal(e.GetProperty("unsignedXml").GetString(), xml);
        var bytes = Encoding.UTF8.GetBytes(xml);
        Assert.Equal(e.GetProperty("byteLength").GetInt32(), bytes.Length);
        Assert.Equal(e.GetProperty("canonicalSha256").GetString(), Convert.ToHexStringLower(SHA256.HashData(bytes)));
        // The written form is already exclusive C14N: the platform canonicalizer gives the same bytes.
        Assert.Equal(xml, PlatformExcC14N(xml));
        if (facts is not null)
        {
            Assert.Equal(e.GetProperty("invNum").GetString(), facts.InvNum);
            Assert.Equal(e.GetProperty("issueDateTime").GetString(), facts.IssueDateTime);
            Assert.Equal(e.GetProperty("iicConcat").GetString(), facts.IicConcat);
            Assert.Equal(e.GetProperty("typeOfInv").GetString(), WireName.Of(facts.TypeOfInv));
        }
        // Every generated request is valid against the bundled official XSD.
        Assert.Empty(SchemaSet.ValidateUnsigned(xml));
    }

    internal static string PlatformExcC14N(string xml)
    {
        var doc = new XmlDocument { PreserveWhitespace = true };
        doc.LoadXml(xml);
        var t = new System.Security.Cryptography.Xml.XmlDsigExcC14NTransform();
        t.LoadInput(doc);
        using var s = (Stream)t.GetOutput(typeof(Stream));
        using var r = new StreamReader(s, Encoding.UTF8);
        return r.ReadToEnd();
    }

    [Theory, MemberData(nameof(Validate))]
    public void Validate_vector(string id)
    {
        var v = Run(id);
        var i = v.Input;
        var message = i.GetProperty("message").GetString();
        if (message == "catalogTypes")
        {
            ValidateCatalogTypes(i.GetProperty("samples"));
            return;
        }
        ValidationResult result = message switch
        {
            "invoice" => InvoiceValidator.Validate(v.InputAs<FiscalProfile>("profile"), v.InputAs<InvoiceDraft>("draft"),
                DateTimeOffset.Parse(i.GetProperty("now").GetString()!, CultureInfo.InvariantCulture)),
            "tcr" => TcrValidator.Validate(v.InputAs<TcrRegistration>("registration"), DateOnly.Parse(i.GetProperty("today").GetString()!, CultureInfo.InvariantCulture)),
            _ => DepositValidator.Validate(v.InputAs<CashDeposit>("deposit")),
        };
        var expected = v.Expected.GetProperty("findings").EnumerateArray()
            .Select(f => (f.GetProperty("rule").GetString()!, f.GetProperty("severity").GetString()!)).ToList();
        var actual = result.Findings.Select(f => (f.Rule, f.Severity == Severity.Error ? "error" : "warning")).Distinct().Order().ToList();
        Assert.Equal(expected, actual);
        Assert.Equal(v.Expected.GetProperty("valid").GetBoolean(), result.IsValid);
    }

    private static void ValidateCatalogTypes(JsonElement samples)
    {
        using var catalog = JsonDocument.Parse(File.ReadAllText(Path.Combine(VectorLoader.RepositoryRoot, "schemas", "fs-v1", "2026-11", "message-catalog.json")));
        var patternTypes = catalog.RootElement.GetProperty("simpleTypes").EnumerateObject().Where(t => t.Value.TryGetProperty("patterns", out _)).Select(t => t.Name).ToHashSet();
        var covered = samples.EnumerateObject().Select(s => s.Name).ToHashSet();
        Assert.Subset(covered, patternTypes);
        foreach (var t in samples.EnumerateObject())
        {
            foreach (var ok in t.Value.GetProperty("valid").EnumerateArray())
            {
                Assert.True(SchemaSet.IsValid(t.Name, ok.GetString()!), $"{t.Name} should accept '{ok.GetString()}'");
            }
            foreach (var bad in t.Value.GetProperty("invalid").EnumerateArray())
            {
                Assert.False(SchemaSet.IsValid(t.Name, bad.GetString()!), $"{t.Name} should reject '{bad.GetString()}'");
            }
        }
    }

    [Theory, MemberData(nameof(Fault))]
    public void Fault_vector(string id)
    {
        var v = Run(id);
        var i = v.Input;
        var r = ResponseParser.Parse(i.GetProperty("httpStatus").GetInt32(), i.GetProperty("body").GetString());
        var e = v.Expected;
        var outcome = e.GetProperty("outcome").GetString();
        Assert.Equal(outcome, r.Outcome switch
        {
            ExchangeOutcome.Fault => "fault",
            ExchangeOutcome.Transport => "transport",
            ExchangeOutcome.MalformedResponse => "malformedResponse",
            _ => "success",
        });
        Assert.Equal(e.GetProperty("retryable").GetBoolean(), r.Retryable);
        if (outcome != "fault")
        {
            return;
        }
        var err = r.Error!;
        Assert.Equal(e.GetProperty("code").ValueKind == JsonValueKind.Null ? null : e.GetProperty("code").GetInt32(), err.Code);
        Assert.Equal(e.GetProperty("class").GetString(), WireName.Of(err.Class));
        Assert.Equal(e.GetProperty("blocking").GetBoolean(), err.Blocking);
        Assert.Equal(e.GetProperty("faultCode").GetString(), err.FaultCode);
        Assert.Equal(e.GetProperty("faultString").GetString(), err.FaultString);
        Assert.Equal(e.GetProperty("requestUuid").GetString(), err.RequestUuid?.ToString("D"));
        Assert.Equal(e.GetProperty("responseUuid").GetString(), err.ResponseUuid?.ToString("D"));
    }

    [Theory, MemberData(nameof(Response))]
    public void Response_vector(string id)
    {
        var v = Run(id);
        var i = v.Input;
        var r = ResponseParser.Parse(i.GetProperty("httpStatus").GetInt32(), i.GetProperty("body").GetString(),
            i.GetProperty("verifySignature").GetBoolean() ? ResponseSignaturePolicy.Verify : ResponseSignaturePolicy.Ignore);
        var e = v.Expected;
        Assert.Equal(ExchangeOutcome.Success, r.Outcome);
        Assert.Equal(e.GetProperty("element").GetString(), r.Element);
        Assert.Equal(e.GetProperty("result").GetString(), r.Result);
        Assert.Equal(e.GetProperty("requestUuid").GetString(), r.RequestUuid?.ToString("D"));
        Assert.Equal(e.GetProperty("responseUuid").GetString(), r.ResponseUuid?.ToString("D"));
        Assert.Equal(e.GetProperty("sendDateTime").GetString(), r.SendDateTime);
        Assert.Equal(e.GetProperty("signaturePresent").GetBoolean(), r.Signature?.Present ?? false);
    }

    [Theory, MemberData(nameof(Offline))]
    public void Offline_vector(string id)
    {
        var v = Run(id);
        var i = v.Input;
        var files = i.GetProperty("invoices").EnumerateArray()
            .Select(x => OfflineNames.RequestFile(x.GetProperty("issueDateTime").GetString()!, x.GetProperty("tcr").GetString()!, x.GetProperty("iic").GetString()!)).ToList();
        Assert.Equal(v.Expected.GetProperty("files").EnumerateArray().Select(x => x.GetString()!).ToList(), files);
        var at = OfflineNames.ParseWire(i.GetProperty("exportedAt").GetString()!);
        Assert.Equal(v.Expected.GetProperty("zip").GetString(), OfflineNames.RequestZip(at, i.GetProperty("tcr").GetString()!));
        Assert.Equal(v.Expected.GetProperty("responseZip").GetString(), OfflineNames.ResponseZip(at, i.GetProperty("tcr").GetString()!));
        Assert.Equal(OfflineNames.MaxZipBytes, v.Expected.GetProperty("maxZipBytes").GetInt64());
    }
}
