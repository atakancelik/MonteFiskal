using System.Text;
using System.Text.RegularExpressions;
using System.Xml;
using System.Xml.Linq;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Transport;
using MonteFiskal.Wire;

namespace MonteFiskal.Archive;

/// <summary>
/// The signed request and the response of the exchange that received the JIKR (BR-X7), as archived. A source is
/// <c>archive:&lt;index&gt;</c> (position in <see cref="IArchiveStore.LoadAsync"/>) or <c>last</c> (the outbox's last request or response).
/// </summary>
public sealed record InvoicePair(string RequestFile, string Request, string RequestSource, string ResponseFile, string Response, string ResponseSource);

/// <summary>An invoice left out of the pair export, with the reason (one of the <see cref="InvoicePairs"/> constants).</summary>
public sealed record PairSkip(Guid Id, string? Iic, string Reason);

/// <summary>Outcome of <see cref="InvoicePairs.ExportAsync"/>: the files written (request then response for each invoice) and the invoices left out.</summary>
public sealed record PairExportResult(IReadOnlyList<string> Written, IReadOnlyList<PairSkip> Skipped)
{
    public int Pairs => Written.Count / 2;
}

/// <summary>
/// Request and response pairs of fiscalized invoices (BR-X7): for each invoice, the signed request and the response of
/// the exchange that received the JIKR, written as <c>&lt;yyyyMMddHHmmss&gt;_&lt;TCR&gt;_&lt;IIC&gt;_request.xml</c> and <c>_response.xml</c>
/// (BR-O11 names). The content is the archived text unchanged (BR-X2). Nothing is sent and nothing stored changes.
/// </summary>
public static partial class InvoicePairs
{
    public const string NotAnInvoice = "not-an-invoice";
    public const string NotFiscalized = "not-fiscalized";
    public const string NoResponse = "no-response";
    public const string NoMatchingRequest = "no-matching-request";

    /// <summary>
    /// Picks the pair of one invoice, or returns the reason it has none. Archived exchanges are searched newest first,
    /// then the outbox's last request and response. The response must carry the invoice's FIC; the request is the one
    /// whose header UUID equals the response's RequestUUID (BR-X7).
    /// </summary>
    public static (InvoicePair? Pair, string? Reason) Select(StoredRequest stored, IReadOnlyList<(string Kind, string Content)> archived)
    {
        ArgumentNullException.ThrowIfNull(stored);
        ArgumentNullException.ThrowIfNull(archived);
        if (stored.Operation != FiscalOperation.RegisterInvoice)
        {
            return (null, NotAnInvoice);
        }
        if (stored.State != InvoiceState.Fiscalized || string.IsNullOrEmpty(stored.Result))
        {
            return (null, NotFiscalized);
        }
        var responses = Candidates(archived, "response", stored.LastResponse);
        var requests = Candidates(archived, "request", stored.LastSignedRequest);
        var foundResponse = false;
        foreach (var (responseSource, response) in responses)
        {
            var requestUuid = AnsweredRequestUuid(response, stored.Result);
            if (requestUuid is null)
            {
                continue;
            }
            foundResponse = true;
            foreach (var (requestSource, request) in requests)
            {
                if (RequestUuid(request) == requestUuid)
                {
                    return (new InvoicePair(
                        OfflineNames.RequestFile(stored.IssueDateTime!, stored.Tcr, stored.Iic!),
                        request,
                        requestSource,
                        OfflineNames.ResponseFile(stored.IssueDateTime!, stored.Tcr, stored.Iic!),
                        response,
                        responseSource), null);
                }
            }
        }
        return (null, foundResponse ? NoMatchingRequest : NoResponse);
    }

