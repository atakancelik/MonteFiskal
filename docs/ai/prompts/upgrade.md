# Upgrade MonteFiskal

> Targets MonteFiskal 0.1.0. When to use: a new MonteFiskal release.

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
Read the release notes from <old version> to <new version>. If any says
"Fiscal behaviour changed: yes", tell me to register the new software version on the SEP and where
to enter the new software code (BR-R7): FiscalProfile.Software in .NET and PHP, the "software"
setting in the plugin. Apply the API changes listed in CHANGELOG.md.
```

## Verification the assistant must show

The test suite is green, and the settings show the new software code when the release changed fiscal behaviour.
