# Changelog

Every release states whether fiscal behaviour changed. When it says **yes**, each installation registers the new
software version on the SEP and enters the new software code (BR-R7); see [upgrade](docs/ai/prompts/upgrade.md).
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow semantic versioning.

## [Unreleased]

## [0.2.0] - 2026-11-25

Fiscal behaviour changed: no
Schema revision: fs-v1 2026-11
Preview: not yet verified against efitest; see docs/STATUS.md.

No message sent to the Tax Administration changed, so installations keep their registered software code (BR-R7).

### Added

- Request and response pairs (BR-X7): `InvoicePairs` in .NET and PHP writes, for each fiscalized invoice, the signed
  request and the response of the exchange that received the JIKR (the response's `RequestUUID` equals the request
  header's `UUID`), byte for byte as archived, as `<yyyyMMddHHmmss>_<TCR>_<IIC>_request.xml` and `_response.xml`.
  WooCommerce: `wp montefiskal export-pairs <dir> --from=YYYY-MM-DD --to=YYYY-MM-DD`. Suggested by the priva-fisc
  project (issue #1).
- .NET: `FiscalClient.SignForExportAsync` and `OfflineExchange.ExportAsync`.
- Shared test vectors `test-vectors/pair/` (six cases) run by both languages.

### Changed

- The release workflow publishes the release itself (pre-release for 0.x). Besides a pushed tag, it also runs when a
  version bump reaches `main` (the plugin header names a version without a tag) and when run by hand from `main`; in
  both cases it creates the tag.

### Fixed

- The offline exchange did not keep everything BR-X2 asks for: the request signed for the export and the response
  loaded from a `_response.zip` were not archived. Both are now archived; the response exactly as received.

## [0.1.0] - 2026-11-13

Fiscal behaviour changed: yes (first release)
Schema revision: fs-v1 2026-11
Preview: not yet verified against efitest; see docs/STATUS.md.

### Added

- .NET 10 library: invoices of every kind in the schema, corrective and error-corrective invoices, TCR registration,
  cash deposits with the automatic daily `INITIAL`, IKOF, verification URL, XML-DSig signing and response signature
  check, durable outbox with gap-free numbering, resend with the subsequent-delivery flag, archive, offline export and
  import, QR code, A4 and ESC/POS receipts, inspector reports, dependency injection and a background resend service.
- PHP 8.5 core with the same API and behaviour, verified against the same shared test vectors, including a PDO outbox
  for MySQL, MariaDB, PostgreSQL and SQLite.
- WooCommerce plugin: invoice at payment or advance flow, refunds as corrective invoices, payment gateway mapping,
  buyer tax number at checkout, fiscal data in e-mails and a printable invoice, Action Scheduler queue, deadline
  warnings, reports, privacy tools, WP-CLI commands, Turkish translation.
- In-process fake EFI service and run-time test certificates for both languages; opt-in efitest scenarios.
- Assistant kit: `AGENTS.md`, `CLAUDE.md`, Cursor and Copilot rules, `llms.txt`, briefing, intake questions, prompts.

[Unreleased]: https://github.com/atakancelik/MonteFiskal/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/atakancelik/MonteFiskal/releases/tag/v0.2.0
[0.1.0]: https://github.com/atakancelik/MonteFiskal/releases/tag/v0.1.0
