# WooCommerce: customise output

> Targets MonteFiskal 0.1.0. When to use: a theme or plugin developer changes how fiscal data looks.

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
Override the printable invoice or the e-mail block by copying
plugins/montefiskal-woocommerce/templates/montefiskal/invoice.php or fiscal-block.php into
<my theme>/woocommerce/montefiskal/. Add a mapping for my custom payment gateway <gateway id>
through the plugin's settings or the documented filters (montefiskal_invoice_draft,
montefiskal_item_unit, montefiskal_is_commercial_card). Do not touch numbering, signing or sending.
```

## Verification the assistant must show

The printable invoice still shows the invoice number, IKOF, JIKR (or the "not yet registered" note), the operator
and software codes and a QR code of at least 21 by 21 mm (BR-Q8); the plugin's tests still pass.
