# Diagnose an error

> Targets MonteFiskal 0.1.0. When to use: an error code or fault string appeared.

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
Given code <code>, the fault string <text> and the redacted request, find the row in
docs/ai/troubleshooting.md, say whether the same request may be resent unchanged, and tell me what
to change. Redact the PIB, names, certificate and signature values before showing anything. A code
that is not in the table is treated as not retryable; draft a spec-change report.
```

## Verification the assistant must show

The cited table row and the rule ID behind it.
