# AI assistant kit

MonteFiskal will be integrated by developers who work with coding assistants (Claude Code, Cursor, GitHub Copilot, Codex and similar). This document specifies what the repository ships for them: instruction files, ready-to-paste integration prompts, and the questions an assistant must ask before it writes any integration code. It is a design with content drafts. The coding session turns the drafts into the files listed in section 3.

Two audiences, kept apart:

| Audience | Where the assistant runs | What it needs |
|---|---|---|
| **Integrator** | In the developer's own project (a .NET application or a WooCommerce site) | What MonteFiskal is, the minimal correct call sequence, the questions to ask the developer, the fiscal rules that must not be guessed, where the details are |
| **Contributor** | In a clone of the MonteFiskal repository | Build and test commands, the parity rule, the evidence rule, what must never be committed |

## 1. File formats, verified

Read from each tool's own documentation on 2026-11-13. A format not listed here is not shipped.

| File | Read by | Format facts that matter | Source |
|---|---|---|---|
| `AGENTS.md` | Codex, Cursor, GitHub Copilot, Jules, Zed, Aider and others; Claude Code when the repository has no `CLAUDE.md` | Plain Markdown, no front matter, no required sections. Cursor and Copilot also read nested `AGENTS.md` files in subdirectories; the nearest one applies. | `https://agents.md/`; `https://cursor.com/docs/rules.md` (section "AGENTS.md"); `https://docs.github.com/en/copilot/how-tos/configure-custom-instructions/add-repository-instructions` |
| `CLAUDE.md` | Claude Code | Markdown at the repository root or in `.claude/`. Loaded at session start; files in subdirectories load when Claude works there. `@path` imports another file. Target under 200 lines. If both `AGENTS.md` and `CLAUDE.md` exist, Claude Code reads `CLAUDE.md` only, so the documented way to share one file is a `CLAUDE.md` whose first line is `@AGENTS.md`. Reading `AGENTS.md` directly needs Claude Code 2.1.277 or later. | `https://code.claude.com/docs/en/memory.md` |
| `.claude/rules/*.md` | Claude Code | Markdown, one topic per file; optional YAML front matter `paths:` with glob patterns scopes a rule to matching files. | same page, "Organize rules" and "Path-specific rules" |
| `.claude/skills/<name>/SKILL.md` | Claude Code and other tools that follow the Agent Skills standard | YAML front matter with `name` and `description` (the description decides when the skill is used), then Markdown instructions. Invoked as `/<name>` or picked up automatically. Older `.claude/commands/*.md` files still work but skills are the current form. | `https://code.claude.com/docs/en/skills.md` |
| `.cursor/rules/*.mdc` | Cursor | Markdown with front matter `description`, `globs` (comma-separated patterns), `alwaysApply`. The extension must be `.mdc`; a plain `.md` file in that folder is ignored. `alwaysApply: true` always loads; with a `description` and no globs the agent loads it when relevant; with `globs` it loads for matching files. Keep under 500 lines. The legacy `.cursorrules` file is not mentioned in the current documentation and is not shipped. | `https://cursor.com/docs/rules.md` |
| `.github/copilot-instructions.md` | GitHub Copilot | Plain Markdown, repository-wide. Copilot also reads `AGENTS.md`, so this file only points to it. | GitHub documentation page above |
| `llms.txt` | Any agent that fetches documentation | Version 2 of the proposal (modified 2026-08-10): an H1 with the project name (the only required part), a blockquote summary, optional paragraphs without headings, then H2 sections that are lists of `[name](url): note` links; a section named `Optional` holds links that may be skipped. Defined for web sites ("at the root path or any subpath"). MonteFiskal has no web site, so the file sits at the repository root and its links are raw GitHub URLs; that placement is a convention, not part of the proposal. | `https://llmstxt.org/` |

## 2. Principles

