# .NET API reference

> MonteFiskal 0.1.0, .NET 10. Namespaces start with `MonteFiskal`. Every public member has XML documentation in the
> packages; this page is the map. Rule IDs point to the [rule book](../analysis/business-rules.md).

## Packages

| Package | Main types |
|---|---|
| `MonteFiskal` | `FiscalClient`, `IFiscalClient`, `FiscalProfile`, `MonteFiskalOptions`, model (`MonteFiskal.Model`), `Pkcs12CertificateProvider`, `HttpFiscalTransport`, `FiscalEndpoints`, `InMemoryOutboxStore`, `ErrorCatalog` |
| `MonteFiskal.Extensions.DependencyInjection` | `AddMonteFiskal(...)`, `MonteFiskalSettings`, `ResendBackgroundService` |
| `MonteFiskal.Storage.EntityFrameworkCore` | `EfOutboxStore`, `MonteFiskalDbContext`, `MonteFiskalModel.Configure(ModelBuilder)` |
| `MonteFiskal.Storage.FileSystem` | `FileArchiveStore`, `OfflineExchange` |
| `MonteFiskal.Rendering` | `QrCode`, `QrImages`, `Receipt`, `HtmlReceipt`, `EscPosReceipt` |
| `MonteFiskal.Reports` | `InspectorReports`, `ReportInvoice`, `TurnoverReport` |
| `MonteFiskal.Testing` | `FakeEfiEndpoint`, `TestCertificateFactory`, `VectorLoader` |

## Configuration

`FiscalProfile` holds the taxpayer's identity; every value comes from the company registration or the SEP.

| Property | Required | Meaning |
|---|---|---|
| `Environment` | | `Test` (default) or `Production` (BR-T1) |
| `IssuerTin`, `IssuerName` | yes | PIB or JMB and registered name; the TIN must match the certificate (BR-S5) |
| `IssuerAddress`, `IssuerTown`, `IssuerCountry` | | Seller address; country defaults to `MNE` |
| `IssuerInVat` | | VAT registration (BR-V1) |
| `BusinessUnit` | yes | Business unit code from the SEP |
| `Tcr` | after registration | TCR code (BR-R5) |
| `Software`, `Maintainer` | software yes | Codes of the registered software and maintainer (BR-R7) |
| `Operator` | yes | Operator code (BR-K16) |
| `TimeZone` | | Default `Europe/Podgorica`; numbering year and dates use local time (BR-N4) |

`MonteFiskalOptions` holds one switch per behaviour that only the test service can settle, with the documented default:

| Option | Default | Question |
|---|---|---|
| `VerificationUrlEncoding` | `Percent` | Q-06 |
| `Text` | `Unchanged` (or `Transliterate`) | Q-07 |
| `DuplicateIic` | `Reject` | Q-09 |
| `ResponseSignature`, `TrustedResponseCertificates` | `Verify` | Q-12 |
| `ItemDecimals` | `4` | Q-14 |
| `TreatInitialAlreadyRegisteredAsSuccess` | `true` | Q-17 |
| `SendSoapAction` | `true` | Q-22 |
| `DateTimeFormat` | `LocalOffset` | Q-23 |
| `SequenceScope` | `TcrYear` | BR-N5 |
| `CashLimit` | `10000` | Q-15: `BANKNOTE` payments of this amount or more are refused |
| `AutoInitialDeposit` | `true` | BR-D5 |
| `SendTimeout`, `DeliveryDeadline` | 15 s, 48 h | BR-O12 |
| `ServiceEndpointOverride`, `VerificationBaseOverride` | none | |

## Dependency injection

