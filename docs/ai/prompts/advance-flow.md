# Advance flow

> Targets MonteFiskal 0.1.0. When to use: pre-orders, deposits, or when the accountant wants the strict reading of Q-24.

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
Implement the advance flow of BR-K9: an advance invoice (InvoiceKind.Advance) at payment; at
delivery a normal invoice paid with PaymentMethod.Advance, the amount and AdvanceIic = the IKOF of
the advance invoice; then a corrective invoice of the advance. Keep the single-invoice mode as the
default and make the advance flow a setting; my accountant decides which applies (Q-24).
```

## Verification the assistant must show

Three documents in the fake service with correct references. The program below is the reference.

```csharp
// Verification: advance invoice, final invoice paid by ADVANCE with AdvIIC, corrective of the advance (BR-K9).
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
InvoiceLine[] lines = [new InvoiceLine { Name = "Pre-order", Unit = "kom", Quantity = 1, UnitPrice = 60.50m, VatRate = 21, PriceIncludesVat = true }];

var advance = await client.IssueInvoiceAsync(new InvoiceDraft { Kind = InvoiceKind.Advance, Lines = lines, Payments = [new Payment { Method = PaymentMethod.Account }] });
await client.SendAsync(advance.Id);
var final = await client.IssueInvoiceAsync(new InvoiceDraft { Lines = lines, Payments = [new Payment { Method = PaymentMethod.Advance, Amount = 60.50m, AdvanceIic = advance.Iic }] });
var correction = await client.IssueCorrectiveAsync(new CorrectionRequest(advance.Id));
var states = new[] { (await client.SendAsync(final.Id)).State, (await client.SendAsync(correction.Id)).State };
Console.WriteLine($"advance={advance.Number} final={final.Number} corrective={correction.Number} states={string.Join(",", states)}");
return states.All(s => s == InvoiceState.Fiscalized) ? 0 : 1;
```
