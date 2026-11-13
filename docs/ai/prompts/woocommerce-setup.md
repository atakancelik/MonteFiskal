# WooCommerce: set up the plugin

> Targets MonteFiskal 0.1.0. When to use: the shop owner's developer installs the plugin.

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
Walk through the setup wizard values with me (the intake questions). Install the release zip
and check its .sha256 file. Place the certificate outside the web root and define
MONTEFISKAL_CERT_PATH and MONTEFISKAL_CERT_PASSWORD in wp-config.php (I type the password myself).
Map every active payment gateway (consumer card: CARD, which makes a cash invoice; bank transfer:
ACCOUNT; cash on delivery: BANKNOTE) and every zero-rate tax class to an exemption code. Choose the
invoicing mode: one invoice at payment (default) or the advance flow (BR-K9); my accountant decides
(Q-24). Run "Test connection" in the wizard on the test environment.
```

## Verification the assistant must show

The plugin's status page (WooCommerce > MonteFiskal > Status) shows a valid certificate, a registered TCR and a
successful test invoice **on the test environment**, and this command prints the same facts:

```sh
wp montefiskal status
```
