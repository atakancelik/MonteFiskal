# .NET: existing point of sale

> Targets MonteFiskal 0.1.0. When to use: a .NET point-of-sale application that already prints receipts.

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
Map my receipt model <type> to InvoiceDraft (lines, payments, buyer when the buyer has a tax
number). Register the day's INITIAL cash deposit before the first cash invoice (BR-D5); the library
does this automatically unless AutoInitialDeposit is off, so tell me which you chose. Add the IKOF,
the JIKR (or the note that it is missing), the operator code, the software code and the QR code of
at least 21 mm to my receipt template, using MonteFiskal.Rendering (QrCode, QrImages, Receipt,
EscPosReceipt or HtmlReceipt).
```

## Verification the assistant must show

Fake-service tests for a cash sale (`BANKNOTE`), a consumer card sale (`CARD`, a cash invoice per BR-K3) and a
full refund (`IssueCorrectiveAsync`), each asserting `InvoiceState.Fiscalized`, plus a rendered receipt that
contains the IKOF and the QR code.
