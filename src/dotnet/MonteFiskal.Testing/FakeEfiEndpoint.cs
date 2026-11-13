using System.Collections.Concurrent;
using System.Globalization;
using System.Net;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using System.Xml;
using MonteFiskal.Security;
using MonteFiskal.Transport;
using MonteFiskal.Wire;

namespace MonteFiskal.Testing;

/// <summary>A request received by the fake endpoint.</summary>
public sealed record FakeExchange(string SoapAction, string RequestElement, string Body, int StatusCode, string Response);

/// <summary>
/// In-process stand-in for the EFI service (fs-v1). Validates every request against the bundled XSD and its
/// signature, enforces the INITIAL-before-cash rule (code 58), answers with signed responses, and can be told
/// to fail. It never opens a network connection. Use it as the primary handler of an <see cref="HttpClient"/>
/// or as an <see cref="IFiscalTransport"/>.
/// </summary>
public sealed class FakeEfiEndpoint : HttpMessageHandler, IFiscalTransport
{
    private readonly X509Certificate2 _serviceCertificate;
    private readonly ConcurrentQueue<Func<string, (int Status, string Body)?>> _script = new();
    private readonly ConcurrentDictionary<string, string> _initialDeposits = new(StringComparer.Ordinal);
    private readonly ConcurrentDictionary<string, string> _tcrCodes = new(StringComparer.Ordinal);
    private readonly ConcurrentDictionary<string, string> _registeredIics = new(StringComparer.OrdinalIgnoreCase);
    private readonly ConcurrentQueue<FakeExchange> _log = new();
    private readonly TimeProvider _clock;

    public FakeEfiEndpoint(TimeProvider? clock = null, X509Certificate2? serviceCertificate = null)
    {
        _clock = clock ?? TimeProvider.System;
        _serviceCertificate = serviceCertificate ?? TestCertificateFactory.Create("00000000", "MonteFiskal Fake EFI Service");
    }

    /// <summary>Certificate that signs the fake responses.</summary>
    public X509Certificate2 ServiceCertificate => _serviceCertificate;

    /// <summary>Enforce BR-D5 (code 58 for a cash invoice without the day's INITIAL). Default true.</summary>
    public bool RequireInitialDeposit { get; set; } = true;

    /// <summary>Sign responses (BR-S6). Default true.</summary>
    public bool SignResponses { get; set; } = true;

    public IReadOnlyList<FakeExchange> Exchanges => _log.ToArray();

    /// <summary>The next request fails with this fault code.</summary>
    public FakeEfiEndpoint FailNextWith(int code, string faultString = "Simulated fault.", bool server = false)
    {
        _script.Enqueue(req => (500, Fault(code, faultString, server, RequestUuid(req))));
        return this;
    }

    /// <summary>The next request gets an HTTP error page (transport failure).</summary>
    public FakeEfiEndpoint FailNextWithHttp(int status = 503, string body = "<html><body>Service Unavailable</body></html>")
    {
        _script.Enqueue(_ => (status, body));
        return this;
    }