| # | Principle | Reason |
|---|---|---|
| 1 | One source of truth: `docs/ai/` holds the content; tool-specific files are thin wrappers that point to it or repeat at most the safety rules. | Four tools, one text to maintain. |
| 2 | An assistant must **ask before it assumes**. Every value in section 5 comes from the developer or from the developer's SEP account, never from an example. | A wrong code produces invoices that are rejected, or worse, accepted with wrong data. |
| 3 | Fiscal rules are quoted with their rule ID (`BR-…`) and the assistant is told to look the rule up instead of reasoning about tax law. | The analysis marks what is official and what is not; an assistant's general knowledge about other countries' fiscalization is wrong here. |
| 4 | Test environment first. No prompt ever leads to a production call before a checklist is passed. | A fiscalized invoice cannot be deleted, only corrected. |
| 5 | No secrets in prompts, logs, commits or issues: no certificate, key, password; no real taxpayer number in anything that leaves the developer's machine. | Project rule; also keeps issue reports clean. |
| 6 | Prompts are versioned with the library and name the version they were written for. | An old prompt against a new API fails silently. |
| 7 | Every prompt ends with a verification step the developer can see (a test run against the fake service, an IKOF recomputation, a schema validation). | "It compiles" is not evidence of a correct fiscal message. |

## 3. Files to ship

| Path | Audience | Content |
|---|---|---|
| `AGENTS.md` | Contributor | Section 4.1 |
| `CLAUDE.md` | Contributor | `@AGENTS.md` plus the Claude-specific lines of section 4.2 |
| `.claude/rules/fiscal-rules.md` | Contributor | Evidence rule and parity rule, scoped with `paths:` to `src/**`, `plugins/**`, `test-vectors/**` |
| `.claude/rules/secrets.md` | Contributor | What must never be written to the tree (unscoped) |
| `.cursor/rules/montefiskal-contributing.mdc` | Contributor | `alwaysApply: true`; the same two rules in short form, with a pointer to `AGENTS.md` |
| `.github/copilot-instructions.md` | Contributor | One paragraph pointing to `AGENTS.md` |
| `llms.txt` | Integrator | Section 4.3 |
| `docs/ai/README.md` | Integrator | How to use the kit: which file to copy where, per tool |
| `docs/ai/context.md` | Integrator | The single-file briefing of section 6; this is what a developer attaches or pastes when the assistant cannot browse |
| `docs/ai/questions.md` | Integrator | The intake questions of section 5, as a checklist an assistant walks through |
| `docs/ai/prompts/*.md` | Integrator | The prompts of section 7, one file each, each starting with a short "when to use" line and the version it targets |
| `docs/ai/integrator-kit/AGENTS.snippet.md` | Integrator | A block the developer appends to the `AGENTS.md` of **their** project |
| `docs/ai/integrator-kit/cursor/montefiskal.mdc` | Integrator | A Cursor rule the developer copies into `.cursor/rules/` of their project; `description` set, `alwaysApply: false`, so that it loads when fiscalization is being worked on |
| `docs/ai/integrator-kit/claude/skills/montefiskal-integration/SKILL.md` | Integrator | A skill the developer copies into `.claude/skills/` of their project; runs the intake questions, then the matching prompt |
| `docs/ai/troubleshooting.md` | Both | Error code to action table generated from `error-codes.md`, in the shape "code, meaning, retry or not, what to ask the developer" |
| NuGet and Composer package READMEs | Integrator | A five-line "Using an AI assistant?" section linking to `llms.txt` and `docs/ai/context.md`, because the package README is the first thing an assistant reads from a restored package |
| Plugin `readme.txt` / settings page | Integrator | A link "Set up with an AI assistant" to `docs/ai/prompts/woocommerce-setup.md` |

The integrator kit is copied by the developer, never installed automatically: MonteFiskal does not write into files of another project.

## 4. Content drafts: repository-level files

### 4.1 `AGENTS.md`

