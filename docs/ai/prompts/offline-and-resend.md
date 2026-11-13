# Offline and resend

> Targets MonteFiskal 0.1.0. When to use: reliability work: the service is slow or down.

Paste the common frame, then the task. Fill in the angle brackets yourself.

## Common frame

```text
You are helping me integrate MonteFiskal 0.1.0 (Montenegro EFI fiscalization) into my project.

Read first: https://raw.githubusercontent.com/atakancelik/MonteFiskal/main/llms.txt and the briefing it links to. If you cannot fetch URLs, tell me and I
will paste docs/ai/context.md.

Rules for this task:
- Ask me the intake questions before writing code. Ask only what you cannot find in my project.
  Never ask me to paste a certificate, a key or a password; ask where the application will read
  them from.
- Do not invent codes, tax numbers or tax treatment. If a rule is not in the MonteFiskal rule book
  or is marked not officially settled, say so and give me the documented default.
- Use the test environment and the fake service only. Do not call production.
- Use the library for IKOF, signing, numbering and XML. Do not reimplement them.
- Finish with the verification listed below and show me its output.
```

## Task

```text
Persist every issued invoice before sending (a durable store, not the in-memory one). When
sending fails, hand the invoice over with the IKOF and without JIKR (BR-J2) and resend with the
subsequent-delivery flag until the JIKR arrives (BR-O7): same body, same IKOF, new header. Alert a
person before the 48-hour deadline (BR-O12). Never renumber. For a long outage, use the offline
export and import (BR-O11).
```

## Verification the assistant must show

A test where the endpoint is down, the invoice is issued and printable, the endpoint comes back and the same IKOF is
delivered. The program below is the reference.

```csharp
// Verification: service down, invoice issued and printable; service back, the same IKOF is delivered (BR-J2, BR-O7).
using MonteFiskal;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Security;
using MonteFiskal.Testing;

var profile = new FiscalProfile
{
    IssuerTin = "12345678", IssuerName = "MonteFiskal Test Only DOO", IssuerInVat = true,
    BusinessUnit = "bb123bb123", Tcr = "cc123cc123", Software = "ss123ss123", Operator = "oo123oo123",
};
var clock = new ManualClock(DateTimeOffset.UtcNow);
var fake = new FakeEfiEndpoint(clock);
var client = new FiscalClient(profile, new StaticCertificateProvider(TestCertificateFactory.Create(profile.IssuerTin)), fake, new InMemoryOutboxStore(),
    new MonteFiskalOptions { AutoInitialDeposit = false }, clock);
fake.RequireInitialDeposit = false;
var issued = await client.IssueInvoiceAsync(new InvoiceDraft
{
    Lines = [new InvoiceLine { Name = "Item", Unit = "kom", Quantity = 1, UnitPrice = 12.10m, VatRate = 21, PriceIncludesVat = true }],
    Payments = [new Payment { Method = PaymentMethod.Account }],
});
fake.FailNextWithHttp(503);
var first = await client.SendAsync(issued.Id);              // not fiscalized; print the invoice with IKOF only
clock.Now += TimeSpan.FromMinutes(10);                       // the resend job runs later
var resend = await client.ResendPendingAsync(new ResendOptions());
var delivered = fake.Exchanges[^1].Body.Contains(issued.Iic, StringComparison.Ordinal)
    && fake.Exchanges[^1].Body.Contains("SubseqDelivType", StringComparison.Ordinal);
Console.WriteLine($"first={first.State} resent={resend.Fiscalized} sameIkof={delivered}");
return first.State != InvoiceState.Fiscalized && resend.Fiscalized == 1 && delivered ? 0 : 1;

sealed class ManualClock(DateTimeOffset now) : TimeProvider
{
    public DateTimeOffset Now { get; set; } = now;
    public override DateTimeOffset GetUtcNow() => Now;
}
```
