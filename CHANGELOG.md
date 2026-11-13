# Changelog

Every release states whether fiscal behaviour changed. When it says **yes**, each installation registers the new
software version on the SEP and enters the new software code (BR-R7); see [upgrade](docs/ai/prompts/upgrade.md).
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow semantic versioning.

## [Unreleased]

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

[Unreleased]: https://github.com/atakancelik/MonteFiskal/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/atakancelik/MonteFiskal/releases/tag/v0.1.0
