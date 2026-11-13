# .NET: first invoice

> Targets MonteFiskal 0.1.0. When to use: a new .NET integration.

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
Add the packages MonteFiskal and MonteFiskal.Extensions.DependencyInjection (and
MonteFiskal.Storage.EntityFrameworkCore for a durable store). Bind the configuration section
"MonteFiskal" with services.AddMonteFiskal(configuration); the certificate path comes from
configuration and the password from an environment variable or secret store. Issue and send one
invoice built from my order type <type>; store the invoice number, IKOF, JIKR and verification URL
on my order; leave the background resend service on.
```

## Verification the assistant must show

A test with `MonteFiskal.Testing`'s fake endpoint that issues an invoice and asserts the state, the number format
and a recomputed IKOF. The program below is the reference; the assistant's test must check the same three things.

```csharp
// Verification: issue against the fake service, check state, number format and a recomputed IKOF (BR-I1, BR-N1).
using System.Text.RegularExpressions;
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
var client = new FiscalClient(profile, new StaticCertificateProvider(TestCertificateFactory.Create(profile.IssuerTin)), new FakeEfiEndpoint(), new InMemoryOutboxStore());
var issued = await client.IssueInvoiceAsync(new InvoiceDraft
{
    Lines = [new InvoiceLine { Name = "Item", Unit = "kom", Quantity = 1, UnitPrice = 12.10m, VatRate = 21, PriceIncludesVat = true }],
    Payments = [new Payment { Method = PaymentMethod.Card }],
});
var sent = await client.SendAsync(issued.Id);
var check = client.RecomputeIkof(new IkofParameters(profile.IssuerTin, issued.IssueDateTime, issued.Ordinal.ToString(System.Globalization.CultureInfo.InvariantCulture),
    profile.BusinessUnit, profile.Tcr, profile.Software, issued.Totals.TotPrice));
var ok = sent.State == InvoiceState.Fiscalized
    && Regex.IsMatch(issued.Number, "^[a-z]{2}[0-9]{3}[a-z]{2}[0-9]{3}/[0-9]+/[0-9]{4}/[a-z]{2}[0-9]{3}[a-z]{2}[0-9]{3}$")
    && check.Iic == issued.Iic;
Console.WriteLine($"state={sent.State} number={issued.Number} ikofMatches={check.Iic == issued.Iic}");
return ok ? 0 : 1;
```
