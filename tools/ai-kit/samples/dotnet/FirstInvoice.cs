// Minimal correct path in .NET: configure, issue, send, handle the result, resend pending.
// Runs against the in-process fake service; for efitest replace the transport and the certificate provider.
using MonteFiskal;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Security;
using MonteFiskal.Testing;

var profile = new FiscalProfile
{
    Environment = FiscalEnvironment.Test,
    IssuerTin = "12345678",              // <your TIN>; this value belongs to the fake service only
    IssuerName = "MonteFiskal Test Only DOO",
    IssuerInVat = true,
    BusinessUnit = "bb123bb123",         // <your business unit code>
    Tcr = "cc123cc123",                  // <your TCR code>
    Software = "ss123ss123",             // <your software code>
    Operator = "oo123oo123",             // <your operator code>
};
// Production: Pkcs12CertificateProvider.FromEnvironment("<path outside the repository>.p12", "MONTEFISKAL_CERT_PASSWORD")
var certificates = new StaticCertificateProvider(TestCertificateFactory.Create(profile.IssuerTin));
var store = new InMemoryOutboxStore();   // production: MonteFiskal.Storage.EntityFrameworkCore
var client = new FiscalClient(profile, certificates, new FakeEfiEndpoint(), store);

var issued = await client.IssueInvoiceAsync(new InvoiceDraft
{
    Lines = [new InvoiceLine { Name = "Item", Unit = "kom", Quantity = 2, UnitPrice = 12.10m, VatRate = 21, PriceIncludesVat = true }],
    Payments = [new Payment { Method = PaymentMethod.Card }],   // consumer card: a CASH invoice (BR-K3)
});
// The invoice exists now: number and IKOF are final, even if sending fails (BR-J2).
var sent = await client.SendAsync(issued.Id);
if (sent.State != InvoiceState.Fiscalized)
{
    // Never renumber; the resend keeps body and IKOF (BR-O7). Run this periodically.
    await client.ResendPendingAsync(new ResendOptions());
}
Console.WriteLine($"{issued.Number} IKOF {issued.Iic} JIKR {sent.Fic} {issued.VerificationUrl}");
return sent.State == InvoiceState.Fiscalized ? 0 : 1;
