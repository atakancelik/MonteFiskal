using System.Net.Http.Headers;
using System.Text;
using MonteFiskal.Model;

namespace MonteFiskal.Transport;

/// <summary>The three operations of the service (WSDL, BR-T4).</summary>
public enum FiscalOperation
{
    RegisterInvoice,
    RegisterTcr,
    RegisterCashDeposit,
}

/// <summary>Endpoints and SOAP actions (BR-T1, BR-T4).</summary>
public static class FiscalEndpoints
{
    public static readonly Uri Test = new("https://efitest.tax.gov.me/fs-v1");
    public static readonly Uri Production = new("https://efi.tax.gov.me/fs-v1");

    public static Uri For(FiscalEnvironment environment) => environment == FiscalEnvironment.Production ? Production : Test;

    public static string SoapAction(FiscalOperation op) => op switch
    {
        FiscalOperation.RegisterInvoice => "https://efi.tax.gov.me/fs/RegisterInvoice",
        FiscalOperation.RegisterTcr => "https://efi.tax.gov.me/fs/RegisterTCR",
        _ => "https://efi.tax.gov.me/fs/RegisterCashDeposit",
    };

    /// <summary>Request element of an operation.</summary>
    public static string RequestElement(FiscalOperation op) => op switch
    {
        FiscalOperation.RegisterInvoice => "RegisterInvoiceRequest",
        FiscalOperation.RegisterTcr => "RegisterTCRRequest",
        _ => "RegisterCashDepositRequest",
    };
}

/// <summary>SOAP 1.1 envelope with an empty header; the signed request is the only child of the body (BR-T3).</summary>
public static class SoapEnvelope
{
    public static string Wrap(string signedRequest) =>
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?><soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\"><soap:Header></soap:Header><soap:Body>" +
        signedRequest + "</soap:Body></soap:Envelope>";
}

/// <summary>Raw HTTP answer.</summary>
public sealed record TransportResponse(int StatusCode, string Body);

/// <summary>Sends an envelope and returns the raw answer. Network failures throw <see cref="FiscalTransportException"/>.</summary>
public interface IFiscalTransport
{
    Task<TransportResponse> SendAsync(FiscalOperation operation, string envelope, CancellationToken cancellationToken);
}

/// <summary>The service was not reached (DNS, TLS, timeout, connection reset).</summary>
public sealed class FiscalTransportException : Exception
{
    public FiscalTransportException(string message, Exception inner) : base(message, inner) { }
    public FiscalTransportException(string message) : base(message) { }
    public FiscalTransportException() { }
}

/// <summary>HTTP POST of SOAP 1.1 over one-way TLS (BR-T2).</summary>
public sealed class HttpFiscalTransport : IFiscalTransport
{
    private readonly HttpClient _http;
    private readonly Uri _endpoint;
    private readonly MonteFiskalOptions _options;

    public HttpFiscalTransport(HttpClient http, Uri endpoint, MonteFiskalOptions? options = null)
    {
        _http = http ?? throw new ArgumentNullException(nameof(http));
        _endpoint = endpoint ?? throw new ArgumentNullException(nameof(endpoint));
        _options = options ?? new MonteFiskalOptions();
    }

    public async Task<TransportResponse> SendAsync(FiscalOperation operation, string envelope, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, _endpoint)
        {
            Content = new StringContent(envelope, new UTF8Encoding(false), "text/xml"),
        };
        request.Content.Headers.ContentType = new MediaTypeHeaderValue("text/xml") { CharSet = "utf-8" };
        if (_options.SendSoapAction)
        {
            // Q-22: SOAP 1.1 action from the WSDL, quoted.
            request.Headers.TryAddWithoutValidation("SOAPAction", "\"" + FiscalEndpoints.SoapAction(operation) + "\"");
        }
        using var cts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        cts.CancelAfter(_options.SendTimeout);
        try
        {
            using var response = await _http.SendAsync(request, cts.Token).ConfigureAwait(false);
            var body = await response.Content.ReadAsStringAsync(cts.Token).ConfigureAwait(false);
            return new TransportResponse((int)response.StatusCode, body);
        }
        catch (HttpRequestException ex)
        {
            throw new FiscalTransportException("The fiscal service could not be reached: " + ex.Message, ex);
        }
        catch (OperationCanceledException ex) when (!cancellationToken.IsCancellationRequested)
        {
            throw new FiscalTransportException("The fiscal service did not answer within " + _options.SendTimeout + ".", ex);
        }
    }
}
