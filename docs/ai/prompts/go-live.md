# Go-live checklist

> Targets MonteFiskal 0.1.0. When to use: before the first production invoice.

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
Walk the checklist below with me, one item at a time. For each item ask what I checked and how.
Refuse to switch the environment to production until every item is confirmed.
```

## Verification the assistant must show

The filled checklist, each item with what was checked.

1. The intake questions are answered and stored in configuration, with no value copied from an example.
2. A test-environment invoice of each kind the shop will issue was accepted and its verification link opens.
3. The software version is registered on the production SEP and its code is configured (BR-R7).
4. The production TCR is registered and its code configured; nothing else issues on it.
5. The business unit's cash or non-cash registration matches the payment methods; if cash, the daily initial deposit job is on (BR-D5).
6. The certificate is the production one, outside the web root and the repository; expiry date known; an alert is set.
7. Sequence store and fiscal tables are backed up; restore was tried once.
8. Resend job runs; the deadline alert reaches a person.
9. The printed or e-mailed invoice shows every required element (BR-P1 to BR-P4).
10. The seller's accountant confirmed the invoicing mode (single invoice or advance flow) and the VAT mapping.
