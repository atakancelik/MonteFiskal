# Project status

> State on 2026-11-13, version 0.1.0. What is done, what was skipped and why, what only the test service can settle,
> and what the first efitest session must try. Updated with every release.

## Done

| Area | State | Evidence |
|---|---|---|
| .NET library (7 projects) | Complete for the scope of `docs/analysis/` | 127 unit, vector and fake-service tests pass; distributed as source from GitHub (no NuGet or Packagist) |
| PHP core | Same API and behaviour as .NET, plus `PdoOutboxStore` | 129 tests (671 assertions) pass; PSR-12 clean; PHPStan level 6 clean |
| Parity | Both languages run the same 104 vectors with identical results | `tools/vectors/parity.py`; cross-signature: identical canonical bytes and signatures for 24 items |
| WooCommerce plugin | Complete: single and advance flow, refunds, deposits, queue, reports, privacy, WP-CLI, block checkout, HPOS | 21 integration tests (113 assertions) pass on WordPress 7.1.4 and WooCommerce 11.3.0, with HPOS on and off; release zip installs and activates |
| Translations | English source, Turkish complete | `tools/i18n/po.py check` |
| CI | 11 workflows, actions pinned by commit SHA, read-only token by default | see `.github/workflows/` |
| Assistant kit | All files of `ai-assistant-kit.md` §3; briefing and error table generated; every code block compiled, fake-service blocks executed | `tools/ai-kit/` (generate, lint, run_blocks, 15 self-tests) |
| Brand | Logo, wordmark, package icon, plugin icons and banners, social preview | `assets/` |

## Not run in this session

| What | Why | Next step |
|---|---|---|
| efitest integration suite (15 scenarios in `tests/dotnet/MonteFiskal.IntegrationTests`) | Needs the taxpayer's own qualified certificate and SEP codes; none exist in this repository by design | Maintainer sets the `efitest` environment secrets and runs the `efitest` workflow (see "First efitest session") |
| PostgreSQL for `PdoOutboxStore` | No PostgreSQL server in the build environment | SQLite (tests) and MariaDB 11 (manual run) pass; add a PostgreSQL service to CI when a user needs it |
| PHPStan on the plugin | WordPress and WooCommerce stubs are not part of the toolchain yet | The plugin is covered by PSR-12 and its integration tests; add `szepeviktor/phpstan-wordpress` later |
| Manual dry run of each prompt with an assistant (`ai-assistant-kit.md` §9 item 5) | A manual review by design, not a CI job | Before the first public release note |

## Missing or open rules ("eksik kural")

No fiscal behaviour was added without a rule. Where the rule book stops, the code stops:

- **Rebate flag `RR`** (BR-A5): the rule book says a rebate reduces the base; whether `RR="false"` (rebate not reducing
  the base) is allowed is not documented. Only `true` is produced.
- **Amount-only refunds in WooCommerce**: a refund without line items cannot be mapped to corrective lines (BR-C5 needs
  the affected items). The plugin records an order note and leaves the corrective to the shop manager.
- **Partial refund semantics** beyond the examples annex (Q-02): implemented as BR-C5 describes (refunded lines with
  negative quantities); a reduction spread over several invoices is a credit note (BR-K18) issued by hand.
- **WooCommerce fees and discounts**: fees are sent as items; coupon discounts are folded into the unit price. The rule
  book has no rule that requires either a separate rebate or a separate fee element for web orders.
- **Digital goods** (Q-28): e-mail block plus a printable invoice; the accountant decides.

## UNVERIFIED rules (warnings, not errors)

BR-T4, BR-T8, BR-I3, BR-J3, BR-Q6, BR-N4, BR-K4, BR-K10, BR-V5, BR-A5, BR-A6, BR-A8, BR-P3. Each is a setting with a
documented default; the matching vectors carry status `unverified` (10 of 104).

Settings that only the test service can settle, with the default shipped:

| Question | Switch (.NET / PHP / plugin) | Default |
|---|---|---|
| Q-06 URL encoding | `VerificationUrlEncoding` / `verification_url_encoding` | percent-encoded |
| Q-07 non-ASCII text | `Text` / `text_policy` | unchanged, with a warning |
| Q-09 same IKOF twice | `DuplicateIic` / `duplicate_iic` | reject locally, resend only with the subsequent-delivery flag |
| Q-12 response signature | `ResponseSignature`, `TrustedResponseCertificates` / `response_signature` | verify integrity |
| Q-14 item decimals | `ItemDecimals` / `item_decimals` | 4 |
| Q-17 INITIAL already registered | `TreatInitialAlreadyRegisteredAsSuccess` / `initial_already_registered_is_success` | success |
| Q-22 SOAPAction | `SendSoapAction` / `send_soap_action` | send the WSDL value |
| Q-23 date format | `DateTimeFormat` / `date_time_format` | local time with offset |

## First efitest session

Run in this order with a fresh test TCR; record the result of each scenario in the matching open question.

1. S01 `INITIAL` 0.00, then a consumer card invoice (BR-D5, BR-K3).
2. S02 Non-cash invoice paid by bank transfer.
3. S03 Full corrective of a registered invoice (BR-C2, BR-C3); then a partial corrective (BR-C5).
4. S06 Gross lines whose net parts round apart (Q-14).
5. S07 Non-ASCII names sent unchanged (Q-07).
6. S10 The same IKOF sent twice (Q-09).
7. S11 Response signature and its certificate chain (Q-12).
8. S12 `IssueDateTime` in UTC with `Z` (Q-23); S13 without `SOAPAction` (Q-22).
9. S14 Both verification URL forms of one invoice, opened by hand (Q-06).
10. S15 `WITHDRAW`; a second `INITIAL` on the same day (Q-17).
11. The WooCommerce plugin on a staging shop: card order, bank transfer order, full and partial refund, advance flow.

## Decisions applied

Q-24 one invoice at payment by default, advance flow (BR-K9) as a second mode; Q-29 `BUSINESSCARD` only with the admin
switch, a buyer tax number and a commercial card reported by the gateway; BR-D5 automatic `INITIAL` 0.00; BR-P4/Q-28
e-mail block and printable invoice with a QR code of at least 21 mm; Q-15 cash limit as a setting (EUR 10,000);
Q-26/BR-R7 per-installation software codes and a "Fiscal behaviour changed" line in every release note.

## Notes

- Target framework is .NET 10 (LTS). .NET 11 is due in November 2026; add it as a second target once its SDK is
  generally available on CI runners.
- The plugin's Montenegrin translation is not written yet; the source is English and a complete Turkish translation
  ships. Contributions welcome through the `.pot` file.
- Assistant file formats were re-checked against the vendors' documentation on 2026-11-13. Differences from the
  analysis: `CLAUDE.md` should stay under 200 lines; a skill's `name` is optional and its description is cut in the
  listing after 1,536 characters together with other skills; Cursor ignores plain `.md` rules and reads nested
  `AGENTS.md`; GitHub Copilot also reads a root `CLAUDE.md`, so it sees the same `@AGENTS.md` pointer; the
  `## Optional` section of `llms.txt` is a convention, not part of the format.
- Official schema files are kept byte-for-byte with `PROVENANCE.md`; the specification PDFs are not in the repository.
