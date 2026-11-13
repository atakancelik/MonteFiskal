# Report a service change

> Targets MonteFiskal 0.1.0. When to use: the service started rejecting messages that used to be accepted.

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
Collect the evidence: the schema digest from schemas/fs-v1/2026-11/SHA256SUMS against the live WSDL and
XSD, the error code and fault string, the date and time, test or production, and the MonteFiskal
version. Redact the PIB, names, certificate and signature values. Draft an issue with the
"Specification change" issue template.
```

## Verification the assistant must show

The draft issue, with no taxpayer data in it.