    /// <summary>The next request is answered by <paramref name="responder"/>; return null to fall through.</summary>
    public FakeEfiEndpoint Next(Func<string, (int Status, string Body)?> responder)
    {
        _script.Enqueue(responder);
        return this;
    }

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(request);
        var body = request.Content is null ? "" : await request.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
        var action = request.Headers.TryGetValues("SOAPAction", out var v) ? string.Join(",", v).Trim('"') : "";
        var (status, response) = Handle(action, body);
        return new HttpResponseMessage((HttpStatusCode)status) { Content = new StringContent(response, Encoding.UTF8, "text/xml") };
    }

    Task<TransportResponse> IFiscalTransport.SendAsync(FiscalOperation operation, string envelope, CancellationToken cancellationToken)
    {
        var (status, response) = Handle(FiscalEndpoints.SoapAction(operation), envelope);
        return Task.FromResult(new TransportResponse(status, response));
    }

    private (int, string) Handle(string action, string envelope)
    {
        string element = "";
        try
        {
            if (_script.TryDequeue(out var scripted) && scripted(envelope) is { } s)
            {
                return Record(action, "", envelope, s.Status, s.Body);
            }
            if (Encoding.UTF8.GetByteCount(envelope) > 150 * 1024)
            {
                return Record(action, "", envelope, 500, Fault(1, "Message too large.", false, null));
            }
            var doc = new XmlDocument { PreserveWhitespace = true, XmlResolver = null };
            using (var r = XmlReader.Create(new StringReader(envelope), new XmlReaderSettings { DtdProcessing = DtdProcessing.Prohibit, XmlResolver = null }))
            {
                doc.Load(r);
            }
            var ns = new XmlNamespaceManager(doc.NameTable);
            ns.AddNamespace("soap", "http://schemas.xmlsoap.org/soap/envelope/");
            if (doc.SelectSingleNode("/soap:Envelope/soap:Body/*", ns) is not XmlElement req)
            {
                return Record(action, "", envelope, 500, Fault(22, "Request element missing.", false, null));
            }
            element = req.LocalName;
            var reqUuid = req.SelectSingleNode("*[local-name()='Header']/@UUID")?.Value;
            var schema = SchemaSet.Validate(req.OuterXml);
            if (schema.Count > 0)
            {
                return Record(action, element, envelope, 500, Fault(11, "Validation of the received XML message failed: " + schema[0], false, reqUuid));
            }
            var sig = XmlSignatureVerifier.Verify(envelope, "Request");
            if (!sig.Present)
            {
                return Record(action, element, envelope, 500, Fault(21, "Signature element missing.", false, reqUuid));
            }
            if (!sig.Valid)
            {
                return Record(action, element, envelope, 500, Fault(29, "Validation failed: " + sig.Problem, false, reqUuid));
            }
            var a = (XmlElement)req.SelectSingleNode("*[2]")!;
            return element switch
            {
                "RegisterInvoiceRequest" => Record(action, element, envelope, Invoice(a, reqUuid!)),
                "RegisterTCRRequest" => Record(action, element, envelope, Tcr(a, reqUuid!)),
                "RegisterCashDepositRequest" => Record(action, element, envelope, Deposit(a, reqUuid!)),
                _ => Record(action, element, envelope, 500, Fault(22, "Unknown request element.", false, reqUuid)),
            };
        }
        catch (XmlException ex)
        {
            return Record(action, element, envelope, 500, Fault(10, "Exception while reading the XML: " + ex.Message, false, null));
        }
    }

    private (int, string) Invoice(XmlElement inv, string reqUuid)
    {
        var iic = inv.GetAttribute("IIC");
        var tcr = inv.GetAttribute("TCRCode");
        var day = inv.GetAttribute("IssueDateTime")[..10];
        if (RequireInitialDeposit && inv.GetAttribute("TypeOfInv") == "CASH" && !_initialDeposits.ContainsKey(tcr + "/" + day))
        {
            return (500, Fault(58, "Cash deposit INITIAL is not registered for the current day.", false, reqUuid));
        }
        var fic = _registeredIics.GetOrAdd(iic, _ => Guid.NewGuid().ToString("D"));
        return (200, Response("RegisterInvoiceResponse", reqUuid, "FIC", fic));
    }

    private (int, string) Tcr(XmlElement tcr, string reqUuid)
    {
        var key = tcr.GetAttribute("IssuerTIN") + "/" + tcr.GetAttribute("BusinUnitCode") + "/" + tcr.GetAttribute("TCRIntID");
        // BR-R3: the same TCRIntID and business unit return the same TCR code.
        var code = _tcrCodes.GetOrAdd(key, _ => "tc" + (100 + _tcrCodes.Count).ToString(CultureInfo.InvariantCulture) + "tc" + (100 + _tcrCodes.Count).ToString(CultureInfo.InvariantCulture));
        return (200, Response("RegisterTCRResponse", reqUuid, "TCRCode", code));
    }

    private (int, string) Deposit(XmlElement dep, string reqUuid)
    {
        var op = dep.GetAttribute("Operation");
        var tcr = dep.GetAttribute("TCRCode");
        var day = dep.GetAttribute("ChangeDateTime")[..10];
        var amount = decimal.Parse(dep.GetAttribute("CashAmt"), CultureInfo.InvariantCulture);
        if (op == "WITHDRAW" && amount == 0m)
        {
            return (500, Fault(51, "Cash amount cannot be zero for WITHDRAW.", false, reqUuid));
        }
        if (op == "INITIAL" && amount < 0m)
        {
            return (500, Fault(50, "Cash amount for INITIAL cannot be negative.", false, reqUuid));
        }
        if (op == "INITIAL")
        {
            _initialDeposits[tcr + "/" + day] = dep.GetAttribute("CashAmt");
        }
        return (200, Response("RegisterCashDepositResponse", reqUuid, "FCDC", Guid.NewGuid().ToString("D")));
    }

    private string Response(string element, string reqUuid, string resultName, string result)
    {
        var now = _clock.GetLocalNow().ToString("yyyy-MM-dd'T'HH:mm:sszzz", CultureInfo.InvariantCulture);
        var xml = new XNode(element, RequestBuilder.Namespace).Attr("Id", "Response").Attr("Version", "1")
            .Add(new XNode("Header").Attr("RequestUUID", reqUuid).Attr("UUID", Guid.NewGuid().ToString("D")).Attr("SendDateTime", now))
            .Add(new XNode(resultName).WithText(result)).ToString();
        if (SignResponses)
        {
            using var key = _serviceCertificate.GetRSAPrivateKey()!;
            xml = XmlSigner.Sign(xml, key, _serviceCertificate, "Response");
        }
        return "<env:Envelope xmlns:env=\"http://schemas.xmlsoap.org/soap/envelope/\"><env:Header/><env:Body>" + xml + "</env:Body></env:Envelope>";
    }

    private static string Fault(int code, string faultString, bool server, string? reqUuid) =>
        "<env:Envelope xmlns:env=\"http://schemas.xmlsoap.org/soap/envelope/\"><env:Header/><env:Body><env:Fault><faultcode>env:" + (server ? "SERVER" : "CLIENT") +
        "</faultcode><faultstring>" + XNode.EscapeText(faultString) + "</faultstring><detail><code>" + code.ToString(CultureInfo.InvariantCulture) + "</code>" +
        (reqUuid is null ? "" : "<requestUUID>" + reqUuid + "</requestUUID>") + "<responseUUID>" + Guid.NewGuid().ToString("D") + "</responseUUID></detail></env:Fault></env:Body></env:Envelope>";

    private static string? RequestUuid(string envelope)
    {
        var i = envelope.IndexOf(" UUID=\"", StringComparison.Ordinal);
        return i < 0 ? null : envelope.Substring(i + 7, 36);
    }

    private (int, string) Record(string action, string element, string body, (int Status, string Body) r) => Record(action, element, body, r.Status, r.Body);

    private (int, string) Record(string action, string element, string body, int status, string response)
    {
        _log.Enqueue(new FakeExchange(action, element, body, status, response));
        return (status, response);
    }
}
