// MonteFiskal Sandbox: issue, send and print a test invoice.
//
//   dotnet run --project samples/MonteFiskal.Sandbox -- fake      runs against the in-process fake service (default)
//   dotnet run --project samples/MonteFiskal.Sandbox -- efitest   runs against https://efitest.tax.gov.me (needs your own certificate)
//
// efitest needs these environment variables (placeholders, never commit real values):
//   MONTEFISKAL_CERT_PATH        path to your .p12 test certificate
//   MONTEFISKAL_CERT_PASSWORD    its password
//   MONTEFISKAL_TIN              <your TIN>
//   MONTEFISKAL_BU               <your business unit code>
//   MONTEFISKAL_TCR              <your TCR code>
//   MONTEFISKAL_SOFTWARE         <your software code>
//   MONTEFISKAL_OPERATOR         <your operator code>
//   MONTEFISKAL_MAINTAINER       <your maintainer code>
//   MONTEFISKAL_IN_VAT           true or false
using MonteFiskal;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Rendering;
using MonteFiskal.Security;
using MonteFiskal.Testing;
using MonteFiskal.Transport;

var mode = args.Length > 0 ? args[0] : "fake";
string Env(string name) => Environment.GetEnvironmentVariable(name) ?? throw new InvalidOperationException($"Set {name} (see the comment at the top of Program.cs).");

FiscalProfile profile;
ICertificateProvider certificates;
IFiscalTransport transport;
if (mode == "efitest")
{
    profile = new FiscalProfile
    {
        Environment = FiscalEnvironment.Test,
        IssuerTin = Env("MONTEFISKAL_TIN"),
        IssuerName = Environment.GetEnvironmentVariable("MONTEFISKAL_NAME") ?? "Sandbox",
        IssuerInVat = bool.Parse(Env("MONTEFISKAL_IN_VAT")),
        BusinessUnit = Env("MONTEFISKAL_BU"),
        Tcr = Env("MONTEFISKAL_TCR"),
        Software = Env("MONTEFISKAL_SOFTWARE"),
        Operator = Env("MONTEFISKAL_OPERATOR"),
        Maintainer = Environment.GetEnvironmentVariable("MONTEFISKAL_MAINTAINER"),
    };
    certificates = Pkcs12CertificateProvider.FromEnvironment(Env("MONTEFISKAL_CERT_PATH"), "MONTEFISKAL_CERT_PASSWORD");
    transport = new HttpFiscalTransport(new HttpClient(), FiscalEndpoints.Test);
}
else
{
    profile = new FiscalProfile
    {
        IssuerTin = "12345678",
        IssuerName = "MonteFiskal Test Only DOO",
        IssuerAddress = "Test Street 1",
        IssuerTown = "Podgorica",
        IssuerInVat = true,
        BusinessUnit = "bb123bb123",
        Tcr = "cc123cc123",
        Software = "ss123ss123",
        Operator = "oo123oo123",
        Maintainer = "mm123mm123",
    };
    certificates = new StaticCertificateProvider(TestCertificateFactory.Create(profile.IssuerTin));
    transport = new FakeEfiEndpoint();
}

if (profile.Environment != FiscalEnvironment.Test)
{
    Console.Error.WriteLine("The sandbox only talks to the test service.");
    return 2;
}

var store = new InMemoryOutboxStore();
var client = new FiscalClient(profile, certificates, transport, store);
var draft = new InvoiceDraft
{
    Lines =
    [
        new InvoiceLine { Name = "Test item", Unit = "kom", Quantity = 2, UnitPrice = 12.10m, VatRate = profile.IssuerInVat ? 21 : null, PriceIncludesVat = true },
    ],
    Payments = [new Payment { Method = PaymentMethod.Card }],
};

var issued = await client.IssueInvoiceAsync(draft);
Console.WriteLine($"Issued   {issued.Number}  IKOF {issued.Iic}  total {issued.Totals.TotPrice}");
foreach (var w in issued.Warnings)
{
    Console.WriteLine($"Warning  {w}");
}
var sent = await client.SendAsync(issued.Id);
Console.WriteLine(sent.State == InvoiceState.Fiscalized ? $"JIKR     {sent.Fic}" : $"State    {sent.State} {sent.Error} {sent.Problem}");
Console.WriteLine($"Verify   {issued.VerificationUrl}");
var receipt = Receipt.FromStored((await store.GetAsync(issued.Id, CancellationToken.None))!, testMode: true);
var file = Path.Combine(Path.GetTempPath(), "montefiskal-sandbox-invoice.html");
await File.WriteAllTextAsync(file, HtmlReceipt.Render(receipt, "en"));
Console.WriteLine($"Printed  {file}");
return sent.State == InvoiceState.Fiscalized ? 0 : 1;
