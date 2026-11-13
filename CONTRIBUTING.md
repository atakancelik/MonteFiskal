# Contributing to MonteFiskal

Thank you for helping. MonteFiskal produces legally relevant documents, so the bar for evidence is higher than usual.

## Ground rules

1. **Evidence rule.** A fiscal behaviour is implemented only when [`docs/analysis/business-rules.md`](docs/analysis/business-rules.md)
   has a rule for it. Quote the rule ID in the code comment and in the test name (for example
   `Cash_invoice_waits_for_initial_deposit_BR_D5`). If the rule is missing, open an issue instead of guessing. A rule
   marked UNVERIFIED becomes a setting with a documented default and a warning.
2. **Montenegro only.** Never carry over rules from Croatian, Serbian, Albanian or other fiscalization systems.
3. **Parity.** A behaviour change lands in .NET and PHP in the same pull request, with a shared vector in
   [`test-vectors/`](test-vectors/README.md). Both suites must pass the same vector IDs.
4. **No secrets, no real data.** No certificate, private key, PKCS#12 file, password, real tax number, real SEP code or
   production invoice in code, tests, issues or pull requests. Tests generate keys at run time. CI runs a secret scan.
5. **No production calls** from tests, samples or CI. The efitest suite is opt-in and runs only with maintainer secrets.
6. **Official files stay unmodified.** `schemas/fs-v1/*/official/` is byte-for-byte what the Tax Administration
   publishes; a schema change is a new revision.
7. **Decimal money.** `decimal` in .NET, decimal strings with bcmath in PHP.
8. **Your own code.** Do not copy code from other fiscalization projects, whatever their licence.

## Development

The commands for every language are in [`AGENTS.md`](AGENTS.md). In short:

```sh
dotnet test --project tests/dotnet/MonteFiskal.Tests -c Release
composer install && vendor/bin/phpunit && vendor/bin/phpcs && vendor/bin/phpstan analyse --memory-limit=1G
python3 -I tools/vectors/check_vectors.py --repo .
python3 -I tools/ai-kit/generate.py && python3 -I tools/ai-kit/lint.py
```

The plugin's integration tests need a MySQL or MariaDB server; see `plugins/montefiskal-woocommerce/tests/install-wp.sh`.

## Pull requests

- One topic per pull request; fill in the template, including the rule IDs touched.
- Add a line to `CHANGELOG.md` under "Unreleased" and say whether fiscal behaviour changed.
- Keep `docs/` and `docs/ai/` in step with public API changes; the `ai-kit` job compiles every code block in them.
- By contributing you agree that your work is licensed under the licence of the
  component you change (MIT for the libraries, GPL-2.0-or-later for the plugin).

## Reporting a service change

When the EFI service starts rejecting messages that used to be accepted, open a "Specification change" issue with the
error code, fault string, date and environment. Redact the tax number, names, certificate and signature values.

Please follow the [code of conduct](CODE_OF_CONDUCT.md). Security problems go to [SECURITY.md](SECURITY.md), not to
public issues.
