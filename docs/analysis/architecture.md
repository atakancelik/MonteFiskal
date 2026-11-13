# Architecture proposal

A proposal for the coding session, not code. Signatures are sketches at the level of names, parameters and return types. Decisions already taken by the project owner (targets, licences, repository layout, distribution through GitHub only, users bring their own certificate and codes) are treated as fixed. `BR-…` refers to `business-rules.md`, `S-…` to the schema observations in `message-catalog.md`, `Q-…` to `open-questions.md`.

## 1. Principles

1. **Issue first, send second.** Number allocation, IKOF, QR and persistence happen locally and synchronously; the network call is a separate step that may fail without losing the invoice (BR-O1, BR-J2).
2. **What was signed is what is stored and what is resent.** The request body is kept as bytes; resending rebuilds only the header and signature (BR-O7).
3. **The schema is data.** Wire models, limits and enumerations are generated from `message-catalog.json`; hand-written code never repeats a pattern or a length (BR-T7).
4. **Two languages, one behaviour.** The PHP core mirrors the .NET library type for type; `test-vectors/` is the contract.
5. **Unverified rules warn, verified rules block.** Each validation rule carries a severity that follows its evidence label.
6. **No secrets, no defaults that guess.** VAT rates, codes, certificates, time zone and sequence scope are always supplied by the user.

## 2. .NET library (`net10.0`)

### 2.1 Packages

| Package | Content | Dependencies |
|---|---|---|
| `MonteFiskal` | Domain model, calculation, validation, IKOF, XML building and signing, SOAP client, fault model, verification URL, outbox and archive abstractions, in-memory implementations | BCL only (`System.Security.Cryptography.Xml`) |
| `MonteFiskal.Extensions.DependencyInjection` | `AddMonteFiskal(...)`, options binding, `IHttpClientFactory` wiring, OpenTelemetry `ActivitySource` and `Meter` names | Microsoft.Extensions.* |
| `MonteFiskal.Storage.EntityFrameworkCore` | Durable sequence allocator, outbox and archive | EF Core |
| `MonteFiskal.Storage.FileSystem` | Archive and offline export/import on disk | none |
| `MonteFiskal.Rendering` | QR (SVG, PNG), receipt layouts: ESC/POS 58 mm and 80 mm, A4 HTML | a QR encoder (permissive licence) |
| `MonteFiskal.Reports` | The five inspector reports and IKOF re-creation (BR-X3, BR-I6) | none |
| `MonteFiskal.Testing` | Fake EFI endpoint, test certificate factory, vector loader | none |

Source layout follows the decision document: `src/dotnet/MonteFiskal/…`, tests in `tests/dotnet/`, sample in `samples/MonteFiskal.Sandbox/`.

### 2.2 Namespaces inside `MonteFiskal`

| Namespace | Responsibility |
|---|---|
| `MonteFiskal` | Entry points: `IFiscalClient`, options, profiles |
| `MonteFiskal.Model` | Immutable domain records: `Invoice`, `InvoiceLine`, `Party`, `Payment`, `CashDeposit`, `TcrRegistration`, enumerations, value types (`Tin`, `RegistrationCode`, `Iic`, `Money`, `Quantity`, `VatRate`) |
| `MonteFiskal.Calculation` | Line and total calculation, rounding policies (BR-A5 to BR-A7) |
| `MonteFiskal.Validation` | Rule engine, rule catalogue keyed by `BR` IDs, severities |
| `MonteFiskal.Security` | `ICertificateProvider`, IKOF signer, XML-DSig signer and verifier |
| `MonteFiskal.Wire` | Schema-revision adapters, XML writers and readers, SOAP envelope, fault parser |
| `MonteFiskal.Transport` | HTTP transport, endpoints, timeouts |
| `MonteFiskal.Outbox` | Attempt state machine, resend scheduling, deadlines |
| `MonteFiskal.Archive` | Storage contracts for requests and responses, offline export/import |
| `MonteFiskal.Verification` | Verification URL builder |

### 2.3 Public API sketch

Entry point:

