using System.Globalization;
using System.Xml;
using MonteFiskal.Errors;
using MonteFiskal.Security;

namespace MonteFiskal.Wire;

/// <summary>Outcome of one HTTP exchange with CIS.</summary>
public enum ExchangeOutcome
{
    /// <summary>A response element with its result (FIC, TCRCode or FCDC).</summary>
    Success,

    /// <summary>A SOAP fault with a fiscal error.</summary>
    Fault,

    /// <summary>The service was not reached or answered with something that is not SOAP (proxy page, empty body).</summary>
    Transport,

    /// <summary>The answer looked like SOAP but could not be read. Retryable: the request may or may not have been registered.</summary>
    MalformedResponse,
}

/// <summary>Parsed answer of CIS.</summary>
public sealed record ParsedResponse(
    ExchangeOutcome Outcome,
    bool Retryable,
    string? Element = null,
    string? Result = null,
    Guid? RequestUuid = null,
    Guid? ResponseUuid = null,
    string? SendDateTime = null,
    SignatureCheck? Signature = null,
    FiscalError? Error = null,
    string? Problem = null);

/// <summary>Reads SOAP responses and faults (BR-J1, BR-S6, error-codes.md).</summary>
public static class ResponseParser
{
    private const string SoapNs = "http://schemas.xmlsoap.org/soap/envelope/";

    private static readonly Dictionary<string, string> ResultElement = new(StringComparer.Ordinal)
    {
        ["RegisterInvoiceResponse"] = "FIC",
        ["RegisterTCRResponse"] = "TCRCode",
        ["RegisterCashDepositResponse"] = "FCDC",
    };

    public static ParsedResponse Parse(int httpStatus, string? body, ResponseSignaturePolicy signaturePolicy = ResponseSignaturePolicy.Verify)
    {
        if (string.IsNullOrWhiteSpace(body) || (httpStatus != 200 && httpStatus != 500))
        {
            return new ParsedResponse(ExchangeOutcome.Transport, true, Problem: $"HTTP {httpStatus} without a SOAP answer");
        }
        var trimmed = body.TrimStart();
        if (!trimmed.StartsWith('<') || trimmed.StartsWith("<html", StringComparison.OrdinalIgnoreCase) || trimmed.StartsWith("<!DOCTYPE html", StringComparison.OrdinalIgnoreCase))
        {
            return new ParsedResponse(ExchangeOutcome.Transport, true, Problem: "the answer is not XML");
        }
        var doc = new XmlDocument { PreserveWhitespace = true, XmlResolver = null };
        try
        {
            using var reader = XmlReader.Create(new StringReader(body), new XmlReaderSettings { DtdProcessing = DtdProcessing.Prohibit, XmlResolver = null });
            doc.Load(reader);
        }
        catch (XmlException ex)
        {
            return new ParsedResponse(ExchangeOutcome.MalformedResponse, true, Problem: ex.Message);
        }
        var ns = new XmlNamespaceManager(doc.NameTable);
        ns.AddNamespace("soap", SoapNs);
        ns.AddNamespace("fs", RequestBuilder.Namespace);
        var bodyEl = doc.SelectSingleNode("/soap:Envelope/soap:Body", ns);
        if (bodyEl is null)
        {
            return new ParsedResponse(ExchangeOutcome.MalformedResponse, true, Problem: "no SOAP body");
        }
        if (bodyEl.SelectSingleNode("soap:Fault", ns) is XmlElement fault)
        {
            var faultCode = fault.SelectSingleNode("faultcode")?.InnerText.Trim() ?? "";
            var local = faultCode.Contains(':', StringComparison.Ordinal) ? faultCode[(faultCode.IndexOf(':', StringComparison.Ordinal) + 1)..] : faultCode;
            var faultString = fault.SelectSingleNode("faultstring")?.InnerText ?? "";
            var codeText = fault.SelectSingleNode("detail/code")?.InnerText.Trim();
            int? code = int.TryParse(codeText, NumberStyles.Integer, CultureInfo.InvariantCulture, out var c) ? c : null;
            var req = ParseGuid(fault.SelectSingleNode("detail/requestUUID")?.InnerText);
            var resp = ParseGuid(fault.SelectSingleNode("detail/responseUUID")?.InnerText);
            var error = ErrorCatalog.Classify(code, local, faultString, req, resp);
            return new ParsedResponse(ExchangeOutcome.Fault, error.Retryable, RequestUuid: req, ResponseUuid: resp, Error: error);
        }
        var element = bodyEl.ChildNodes.OfType<XmlElement>().FirstOrDefault();
        if (element is null || element.NamespaceURI != RequestBuilder.Namespace || !ResultElement.TryGetValue(element.LocalName, out var resultName))
        {
            return new ParsedResponse(ExchangeOutcome.MalformedResponse, true, Problem: "unexpected body element");
        }
        var header = element.SelectSingleNode("fs:Header", ns) as XmlElement;
        var result = element.SelectSingleNode("fs:" + resultName, ns)?.InnerText;
        if (string.IsNullOrEmpty(result))
        {
            return new ParsedResponse(ExchangeOutcome.MalformedResponse, true, Problem: resultName + " missing");
        }
        SignatureCheck? sig = null;
        var hasSignature = element.GetElementsByTagName("Signature", XmlSigner.DsigNamespace).Count > 0;
        if (signaturePolicy != ResponseSignaturePolicy.Ignore)
        {
            sig = hasSignature ? XmlSignatureVerifier.Verify(body, "Response") : new SignatureCheck(false, false, null, "no signature");
            if (signaturePolicy == ResponseSignaturePolicy.Require && !sig.Valid)
            {
                return new ParsedResponse(ExchangeOutcome.MalformedResponse, true, element.LocalName, result, Signature: sig, Problem: "response signature: " + sig.Problem);
            }
        }
        return new ParsedResponse(ExchangeOutcome.Success, false, element.LocalName, result,
            ParseGuid(header?.GetAttribute("RequestUUID")), ParseGuid(header?.GetAttribute("UUID")), header?.GetAttribute("SendDateTime"),
            sig ?? new SignatureCheck(hasSignature, false, null, "not checked"));
    }

    private static Guid? ParseGuid(string? s) => Guid.TryParse(s?.Trim(), out var g) ? g : null;
}