```csharp
using Microsoft.Extensions.DependencyInjection;
using MonteFiskal;
using MonteFiskal.Extensions.DependencyInjection;

var services = new ServiceCollection();
services.AddLogging();
services.AddMonteFiskal(s =>
{
    s.Profile = new FiscalProfile
    {
        IssuerTin = "<your TIN>", IssuerName = "<your registered name>", IssuerInVat = true,
        BusinessUnit = "<your business unit code>", Tcr = "<your TCR code>",
        Software = "<your software code>", Maintainer = "<your maintainer code>", Operator = "<your operator code>",
    };
    s.Certificate.Path = "<path outside the web root>.p12";   // password from MONTEFISKAL_CERT_PASSWORD
});
using var provider = services.BuildServiceProvider();
Console.WriteLine(provider.GetService<IFiscalClient>() is null ? "not registered" : "registered");
```

`AddMonteFiskal(IConfiguration)` binds the same settings from a configuration section (see the
[setup guide](../setup-guide.md)). It registers `IFiscalClient`, a named `HttpClient`, the in-memory outbox unless you
registered another `IOutboxStore`, and `ResendBackgroundService` (switch off with `BackgroundResend = false`).

## The client

| Method | Network | Does |
|---|---|---|
| `IssueInvoiceAsync(InvoiceDraft)` | no | Validates, calculates, takes the next ordinal, computes the IKOF and the verification URL, stores the request. Throws `FiscalValidationException` for an invalid draft; nothing is numbered then. |
| `SendAsync(Guid id)` | yes | Signs and sends; returns `SendResult` with `State` and `Fic` (JIKR). Never throws for a fiscal fault (BR-O3). Registers the day's `INITIAL` deposit first when needed (BR-D5). |
| `IssueAndSendAsync(InvoiceDraft)` | yes | Both; `null` when not yet fiscalized. |
| `IssueCorrectiveAsync(CorrectionRequest)` | no | Full corrective (no lines), partial corrective (negative lines, BR-C5) or error-corrective (`CorrectionType.ErrorCorrective`, BR-C4). Sent only after the original has its JIKR (BR-C3). |
| `RegisterTcrAsync(TcrRegistration)`, `DeregisterTcrAsync(...)` | yes | TCR registration (BR-R1 to BR-R4). |
| `RegisterCashDepositAsync(CashDeposit)` | yes | `INITIAL` or `WITHDRAW` (BR-D1). |
| `ResendPendingAsync(ResendOptions)` | yes | Resends open requests oldest first with `SubseqDelivType` set; same body and IKOF (BR-O7). |
| `SignForExport`, `ApplyImportedResponseAsync` | no | Offline exchange (BR-O11). |
| `RecomputeIkof(IkofParameters)`, `BuildVerificationUrl(...)` | no | Check an IKOF or rebuild the link (BR-I6, BR-Q1). |

`InvoiceState`: `Issued` (no attempt yet), `Pending` (retry scheduled), `Overdue` (past the delivery deadline),
`Fiscalized`, `Rejected` (a non-retryable fault; fix the cause and issue a corrective, never a new number).

## Model

All amounts and quantities are `decimal`. `InvoiceLine.UnitPrice` is gross when `PriceIncludesVat` is true.

- `InvoiceDraft`: `Kind` (`Invoice`, `Corrective`, `Summary`, `Periodical`, `Advance`, `CreditNote`), `Lines`, `Payments`,
  `Buyer` (`Party`), optional `TypeOfInv` (otherwise derived from the payment methods, BR-K3), `Currency`, `Fees`,
  `SupplyPeriod`, `IicRefs`, `Note` and the other optional elements of the message catalog.
- `InvoiceLine`: `Name`, `Unit`, `Quantity`, `UnitPrice`, `PriceIncludesVat`, `VatRate` or `Exemption` (BR-V3), `Rebate`, `Code`.
- `Payment`: `Method` (`Banknote`, `Card`, `BusinessCard`, `SingleVoucher`, `Company`, `Order`, `Advance`, `Account`,
  `Factoring`, `Other`, `OtherCash`), `Amount`, `AdvanceIic` for an advance payment (BR-K9).
- `Party`: `IdType`, `IdNum`, `Name`, `Address`, `Town`, `Country` (ISO 3166-1 alpha-3).

