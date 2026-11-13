# MonteFiskal for .NET

Fiscalization of invoices with the Montenegrin EFI service (Tax Administration of Montenegro). It handles the request, the
IKOF and the XML signature, sends the request, retries it, and returns the JIKR. Built for .NET 10.

| Package | What it contains |
|---|---|
| `MonteFiskal` | Client, model, calculation, IKOF, XML signature, validation, transport, outbox, error catalog |
| `MonteFiskal.Extensions.DependencyInjection` | `AddMonteFiskal(...)`, typed HTTP client, background resend |
| `MonteFiskal.Storage.EntityFrameworkCore` | Durable outbox and number sequences |
| `MonteFiskal.Storage.FileSystem` | Archive of every signed request and response, offline export and import |
| `MonteFiskal.Rendering` | QR code, A4 HTML invoice and ESC/POS receipt |
| `MonteFiskal.Reports` | Inspector reports with CSV export |
| `MonteFiskal.Testing` | In-process fake EFI service and throw-away test certificates |

## Quick start

```csharp
using MonteFiskal;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Security;
using MonteFiskal.Transport;

var profile = new FiscalProfile
{
    Environment = FiscalEnvironment.Test,
    IssuerTin = "<your TIN>",
    IssuerName = "<your registered name>",
    IssuerInVat = true,
    BusinessUnit = "<your business unit code>",
    Tcr = "<your TCR code>",
    Software = "<your software code>",
    Operator = "<your operator code>",
};
var certificates = Pkcs12CertificateProvider.FromEnvironment("/secure/path/<your certificate>.p12", "MONTEFISKAL_CERT_PASSWORD");
var client = new FiscalClient(profile, certificates, new HttpFiscalTransport(new HttpClient(), FiscalEndpoints.Test), new InMemoryOutboxStore());

var issued = await client.IssueInvoiceAsync(new InvoiceDraft
{
    Lines = [new InvoiceLine { Name = "Item", Unit = "kom", Quantity = 1, UnitPrice = 12.10m, VatRate = 21, PriceIncludesVat = true }],
    Payments = [new Payment { Method = PaymentMethod.Card }],
});
var sent = await client.SendAsync(issued.Id);   // never throws for a fiscal fault; check sent.State
```

Issuing is local: the invoice gets its number and IKOF even when the service is down. Sending is a separate step that is retried
with the subsequent-delivery flag until the JIKR arrives.

Use a durable store (`MonteFiskal.Storage.EntityFrameworkCore`) in production; the in-memory store is for tests.

## Using an AI assistant?

Point it at https://github.com/atakancelik/MonteFiskal/blob/main/llms.txt, or paste
https://github.com/atakancelik/MonteFiskal/blob/main/docs/ai/context.md when it cannot browse. Prompts with a
verification step: https://github.com/atakancelik/MonteFiskal/blob/main/docs/ai/prompts/README.md. Never give an assistant
your certificate or password; it must ask for your codes instead of inventing them.

## Documentation

* Repository and full guide: https://github.com/atakancelik/MonteFiskal
* API reference: `docs/api/dotnet.md`
* Business rules with sources: `docs/analysis/business-rules.md`

Preview release: not yet verified against the Tax Administration's test service (efitest).

The software does not replace the taxpayer's legal responsibility. Test on efitest with your own certificate before production.
MIT licence.