```
interface IFiscalClient
    Task<IssuedInvoice>    IssueInvoiceAsync(InvoiceDraft draft, CancellationToken ct)
    Task<SendResult>       SendAsync(IssuedInvoice invoice, CancellationToken ct)
    Task<FiscalizedInvoice> IssueAndSendAsync(InvoiceDraft draft, CancellationToken ct)
    Task<IssuedInvoice>    IssueCorrectiveAsync(CorrectionRequest request, CancellationToken ct)
    Task<TcrRegistrationResult> RegisterTcrAsync(TcrRegistration registration, CancellationToken ct)
    Task<TcrRegistrationResult> DeregisterTcrAsync(string tcrInternalId, DateOnly validTo, CancellationToken ct)
    Task<CashDepositResult> RegisterCashDepositAsync(CashDeposit deposit, CancellationToken ct)
    Task<ResendSummary>    ResendPendingAsync(ResendOptions options, CancellationToken ct)
    IkofCheck              RecomputeIkof(IkofParameters parameters)
    Uri                    BuildVerificationUrl(IkofParameters parameters, Iic iic)
```

Semantics:

| Member | Behaviour |
|---|---|
| `IssueInvoiceAsync` | Validates (blocking rules throw `FiscalValidationException`), calculates totals, allocates the ordinal, computes IKOF, builds and signs the request, stores it with state `Issued`. No network. Returns everything needed to print: number, IKOF, verification URL. |
| `SendAsync` | Sends one stored invoice. Returns `Fiscalized` with JIKR, `Pending` (transport or server-class failure, will be resent), or `Rejected` with a typed error. Never throws for a fiscal fault. |
| `IssueAndSendAsync` | Convenience for online use: issue, then one send attempt within the configured timeout. |
| `IssueCorrectiveAsync` | Builds a full or partial reversal from a stored invoice (BR-C2), checks BR-C3. |
| `ResendPendingAsync` | Processes the outbox oldest first, with a budget so that live issuing is not delayed (BR-O8); sets `SubseqDelivType`; re-signs with the current certificate (BR-I5). |
| `RecomputeIkof` | Pure function for the inspector screen (BR-I6). |

Model sketch (records; all amounts decimal, all codes strongly typed):

```
record InvoiceDraft(
    InvoiceKind Kind,                 // Invoice, Corrective, Summary, Periodical, Advance, CreditNote
    PaymentKind PaymentKind,          // Cash, NonCash
    DateTimeOffset? IssuedAt,         // null = clock
    Party? Buyer,
    IReadOnlyList<InvoiceLine> Lines,
    IReadOnlyList<Payment> Payments,
    SelfIssuingKind? SelfIssuing, bool? ReverseCharge,
    DateOnly? PayDeadline, SupplyPeriod? Supply, CurrencyInfo? Currency,
    IReadOnlyList<Fee> Fees, IReadOnlyList<InvoiceReference> References,
    CorrectionInfo? Correction, string? Note, string? BankAccount, ...)

record InvoiceLine(string Name, string Unit, Quantity Quantity,
    UnitPrice Price,                  // net or gross, flagged
    VatRate? Rate, VatExemption? Exemption,
    string? Code, decimal? RebatePercent, bool? RebateReducesBase, bool? Investment, ...)

record Payment(PaymentMethod Method, Money Amount, string? CompanyCard,
    IReadOnlyList<string> Vouchers, Iic? AdvanceInvoice, string? BankAccount)

record IssuedInvoice(Guid Id, string Number, int Ordinal, Iic Iic, string IicSignature,
    Uri VerificationUrl, DateTimeOffset IssuedAt, InvoiceState State, InvoiceTotals Totals, ...)

record FiscalizedInvoice(IssuedInvoice Invoice, Guid Fic, DateTimeOffset RegisteredAt)

record SendResult(InvoiceState State, Guid? Fic, FiscalError? Error)

record FiscalError(int Code, FiscalErrorClass Class, bool Retryable,
    string FaultString, Guid? RequestUuid, Guid? ResponseUuid)
```

Configuration:

```
record FiscalProfile(
    FiscalEnvironment Environment,     // Test, Production
    Tin IssuerTin, string IssuerName, Address IssuerAddress,
    bool IssuerInVat,
    RegistrationCode BusinessUnit, RegistrationCode Tcr,
    RegistrationCode Software, RegistrationCode Operator,
    RegistrationCode? Maintainer,
    TimeZoneInfo TimeZone,             // Europe/Podgorica unless set
    SequenceScope SequenceScope)       // explicit, see BR-N3

class MonteFiskalOptions
    Uri? ServiceEndpointOverride, Uri? VerificationBaseOverride
    TimeSpan SendTimeout, RetryPolicy Retry, TimeSpan DeliveryDeadline (48 h)
    bool VerifyResponseSignature (true), TextPolicy Text (Strict | Transliterate)
    RoundingPolicy Rounding, SchemaRevision Schema (latest bundled)
```