```
# MonteFiskal

Open-source client for Montenegro's EFI fiscalization service: a .NET 10 library, a PHP 8.5 core
with the same API, and a WooCommerce plugin. There is no hosted service.

## Layout
src/dotnet/        .NET packages          tests/dotnet/
src/php/           PHP core               tests/php/
plugins/montefiskal-woocommerce/          WooCommerce plugin
test-vectors/      shared vectors, both languages must pass the same IDs
schemas/fs-v1/     message catalog and the official schema, unmodified
docs/analysis/     the specification this code implements; rule IDs (BR-…) come from here
docs/ai/           material for assistants that integrate MonteFiskal into other projects

## Commands
<build, test, lint and vector commands for .NET, PHP and the plugin; filled in by the coding session>

## Rules
1. Never add a certificate, private key, PKCS#12 file, password or real taxpayer data. Tests generate
   keys at run time. CI rejects such files.
2. Evidence rule: a fiscal behaviour is implemented only if docs/analysis/business-rules.md has a rule
   for it. Quote the rule ID in the code comment and in the test name. If the rule is missing or marked
   UNVERIFIED, stop and say so; do not fill the gap from general knowledge or from another country's
   system.
3. Parity rule: a behaviour change lands in .NET and PHP together, with a shared test vector.
4. Do not edit files under schemas/**/official/. A schema change is a new revision (see
   docs/analysis/architecture.md, "Versioned adapter layer").
5. Money is decimal, never floating point. Rounding follows BR-A5 to BR-A7.
6. No call to the production endpoint from tests, samples or CI.
7. Do not copy code from other fiscalization projects.

## Before you finish
Run the unit, vector and fake-service suites for every language you touched. For a change to XML
output, show the canonical bytes diff of the affected vectors.
```

### 4.2 `CLAUDE.md`

```
@AGENTS.md

## Claude Code
- Use plan mode for changes under src/**/Security, src/**/Wire and the plugin's sequence allocator.
- The integration suite against efitest needs a certificate that is not in this repository; do not
  try to obtain or generate one for it. Run it only when the developer says the secrets are set.
```

### 4.3 `llms.txt`

```
# MonteFiskal

> Open-source client for Montenegro's EFI fiscalization service (Poreska uprava): .NET 10 library,
> PHP 8.5 core with the same API, WooCommerce plugin. The taxpayer supplies its own certificate and
> its own codes from the SEP portal; there is no hosted service and no shared test certificate.

Before writing integration code, ask the developer the questions in "Intake questions". Do not invent
codes, tax numbers or certificate paths. Use the test environment until the go-live checklist passes.
Montenegro's rules differ from Croatia's, Serbia's and Albania's; use the rules linked here.

## Start here
- [Briefing for assistants](<raw URL>/docs/ai/context.md): everything needed for a first integration, one file
- [Intake questions](<raw URL>/docs/ai/questions.md): what to ask the developer first
- [Prompts](<raw URL>/docs/ai/prompts/): ready-made tasks for .NET, PHP and WooCommerce

## Reference
- [Business rules](<raw URL>/docs/analysis/business-rules.md): every fiscal rule with its source
- [Message catalog](<raw URL>/schemas/fs-v1/current/message-catalog.json): every element, type and limit
- [Error codes](<raw URL>/docs/ai/troubleshooting.md): what each code means and what to do
- [API reference .NET](<raw URL>/docs/api/dotnet.md)
- [API reference PHP](<raw URL>/docs/api/php.md)

## Optional
- [Open questions](<raw URL>/docs/analysis/open-questions.md): what is not officially settled
- [Architecture](<raw URL>/docs/analysis/architecture.md)
```

## 5. Intake questions

The assistant asks these before writing code, in this order, and stops at the first answer that makes the plan impossible. Each question says where the developer finds the answer, so that the assistant can help instead of guessing. "Stored as" names the setting; the names are final only after the coding session fixes the options type.

### 5.1 Who and where

| # | Question | Where the answer comes from | Why | Stored as |
|---|---|---|---|---|
| 1 | Which integration: a .NET application, a PHP application, or a WooCommerce shop? Which versions? | The project | MonteFiskal supports .NET 10, PHP 8.5 and the latest WordPress and WooCommerce only. Older: stop and say so. | n/a |
| 2 | Is the seller a taxpayer registered in Montenegro with a PIB? | Company registration | Without a Montenegrin PIB there is no certificate and no SEP access. Stop. | `Seller.Tin` |
| 3 | Is the seller registered for VAT? | Tax registration | Decides `IsIssuerInVAT`; a seller outside VAT sends no VAT amounts (BR-V1). | `Seller.IsInVat` |
| 4 | Test or production now? | Developer's intent | Always start on test. Production needs the checklist of section 8. | `Environment` |

### 5.2 Certificate