## Storage

```csharp
using Microsoft.EntityFrameworkCore;
using MonteFiskal.Storage.EntityFrameworkCore;

// Use your provider here (UseSqlServer, UseNpgsql, UseSqlite, ...). The store opens a short-lived context per call.
var options = new DbContextOptionsBuilder<MonteFiskalDbContext>().Options;
var store = new EfOutboxStore(() => new MonteFiskalDbContext(options));
Console.WriteLine(store.GetType().Name);
```

Create the tables with your migrations; to add them to your own context, call `MonteFiskalModel.Configure(modelBuilder)`.
Ordinals are allocated with optimistic concurrency and retries, so they stay gap-free under load (BR-N5). Records are
never deleted by the library (BR-X1). `FileArchiveStore(root)` keeps every signed request and response as files.

## Rendering and reports

```csharp
using MonteFiskal;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Rendering;
using MonteFiskal.Reports;
using MonteFiskal.Security;
using MonteFiskal.Testing;

var profile = new FiscalProfile
{
    IssuerTin = "12345678", IssuerName = "MonteFiskal Test Only DOO", IssuerInVat = true,
    BusinessUnit = "bb123bb123", Tcr = "cc123cc123", Software = "ss123ss123", Operator = "oo123oo123",
};
var store = new InMemoryOutboxStore();
var client = new FiscalClient(profile, new StaticCertificateProvider(TestCertificateFactory.Create(profile.IssuerTin)), new FakeEfiEndpoint(), store);
var issued = await client.IssueInvoiceAsync(new InvoiceDraft
{
    Lines = [new InvoiceLine { Name = "Item", Unit = "kom", Quantity = 1, UnitPrice = 12.10m, VatRate = 21, PriceIncludesVat = true }],
    Payments = [new Payment { Method = PaymentMethod.Banknote }],
});
await client.SendAsync(issued.Id);

var stored = await store.GetAsync(issued.Id, CancellationToken.None) ?? throw new InvalidOperationException();
var receipt = Receipt.FromStored(stored, testMode: true);
var html = HtmlReceipt.Render(receipt, "en");                 // A4 invoice with a QR code of at least 21 mm (BR-Q8)
var escPos = EscPosReceipt.Render(receipt, 80, "en");          // 80 mm receipt printer
var qr = QrImages.Svg(QrCode.Encode(issued.VerificationUrl), 25);

var (invoices, deposits) = InspectorReports.FromStore(await store.ListAsync(DateTimeOffset.UtcNow.AddDays(-1), DateTimeOffset.UtcNow.AddDays(1), CancellationToken.None), profile.Tcr);
var daily = InspectorReports.Daily(DateOnly.FromDateTime(DateTime.Today), profile.Tcr, profile.BusinessUnit, invoices, deposits);
Console.WriteLine($"html={html.Contains(issued.Iic, StringComparison.Ordinal)} escpos={escPos.Length > 0} qr={qr.StartsWith("<svg", StringComparison.Ordinal)} csv={InspectorReports.ToCsv(daily).Length > 0}");
return html.Contains(issued.Iic, StringComparison.Ordinal) ? 0 : 1;
```

## Errors

A fiscal fault is a `FiscalError` on the result (`Code`, `Class`, `Retryable`, `FaultString`, `Hint`), never an
exception. `ErrorCatalog` knows every documented code; [troubleshooting](../ai/troubleshooting.md) lists them with
what to do. Configuration problems throw `FiscalConfigurationException`; an invalid draft throws
`FiscalValidationException` with the findings and their rule IDs.

## Testing

`FakeEfiEndpoint` implements the service in process: it validates the signature and the schema, enforces the initial
deposit, answers with a signed response, and can fail on demand (`FailNextWith(code)`, `FailNextWithHttp(503)`).
`TestCertificateFactory.Create(tin)` makes a throw-away certificate at run time. `VectorLoader` reads the shared vectors
in `test-vectors/` that the PHP core passes too.