Extension points (interfaces): `ICertificateProvider`, `ISequenceAllocator`, `IOutboxStore`, `IArchiveStore`, `IFiscalTransport`, `TimeProvider` (BCL), `IUuidGenerator`, `IRoundingPolicy`, `ITransliterator`.

Several taxpayers or TCRs: `IFiscalClientFactory.Create(FiscalProfile)`; profiles are keyed so one process can serve many shops.

### 2.4 Errors

| Type | When |
|---|---|
| `FiscalValidationException` | Local blocking rule failed; carries rule IDs and paths. The invoice was not issued and no ordinal was consumed. |
| `FiscalConfigurationException` | Missing or inconsistent profile, unusable certificate (wrong key size, expired, TIN mismatch). |
| `FiscalTransportException` | Only from low-level APIs; the high-level client converts it to state `Pending`. |
| `FiscalError` (value, not exception) | A fault returned by CIS, with class and retryability from the data-driven catalogue (`error-codes.md` §3). |

### 2.5 Invoice state machine

`Draft → Issued → (Sending) → Fiscalized`, with `Issued → Pending → Fiscalized`, `Pending → Overdue` (deadline passed, still retried, surfaced loudly), and `Rejected` (permanent fault; needs a person). Every attempt is recorded with time, header UUID, outcome and response.

### 2.6 Calculation

One calculator used by all callers. Input lines carry either a net or a gross unit price. Output is the complete set of formatted strings for the wire. Default policy reproduces the behaviour proven in production by R-01 (BR-A5). For gross-priced lines the line total with VAT is authoritative and net and VAT are derived from it, so that the invoice total equals what the shop charged; this policy needs confirmation against the deferred checks (Q-14). The policy is an interface so that the answer to Q-14 does not change the API.

## 3. PHP core (`montefiskal/core`, PHP 8.5)