    /// <summary>
    /// Writes the pair of every invoice issued in [<paramref name="from"/>, <paramref name="to"/>] to <paramref name="directory"/>.
    /// Existing files of the same name are replaced; their content is the same archived text.
    /// </summary>
    public static async Task<PairExportResult> ExportAsync(IOutboxStore store, IArchiveStore archive, DateTimeOffset from, DateTimeOffset to, string directory, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(store);
        ArgumentNullException.ThrowIfNull(archive);
        ArgumentException.ThrowIfNullOrEmpty(directory);
        Directory.CreateDirectory(directory);
        var written = new List<string>();
        var skipped = new List<PairSkip>();
        foreach (var stored in await store.ListAsync(from, to, cancellationToken).ConfigureAwait(false))
        {
            if (stored.Operation != FiscalOperation.RegisterInvoice)
            {
                continue;
            }
            var (pair, reason) = Select(stored, await archive.LoadAsync(stored.Id, cancellationToken).ConfigureAwait(false));
            if (pair is null)
            {
                skipped.Add(new PairSkip(stored.Id, stored.Iic, reason!));
                continue;
            }
            foreach (var (name, content) in new[] { (pair.RequestFile, pair.Request), (pair.ResponseFile, pair.Response) })
            {
                var path = Path.Combine(directory, name);
                await File.WriteAllTextAsync(path, content, new UTF8Encoding(false), cancellationToken).ConfigureAwait(false);
                written.Add(path);
            }
        }
        return new PairExportResult(written, skipped);
    }

    private static List<(string Source, string Content)> Candidates(IReadOnlyList<(string Kind, string Content)> archived, string kind, string? last)
    {
        var result = new List<(string, string)>();
        for (var i = archived.Count - 1; i >= 0; i--)
        {
            if (archived[i].Kind == kind)
            {
                result.Add(($"archive:{i}", archived[i].Content));
            }
        }
        if (!string.IsNullOrEmpty(last))
        {
            result.Add(("last", last));
        }
        return result;
    }

    /// <summary>RequestUUID of a RegisterInvoiceResponse that carries <paramref name="fic"/>, or null. Accepts the SOAP envelope or the bare element.</summary>
    private static Guid? AnsweredRequestUuid(string response, string fic)
    {
        var parsed = ResponseParser.Parse(200, Envelope(response), ResponseSignaturePolicy.Ignore);
        if (parsed.Outcome != ExchangeOutcome.Success || parsed.Element != "RegisterInvoiceResponse" || parsed.RequestUuid is null)
        {
            return null;
        }
        return string.Equals(parsed.Result, fic, StringComparison.OrdinalIgnoreCase) ? parsed.RequestUuid : null;
    }

    /// <summary>UUID of the header of a RegisterInvoiceRequest, or null.</summary>
    private static Guid? RequestUuid(string request)
    {
        XElement root;
        try
        {
            using var reader = XmlReader.Create(new StringReader(request), new XmlReaderSettings { DtdProcessing = DtdProcessing.Prohibit, XmlResolver = null });
            root = XElement.Load(reader);
        }
        catch (XmlException)
        {
            return null;
        }
        if (root.Name.LocalName == "Envelope")
        {
            root = root.Elements().FirstOrDefault(e => e.Name.LocalName == "Body")?.Elements().FirstOrDefault(e => e.Name.LocalName == "RegisterInvoiceRequest")!;
        }
        if (root is null || root.Name != XName.Get("RegisterInvoiceRequest", RequestBuilder.Namespace))
        {
            return null;
        }
        var uuid = root.Elements().FirstOrDefault(e => e.Name.LocalName == "Header")?.Attribute("UUID")?.Value ?? "";
        return UuidPattern().IsMatch(uuid) ? Guid.Parse(uuid) : null;
    }

    /// <summary>Wraps a bare response element in a SOAP envelope for the parser; the archived text itself is not changed.</summary>
    private static string Envelope(string xml)
    {
        xml = xml.TrimStart();
        if (xml.StartsWith("<?xml", StringComparison.Ordinal))
        {
            xml = xml[(xml.IndexOf("?>", StringComparison.Ordinal) + 2)..].TrimStart();
        }
        return EnvelopePattern().IsMatch(xml) ? xml
            : "<s:Envelope xmlns:s=\"http://schemas.xmlsoap.org/soap/envelope/\"><s:Body>" + xml + "</s:Body></s:Envelope>";
    }

    [GeneratedRegex("^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$")]
    private static partial Regex UuidPattern();

    [GeneratedRegex(@"^<([A-Za-z_][\w.-]*:)?Envelope[\s>]")]
    private static partial Regex EnvelopePattern();
}