| # | Question | Where the answer comes from | Why | Stored as |
|---|---|---|---|---|
| 5 | Do you have a certificate for electronic signature issued to the seller's PIB by a Montenegrin certification authority (Pošta Crne Gore, CoreIT, Crnogorski Telekom)? As a file (PKCS#12) or on a token? | The certification authority | Fiscalization signs every message. A token cannot be used by a server. No certificate: only the offline tests and the fake service are possible; say so and continue with those. | `Certificate.*` |
| 6 | Where will the server read the certificate and its password from? | Hosting setup | Must be outside the web root and outside the repository: environment variable, secret store, or a path with restricted permissions. **Never ask the developer to paste the certificate or the password into the chat.** | `Certificate.Path`, `Certificate.PasswordSource` |

### 5.3 Codes from the SEP portal

| # | Question | Where the answer comes from | Why | Stored as |
|---|---|---|---|---|
| 7 | Business unit code (`BusinUnitCode`)? Is the unit registered for cash invoices, non-cash invoices or both? Type "internet trgovina"? | SEP, My data, Business units | Ten characters, generated by the portal. The cash or non-cash registration decides the deposit duty (BR-D5). | `BusinessUnitCode`, `BusinessUnitMode` |
| 8 | Operator code (`OperatorCode`)? | SEP, My data, Operators (generated after entering the operator's personal number) | Required on every invoice. For a web shop it is the person responsible for the shop. | `OperatorCode` |
| 9 | Is the software registered on the SEP, and what is its code (`SoftCode`)? Who is registered as maintainer (`MaintainerCode`)? | SEP, Software | A company that uses the software and owns its installation may register it itself (BR-R7). If not registered yet, point to the setup guide's step and continue on test. | `SoftwareCode`, `MaintainerCode` |
| 10 | Is a TCR (ENU) already registered for this shop or cash register, and what is its code (`TCRCode`)? If not, should MonteFiskal register it through the service? | SEP, Business units, ENU; or the response of `RegisterTcr` | Every invoice needs a TCR code, non-cash ones included (BR-K17). Registration needs questions 7 to 9 first. | `TcrCode`, `TcrInternalId` |

### 5.4 What is sold and how it is paid

| # | Question | Where the answer comes from | Why | Stored as |
|---|---|---|---|---|
| 11 | Which payment methods does the shop accept: card online, bank transfer, cash on delivery, vouchers, other? | The shop | Maps to `CARD`, `ACCOUNT`, `BANKNOTE`, … and through them to a cash or non-cash invoice (BR-K3). A consumer card payment is a **cash** invoice. | Payment mapping |
| 12 | Do companies buy from you, and do you collect their tax number at checkout? | The shop | A buyer with a tax number gets a `Buyer` element. Whether a card is a company card cannot be seen from the gateway; default is `CARD` (Q-29). | Buyer fields, card rule |
| 13 | When is the order paid relative to shipment: at checkout, on delivery, by invoice later? Do you take pre-orders or deposits? | The shop | Payment before shipment is formally an advance (Q-24). Default: one invoice at the moment payment is confirmed. Pre-orders and deposits: use the advance flow. The developer's accountant decides; the assistant states the two options and does not choose silently. | `InvoicingMode` |
| 14 | Which VAT rates apply to your products (21 %, 15 %, 7 %, 0 %), and is anything exempt? Under which article? | The seller's accountant | Rates come from the shop's tax settings; an exempt line needs an exemption code (BR-V5). The assistant does not decide tax treatment. | Tax mapping |
| 15 | Are prices entered with VAT included? | The shop | Selects the gross or the net calculation path (BR-A5 to BR-A7). | `PricesIncludeVat` |
| 16 | Do you sell in a currency other than EUR? | The shop | Needs the currency block and an exchange rate (BR-K12). | Currency settings |
| 17 | How does the buyer receive the invoice: paper in the parcel, e-mail, both? Do you sell digital goods? | The shop | A cash invoice must be handed over on paper (BR-P4). Digital goods: not officially settled (Q-28); say so. | Output settings |
| 18 | Do you refund partially? | The shop | Decides whether partial corrective invoices are needed (BR-C5). | n/a |

### 5.5 Operations

| # | Question | Where the answer comes from | Why | Stored as |
|---|---|---|---|---|
| 19 | Where are invoices and sequence numbers stored, and is that store backed up? | The application | The ordinal must be gap-free per TCR and year and survive restarts (BR-N5); fiscal records are kept for years (BR-X1). | Storage choice |
| 20 | Does anything else issue fiscal invoices for the same TCR (a cash register, an ERP)? | The seller | Two issuers on one TCR break the sequence. Use a separate TCR for the shop. | n/a |
| 21 | Who is told when an invoice could not be sent within the deadline? | The seller | Unsent invoices must be delivered within 48 hours (BR-O1, BR-O12); someone must see the alert. | Notification settings |

Stop conditions the assistant must state plainly: no Montenegrin PIB; runtime older than supported; certificate only on a token for an unattended server; the developer wants to skip the test environment; the developer asks to reuse a certificate found in another repository.

## 6. Briefing file (`docs/ai/context.md`)

One file, at most about 400 lines, written for a model that sees nothing else. Sections, in order:

1. What MonteFiskal is, in five lines, and what it is not (no hosted service, no accounting advice).
2. The three operations of the service and when each is called: register TCR once; register the day's initial cash deposit before the first cash invoice of a day; register each invoice.
3. The life of an invoice: draft, local issue (number, IKOF, signature), send, JIKR; what to print (number, IKOF, JIKR, QR, operator, software code); what to do when sending fails (hand over the invoice, resend within the deadline, never renumber).
4. The decision table "payment method to invoice type" (BR-K3) and the deposit consequence (BR-D5).
5. Corrections: an invoice is never edited or deleted; refunds are corrective invoices that reference the original IKOF.
6. The minimal correct code path in .NET and in PHP: configure, issue and send, handle the result, resend pending. Taken from the tested samples, not written by hand.
7. Configuration reference: every option with type, source (which SEP screen) and example **placeholder** (`<your business unit code>`), never a realistic-looking value.
8. The ten mistakes an assistant is most likely to make, each with the rule ID:
   - computing the IKOF itself instead of calling the library;
   - floating-point money;
   - treating a consumer card payment as non-cash;
   - generating a new invoice number on retry;
   - sending to production in a test;
   - inventing `SoftCode` or `MaintainerCode`;
   - storing the certificate in the repository or the web root;
   - deleting or updating a fiscalized invoice instead of issuing a corrective;
   - applying Croatian, Serbian or Albanian rules;
   - skipping the initial cash deposit on a day with cash invoices.
9. What is not officially settled (Q-24, Q-28 digital goods, Q-29) and the defaults, with the sentence "tell the developer that their accountant decides".
10. Where to look next: links to the rule book, the catalog and the error table.

The file is generated at release time from the analysis documents and the tested samples by a small script, and a CI check fails when it is out of date. Hand-written copies drift.

## 7. Prompts

Each prompt is a Markdown file under `docs/ai/prompts/`. Common frame for all of them (the wording below is the draft; angle brackets are filled in by the developer):

```
You are helping me integrate MonteFiskal <version> (Montenegro EFI fiscalization) into my project.

Read first: <raw URL>/llms.txt and the briefing it links to. If you cannot fetch URLs, tell me and I
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

| File | When to use | Task (after the common frame) | Verification the prompt demands |
|---|---|---|---|
| `dotnet-first-invoice.md` | New .NET integration | Add the packages; bind options from configuration and a secret source; register the client; issue and send one invoice built from my order type `<type>`; map the result to my order; add the resend background service. | A test with `MonteFiskal.Testing`'s fake endpoint that issues an invoice and asserts state, number format and a recomputed IKOF. |
| `dotnet-existing-pos.md` | A .NET point-of-sale that already prints receipts | Map my receipt model to `InvoiceDraft`; add the daily initial deposit; add printing of IKOF, JIKR and QR to my receipt template. | Fake-service test for a cash sale, a card sale and a refund. |
| `php-first-invoice.md` | Plain PHP 8.5 application | Same as the .NET prompt with Composer, a PSR-18 client and a PDO store. | PHPUnit test against the fake endpoint. |
| `woocommerce-setup.md` | Shop owner's developer installs the plugin | Walk through the setup wizard values with me (section 5 questions); map my payment gateways and tax classes; choose the invoicing mode; place the certificate outside the web root and define the constants in `wp-config.php`; run "test connection". | The plugin's status page shows a valid certificate, a registered TCR and a successful test invoice **on the test environment**; WP-CLI check command output. |
| `woocommerce-customize.md` | Theme or plugin developer | Override the invoice template or e-mail block; add a custom payment gateway mapping through the documented filters; do not touch numbering or sending. | The printable invoice still shows number, IKOF, JIKR, QR of at least 21 by 21 mm (BR-Q8). |
| `corrective-invoice.md` | Refunds and mistakes | Implement full and partial refunds as corrective invoices referencing the original IKOF; cash refunds with a withdrawal; error-corrective within the allowed days. | Fake-service test: original, partial corrective, totals reconcile. |
| `advance-flow.md` | Pre-orders, deposits, strict mode | Implement advance invoice at payment, final invoice with `ADVANCE` and `AdvIIC`, corrective of the advance (BR-K9). | Three documents in the fake service with correct references. |
| `offline-and-resend.md` | Reliability work | Persist issued invoices before sending; resend with the right subsequent-delivery type; alert before the deadline; never renumber. | Test: endpoint down, invoice issued and printed, endpoint up, same IKOF delivered. |
| `go-live.md` | Before the first production invoice | Walk the checklist of section 8 with me and refuse to switch the environment until every item is confirmed. | The filled checklist. |
| `diagnose-error.md` | An error code or fault string appeared | Given code `<code>` and the redacted request, find the cause in `troubleshooting.md`, say whether a retry is allowed, and tell me what to change. Redact PIB, names, certificate and signature values before showing anything. | The cited table row and the rule ID. |
| `upgrade.md` | New MonteFiskal release | Read the release notes; if "fiscal behaviour changed: yes", tell me to register the new software version on the SEP and where to enter the new code (BR-R7); apply API changes. | Test suite green; settings show the new code. |
| `spec-change-report.md` | The service started rejecting valid messages | Collect evidence (schema digest, fault string, date, environment), redact, and draft a `spec-change` issue from the template. | The draft issue, no taxpayer data in it. |

The integrator skill (`SKILL.md`) is the same material in another shape: its description reads "Integrate or troubleshoot MonteFiskal (Montenegro EFI fiscalization) in a .NET, PHP or WooCommerce project"; its body runs the intake questions, selects one prompt by the answers, and ends with that prompt's verification. The Cursor rule holds the common frame and the ten mistakes, and links to the prompts.

## 8. Go-live checklist (content of `go-live.md`)

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

## 9. What the coding session must do

| # | Work item | Acceptance |
|---|---|---|
| 1 | Create the files of section 3 from the drafts of sections 4 to 8, filling commands and API names from the code as built. | Every file exists; `CLAUDE.md` starts with `@AGENTS.md`; the Cursor files have the `.mdc` extension and valid front matter; `llms.txt` has exactly one H1, then a blockquote, then only H2 link lists. |
| 2 | Generate `docs/ai/context.md` and `docs/ai/troubleshooting.md` from the sources (analysis documents, tested samples, error table) with a script under `tools/ai-kit/`. | CI job `ai-kit` regenerates and fails on a difference. |
| 3 | Every code block in the prompts and the briefing is compiled or executed in CI (extracted to a sample project). | A renamed API breaks the build, not the user's first try. |
| 4 | Lint the kit: no realistic-looking PIB, code or key in any example (placeholders only); every `BR-` and `Q-` reference resolves; every link resolves. | Part of the `ai-kit` job. |
| 5 | Dry-run each prompt once against an empty sample project with at least one assistant and record in the pull request what the assistant asked and produced. This is a manual review, not a CI job. | Notes attached to the release pull request; prompts corrected where the assistant skipped the intake questions or guessed a value. |
| 6 | Version stamp: each prompt names the MonteFiskal version; the release process updates the stamp. | Release checklist item. |

Not part of the kit: an MCP server, a hosted chatbot, or any component that sends the developer's data to a third party. If there is demand later, a local MCP server exposing "validate invoice", "explain error code" and "recompute IKOF" would be the natural next step; it is recorded here as an idea only.