Mirror of the .NET library; path `src/php/montefiskal-core/`, PSR-4 root `MonteFiskal\`.

| Aspect | Choice |
|---|---|
| Types | `readonly` classes and enums with the same names as the .NET records and enumerations; value objects for `Tin`, `RegistrationCode`, `Iic`, money as decimal strings |
| Decimal arithmetic | BCMath number objects; no floats anywhere |
| HTTP | PSR-18 client and PSR-17 factories injected; a small cURL-based default so that the WordPress plugin needs no extra package |
| Logging, clock | PSR-3, PSR-20 |
| XML | `ext-dom` for building; exclusive C14N through `DOMNode::C14N`; XML-DSig implemented in the core (small, fixed profile) rather than through a general-purpose library, so that the output is byte-controlled |
| Crypto | `ext-openssl`; PKCS#12 and PEM loading; explicit handling of the legacy-PFX failure (Q-21) |
| Schema validation | Validators generated from `message-catalog.json`; where a real XSD is used, `DOMDocument::schemaValidateSource` needs a copy in which the two `\/` escapes are replaced by `/` (S-23), generated at build time from the bundled official file (Q-20), with the unmodified digest recorded next to it |
| Storage contracts | `SequenceAllocator`, `OutboxStore`, `ArchiveStore` interfaces; PDO implementations optional |
| Entry point | `FiscalClient` with the same methods as `IFiscalClient`, synchronous |

API parity table (excerpt):

| .NET | PHP |
|---|---|
| `IFiscalClient.IssueInvoiceAsync(InvoiceDraft)` | `FiscalClient::issueInvoice(InvoiceDraft): IssuedInvoice` |
| `SendAsync(IssuedInvoice)` | `send(IssuedInvoice): SendResult` |
| `RegisterTcrAsync(TcrRegistration)` | `registerTcr(TcrRegistration): TcrRegistrationResult` |
| `RegisterCashDepositAsync(CashDeposit)` | `registerCashDeposit(CashDeposit): CashDepositResult` |
| `ResendPendingAsync(ResendOptions)` | `resendPending(ResendOptions): ResendSummary` |
| `RecomputeIkof(IkofParameters)` | `recomputeIkof(IkofParameters): IkofCheck` |
| `FiscalValidationException` | `FiscalValidationException` |

## 4. WooCommerce plugin (`plugins/montefiskal-woocommerce/`)

Requires the latest stable WordPress and WooCommerce and PHP 8.5; talks to EFI natively through the PHP core (no intermediate service).

| Area | Design |
|---|---|
| Compatibility | Declares `custom_order_tables` (HPOS) and `cart_checkout_blocks`. All order access through the order CRUD API (`wc_get_order`, order meta methods); no direct post or postmeta access. |
| Data | Own tables with the site prefix: `montefiskal_invoices` (state, number, ordinal, IKOF, JIKR, order ID, kind, totals, signed body, timestamps), `montefiskal_attempts`, `montefiskal_sequences`, `montefiskal_cash_deposits`. Order meta holds only a pointer and a status for list filtering. |
| Numbering | `montefiskal_sequences` row per scope and year, incremented under `SELECT … FOR UPDATE` in a transaction (BR-N5). |
| Trigger | Configurable order status (default: when payment is complete), plus manual order action, bulk action and WP-CLI. Issuing (local) happens in the hook; sending is queued. Two modes: "single invoice" and "advance flow" (advance invoice at payment, invoice with `ADVANCE` + `AdvIIC` at shipment, corrective of the advance; BR-K9). Which one the law requires for prepaid orders is Q-24. |
| Queue | Action Scheduler: `montefiskal/send` per invoice with exponential back-off; recurring `montefiskal/resend_pending`; `montefiskal/daily_cash_deposit` (BR-D5): for a TCR that issues cash invoices the day's `INITIAL` deposit (default 0.00) is registered before the first cash invoice of the day, lazily on first use and optionally at a fixed time; a cash invoice is never sent before it; recurring `montefiskal/deadline_watch` that raises admin notices and e-mails before 48 hours pass (BR-O12). |
| Refunds | `woocommerce_order_refunded`: full refund creates a full corrective invoice; partial refund creates a corrective invoice for the refunded lines, shipping and fees (semantics pending Q-02). Refund is blocked from fiscalizing until the original has a JIKR (BR-C3); it waits in the queue. |
| Payment mapping | Settings table: each active gateway mapped to a payment method, with "do not fiscalize" as an option; `TypeOfInv` follows from the method (BR-K3) and is not chosen separately. Card gateways offer `CARD` (consumer, cash invoice) and `BUSINESSCARD` (company card, non-cash); a rule "buyer gave a tax number" may switch between them (Q-29). Unmapped gateway: invoice not issued, admin notice. |
| Tax mapping | WooCommerce tax rates supply the VAT percentage. Zero-rate tax classes are mapped by the administrator to an exemption code. Prices entered with tax use the gross calculation path. |
| Buyer data | Company name and tax number fields for classic checkout and, through the additional checkout fields API, for block checkout. A buyer with a tax number produces a `Buyer` element; a consumer does not. |
| Settings | Own page under WooCommerce: environment, taxpayer data, VAT status, codes (business unit, TCR, software, operator, maintainer), certificate, trigger, mappings, text policy, retention. Setup wizard with a "test connection" step and optional TCR registration. |
| Certificate | Path outside the web root and password from `wp-config.php` constants or environment variables (preferred); encrypted option as fallback. The certificate is never uploaded through the media library and never included in exports or debug reports. |
| Customer output | E-mail block and order-received/My Account block with number, IKOF, JIKR, verification link and QR; printable invoice template that themes can override; test-mode watermark. |
| Admin | Order screen panel (state, codes, attempts, actions: send now, issue corrective, issue error-corrective); orders-list column and filter; status page (pending count, oldest pending age, certificate expiry, last error); the five inspector reports with CSV export. |
| Multisite | Each site is its own taxpayer profile. |
| Privacy | Personal-data exporter and eraser hooks registered; fiscal records are exempt from erasure during the retention period and the eraser reports that. |
| Uninstall | Never deletes fiscal tables automatically; a separate, explicit tool does, after a warning about the retention duty (BR-X1). |
| Updates | GitHub releases; optional update check against the releases API. No WordPress.org listing. |
| i18n | Text domain `montefiskal-woocommerce`; English source, Montenegrin and Turkish translations planned. |

## 5. Versioned adapter layer

The problem: the service changed its schema incompatibly while endpoint and `Version` stayed the same (drift table in `message-catalog.md`). The specification promises a four-week overlap only for MAJOR versions (BR-T7).

Design:

| Element | Description |
|---|---|
| Schema revision | Identified by the SHA-256 of the XSD plus a human label, for example `fs-v1@2026-11`. `message-catalog.json` is the machine description of one revision. |
| Revision package | Per revision: the catalog JSON, the unmodified XSD and its digest (Q-20), generated wire types and writers, and a mapper between the stable domain model and the wire types. In .NET a source generator reads the catalog; in PHP a build script writes classes that are committed. |
| Stable domain model | The public API in sections 2 and 3. It grows only additively. A field unknown to an older revision is rejected by that revision's mapper with a clear message. |
| Selection | Configured revision, default the newest bundled. No automatic switching at run time: an invoice must be reproducible. |
| Drift detection | A scheduled CI job and an optional run-time health check compare the live XSD digest with the bundled one. On a difference: CI opens a "spec-change" issue with the catalog diff; the library logs a warning and keeps working. |
| Fault hint | When CIS answers with code 11 and the live digest differs from the bundled one, the error carries the hint "schema changed on the server". |
| Adding a revision | Regenerate the catalog from the new XSD, review the diff, add mapper changes and vectors, release a minor version. The extraction tool belongs in a separate tooling folder with tests; the script used for this analysis was temporary and is deliberately not in the repository. |
| Major version | A new endpoint (`fs-v2`) becomes a second adapter family; both can be bundled during the overlap. |
| Stored invoices | Each stored request records its revision so that it can be resent or exported unchanged later. |

## 6. Repository layout

As fixed by the decision document, with the additions this analysis suggests marked "new".

```
src/dotnet/MonteFiskal/                 library packages
src/php/montefiskal-core/               PHP core
plugins/montefiskal-woocommerce/        WooCommerce plugin
tests/dotnet/  tests/php/               test suites
test-vectors/                           shared vectors (see test-strategy.md)
schemas/fs-v1/<revision>/               new: catalog JSON, digest file, the unmodified official XSD and WSDL with a PROVENANCE file (Q-20)
tools/catalog/                          new: XSD-to-catalog generator with its own tests
samples/MonteFiskal.Sandbox/            console sample against efitest
tools/ai-kit/                           new: generator and linter for the assistant kit
docs/                                   analysis, guides
docs/ai/                                new: assistant kit for integrators (briefing, intake questions, prompts, copy-in rules and skill)
AGENTS.md  CLAUDE.md  llms.txt          new: instruction files for coding assistants (see ai-assistant-kit.md)
.claude/rules/  .cursor/rules/          new: contributor rules for Claude Code and Cursor
.github/                                workflows, issue templates, copilot-instructions.md
```

**Decision applied (Q-20): the official XSD and WSDL are bundled unmodified** in `schemas/fs-v1/<revision>/`, next to a `PROVENANCE` file that gives the source URL, the retrieval date, the SHA-256 and the statement that the files are official texts of the Tax Administration of Montenegro, outside the project's own licence. Basis: Article 8 of the Montenegrin copyright law excludes official texts of the administration from protection (D-18), the message rulebook makes the schema binding on every taxpayer, and an MIT-licensed project already redistributes it (R-04). The copy PHP needs (two `\/` escapes replaced) is generated from the bundled file at build time and is not committed. Specifications, rulebooks and other PDF or DOCX documents are **not** bundled: link and SHA-256 only, as before. If the owner prefers zero copied files, the fallback is unchanged: download at build time and verify the digest.

## 7. Continuous integration (GitHub Actions)

| Workflow | Trigger | Jobs |
|---|---|---|
| `ci-dotnet` | push, pull request | restore, build with warnings as errors, unit + vector + fake-service tests, pack |
| `ci-php` | push, pull request | Composer validate, static analysis, coding standard, unit + vector + fake-service tests on PHP 8.5 |
| `ci-plugin` | push, pull request | WordPress and WooCommerce latest; HPOS on and off; plugin integration tests; build the release zip and run the plugin checker |
| `cross-signature` | push, pull request | generate a key pair in the job; .NET signs, PHP verifies, and the reverse; compare canonical bytes |
| `vectors-parity` | push, pull request | both languages must run the same vector IDs |
| `efitest` | manual dispatch and weekly schedule, default branch only | integration suite with secrets from a protected environment; never on fork pull requests; logs scrubbed |
| `schema-drift` | daily schedule | compare live WSDL/XSD digests with `schemas/`; open a "spec-change" issue on difference |
| `secret-scan` | push, pull request | block commits containing private keys, PKCS#12 files or passwords; fail on files with certificate extensions anywhere in the tree |
| `ai-kit` | push, pull request | regenerate `docs/ai/context.md` and `docs/ai/troubleshooting.md` and fail on a difference; compile or run every code block of the prompts and the briefing; check that examples contain placeholders only, that every `BR-` and `Q-` reference and every link resolves, that `CLAUDE.md` imports `AGENTS.md`, that Cursor rules are `.mdc` with front matter and that `llms.txt` follows the format (ai-assistant-kit.md §9) |
| `release` | tag | Plugin zip attached to the GitHub release, checksums; .NET and PHP are used from source (GitHub only) |

All third-party actions pinned by commit; least-privilege `permissions` per workflow.

## 8. Licences and community files

| Component | Licence | Note |
|---|---|---|
| .NET library | MIT | |
| PHP core | MIT | MIT is compatible with inclusion in a GPL plugin |
| WooCommerce plugin | GPL-2.0-or-later | Stated identically in the plugin header, `composer.json`, and licence file |
| Documentation and vectors | same as the component they belong to; analysis documents MIT | |
| Third-party notices | `NOTICE` file listing dependencies; a statement that no code was taken from the reviewed reference projects | |

Root `LICENSE` explains the split and each component directory carries its own licence file.

Issue templates:

| Template | Fields |
|---|---|
| `spec-change` | What changed (schema, error code, rule, URL); evidence (official link, live digest, fault string); date observed; environment (test or production); affected catalog nodes; "I have removed all taxpayer data" checkbox |
| `fiscal-bug` | Component and version; schema revision; scenario; expected and actual; error code and fault string; request with TIN, names, certificate and signature values redacted; "no certificate, key or password attached" checkbox |
| `feature` and `question` | Short forms; questions are pointed to Discussions |

`CONTRIBUTING.md` outline:

1. Scope and non-goals (no hosted service, no WordPress.org listing, latest runtimes only)
2. Ground rules: no certificates, keys, passwords or real taxpayer data, ever
3. Development setup (.NET, PHP, plugin)
4. Running tests; adding a test vector
5. The parity rule: a behaviour change lands in both languages with a vector
6. Handling a specification change (regenerate catalog, review diff, adapter, vectors)
7. Evidence rules: every fiscal rule cites a source; unverified rules warn
8. Coding standards and commit conventions
9. Pull request checklist
10. Licensing of contributions
11. Release process

`SECURITY.md` outline:

1. Supported versions
2. How to report a vulnerability privately (GitHub security advisories)
3. What counts as a vulnerability here (signature or validation bypass, key or certificate exposure, number sequence corruption, injection in the plugin)
4. What never to send (certificates, keys, passwords, real invoices)
5. Response targets and disclosure policy
6. Hardening guidance for users (certificate storage, file permissions, backups of fiscal tables, test versus production)
7. Disclaimer: the software does not replace the taxpayer's legal responsibility; no warranty

## 9. Decisions the coding session needs before it starts

**Nothing is left that blocks coding.** Every question in `open-questions.md` now has either an answer or an applied, switchable default: invoicing mode (Q-24: single invoice at payment, advance flow optional), card kind (Q-29: `CARD`), paper delivery (Q-28), key material (Q-19: none in the repository), official schema (Q-20: bundled unmodified), software registration (Q-26: per-installation codes, self-registration guide), cash limit (Q-15: refuse banknote payments of EUR 10,000 or more). Questions that only an experiment on the test environment can answer (Q-06, Q-07, Q-09, Q-12, Q-14, Q-17, Q-22, Q-23) have defaults and are covered by the opt-in integration suite; the coding session implements the default and keeps the behaviour behind one switch each.

## 10. Assistant kit

Developers will integrate MonteFiskal with coding assistants. The files, their verified formats, the intake questions, the prompts and the acceptance criteria are specified in `ai-assistant-kit.md`. It is part of the first release, not an afterthought: the public API and the option names should be chosen so that the intake questions map one-to-one to settings.
