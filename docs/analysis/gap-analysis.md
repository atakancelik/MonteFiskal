# Gap analysis

Two existing open-source projects were read to learn from their behaviour: OpenMicroFiscal for the Montenegrin protocol, and a Croatian WooCommerce plugin for shop integration. **No code was copied from either.** Everything below describes behaviour, in our own words, at the commits listed in `sources/README.md` (R-01, R-02).

`BR-…` refers to `business-rules.md`, `S-…` to the schema observations in `message-catalog.md`.

## 1. OpenMicroFiscal (R-01) against MonteFiskal's scope

OpenMicroFiscal is a small .NET library (net10.0, NuGet 4.0.6, Apache-2.0). Its README states its scope: VAT-registered legal entities issuing and cancelling invoices to businesses that pay by bank account or business card. It is in production use, which makes its wire behaviour valuable evidence that the live service accepts it.

Legend: **Yes** = supported; **Partial** = supported with a fixed value or a restriction; **No** = absent.

### 1.1 Operations

| Capability | OpenMicroFiscal | MonteFiskal target | Notes |
|---|---|---|---|
| `RegisterInvoice` | Yes | Yes | |
| `RegisterTCR` (register, update `ValidTo`, deregister) | No; the TCR code must be obtained elsewhere | Yes | BR-R1 to BR-R5 |
| `RegisterCashDeposit` (`INITIAL`, `WITHDRAW`) | No | Yes | BR-D1 to BR-D4 |
| Verification of the response signature | No | Yes, on by default | BR-S6 |
| Local XSD validation before sending | No | Yes | BR-O10, check 1 |
| Message size check | No | Yes | BR-T5 |
| Typed errors with numeric code | No; returns the fault string only | Yes | `error-codes.md` |
| `SOAPAction` header | Not sent | Sent | BR-T4 |

### 1.2 Invoice attributes

| Field or scenario | OpenMicroFiscal | MonteFiskal target |
|---|---|---|
| `TypeOfInv` | Partial: always `NONCASH` | `CASH` and `NONCASH` |
| `InvType` | Partial: `INVOICE` and `CORRECTIVE` | all six values (`SUMMARY`, `PERIODICAL`, `ADVANCE`, `CREDIT_NOTE` behind an "experimental" flag until Q-02 is answered) |
| `IsIssuerInVAT` | Partial: always `true` | both; non-VAT sellers supported (BR-V6) |
| `TypeOfSelfIss` | No | Yes |
| `IsReverseCharge` | No | Yes |
| `IssueDateTime` | Current UTC time, written with `Z` | Caller's clock through an injectable time provider, written with the Europe/Podgorica offset; `Z` accepted (Q-23) |
| `InvNum`, `InvOrdNum` | Ordinal supplied by the caller; number built as BU/ordinal/year/TCR, year taken from a separate tax date | Same format; a sequence allocator interface with a transactional reference implementation (BR-N5) |
| `TaxPeriod` | Yes, `MM/yyyy` from the tax date | Yes, optional |
| `PayDeadline`, `BankAccNum`, `Note`, `ParagonBlockNum` | No | Yes |
| `TaxFreeAmt`, `MarkUpAmt`, `GoodsExAmt` | No | Yes |
| `TotPriceToPay` | Yes: total, floored at zero | Yes |
| `SupplyDateOrPeriod` | No | Yes |
| `Currency` and exchange rate | No | Yes |
| `Fees` | No | Yes |
| `IICRefs` (summary and other references) | No | Yes |
| `BadDebtInv` | No | Yes (experimental, Q-02) |
| `Approvals` (credit note) | No | Yes (experimental, Q-02) |

### 1.3 Parties

| Field or scenario | OpenMicroFiscal | MonteFiskal target |
|---|---|---|
| Seller | Name, ID, address, town, country | Same |
| Seller and buyer ID types | `TIN`, `PASS`, `VAT` | all six (`ID`, `TAX`, `SOC` added) |
| Buyer | Required by its API | Optional, as in the schema; required by validation where the law requires it (BR-K15) |
| Buyer `TIC` | No | Yes |
| Consumer without identification (retail) | Not possible | Yes: no `Buyer` element |

### 1.4 Payment

| Field or scenario | OpenMicroFiscal | MonteFiskal target |
|---|---|---|
| Payment methods | `ACCOUNT`, `BUSINESSCARD` | all eleven |
| Several methods on one invoice | No; one method carrying the total | Yes, up to ten, unique types (S-13) |
| `CompCard`, `Vouchers`, `AdvIIC`, `BankAcc` | No | Yes |

### 1.5 Items and tax

| Field or scenario | OpenMicroFiscal | MonteFiskal target |
|---|---|---|
| Item name, unit, quantity, unit price | Yes | Yes |
| Item code `C` | No | Yes |
| Rebate `R`, `RR` | No | Yes |
| Investment flag `IN` | No | Yes |
| Sold-voucher data `VD`, `VSN` | No | Yes |
| VAT rate | Default 21 %; optional per item | No default; rate is caller data (BR-V4) |
| Exemption code | Free string | Typed enumeration from the schema |
| `SameTaxes` | Yes, grouped by rate and exemption | Yes |
| Input prices | Net unit price only | Net or gross input, explicit rounding policy (BR-A7) |
| Rounding | Half away from zero; unit prices and rate to 4 decimals, quantity to 2, totals to 2 | Same mode as default because it is proven in production; quantity to 3 decimals as the schema allows; policy replaceable |
| Arithmetic self-check before sending | No | Yes (BR-A6) |
| Item count and length limits | No | Yes, from the catalog |

### 1.6 Corrections

| Field or scenario | OpenMicroFiscal | MonteFiskal target |
|---|---|---|
| Full reversal | Yes: caller passes negative quantities and a reference to the original | Yes, plus a helper that builds the reversal from a stored invoice |
| `CorrectiveInv/@Type` | `CORRECTIVE` only | `CORRECTIVE` and `ERROR_CORRECTIVE` |
| Partial correction | Not specifically | Yes (semantics pending Q-02) |
| Check that the original is registered | No | Yes (BR-C3) |

### 1.7 Resilience and offline

| Capability | OpenMicroFiscal | MonteFiskal target |
|---|---|---|
| `SubseqDelivType` | No | Yes, all five values |
| Issue with IKOF when the service is unreachable | No; the call fails | Yes: IKOF and QR are produced before any network call |
| Outbox, retry with back-off, 48-hour deadline tracking | No | Yes (BR-O1, BR-O7, BR-O8, BR-O12) |
| Resend with identical content and IKOF | No | Yes; the signed body is stored, only header and signature are rebuilt |
| Certificate renewal with pending invoices | No | Yes (BR-I5) |
| Offline export and response import (zip format) | No | Yes (BR-O11) |
| Timeouts | HTTP client default | Explicit, configurable (BR-T6) |
| Idempotent handling of a lost response | No | Yes (BR-J3, Q-09) |

### 1.8 Output, storage, inspection

| Capability | OpenMicroFiscal | MonteFiskal target |
|---|---|---|
| Verification URL | Yes; values percent-encoded; production host `mapr.tax.gov.me` | Yes (BR-Q2 to BR-Q6) |
| QR image | No | Yes: SVG and PNG, level M or higher |
| Printable receipt (58 mm, 80 mm ESC/POS, A4) | No | Yes (decision document) |
| Storage of request and response XML | No | Archive interface with a file and a database implementation (BR-X2) |
| IKOF re-creation and verification | No | Yes (BR-I6) |
| Five inspector reports | No | Yes (BR-X3) |
| Logging, metrics, tracing | Minimal | Structured logging and OpenTelemetry (decision document) |

### 1.9 Platform and security

| Aspect | OpenMicroFiscal | MonteFiskal target |
|---|---|---|
| Runtime | .NET 10 | .NET 10 and PHP 8.5 with identical behaviour proven by shared test vectors |
| Dependency injection | Yes, one registration method; certificate through a delegate | Yes; certificate provider interface (file, store, key vault) |
| Several taxpayers or TCRs in one process | Limited: one settings object | Yes: profile per taxpayer and TCR |
| Certificate shipped in the repository | Yes: a `.pfx` with a published password, used by its tests | **None.** Tests generate their own key and certificate (see `test-strategy.md` §6) |
| Schema version handling | None; one hand-written model | Versioned adapter generated from the catalog (`architecture.md` §5) |
| Character-set handling (BR-T8) | None | Optional transliteration |

### 1.10 What OpenMicroFiscal confirms

Because it runs in production, these behaviours are evidence (not proof) about the live service:

| Observation | Relevance |
|---|---|
| A request without a `SOAPAction` header is accepted. | BR-T4 |
| `IssueDateTime` with `Z` is accepted. | Q-23 |
| Non-cash invoices are sent with a TCR code. | BR-K17 |
| `BUSINESSCARD` is used with `NONCASH`. | BR-K3 |
| The production verification host is `mapr.tax.gov.me`. | BR-Q4 |
| Rounding half away from zero with the formulas of BR-A5 passes the deferred checks for its users. | BR-A5 |
| Fault text is read from `env:Fault/faultstring`. | `error-codes.md` §1 |

## 2. Croatian WooCommerce plugin (R-02): integration patterns

The plugin connects WooCommerce to the Croatian fiscalization service. The protocol differs, so only the shop-integration patterns matter. Version 0.5.0, last commit 2024-10-13, PHP 7.4.

| Area | What the plugin does | Assessment | MonteFiskal plugin decision |
|---|---|---|---|
| Trigger | Listens to the order status change hook; the status that triggers issuing is a setting; a "manual only" mode exists. | Good, simple model. | Keep: configurable trigger status, default "processing" for paid orders (Q-24), plus manual action. |
| Manual action | Adds an entry to the order actions drop-down to issue the invoice. | Good. | Keep; add bulk action and WP-CLI command. |
| Execution | Calls the tax service synchronously inside the status-change hook. A failure only writes an order note. No retry. | Weak: a slow or failing service delays checkout or admin, and failed invoices are forgotten. | Never call the service in the request that changes the status. Allocate number and compute IKOF synchronously (fast, local), then send through Action Scheduler with retry and deadline tracking. |
| Settings location | A section under the WooCommerce "Tax" settings tab. | Reasonable, discoverable. | Own settings page under WooCommerce plus a status screen; the Tax tab is crowded. |
| Credentials | Certificate path and password stored as plain options in the database. | Weak. | Certificate file outside the web root; password from a constant in `wp-config.php` or an environment variable; database option only as a fallback, encrypted with a key derived from WordPress salts, and labelled as the least safe choice. |
| Sandbox | One checkbox switches to the test service. | Good. | Keep; show a permanent admin notice and a mark on documents while in test mode. |
| Payment mapping | For each payment gateway, the administrator chooses the fiscal payment type and whether orders paid with it are invoiced at all. | Very good; gateways cannot be classified automatically. | Keep, extended: gateway to `TypeOfInv` + `PayMethod/@Type`, with conservative defaults and a warning for unmapped gateways. |
| Invoice storage | A custom post type with post meta; the invoice content is stored serialized. | Not suitable: post tables are the wrong place for financial records, and it is unrelated to HPOS. | Own database tables (invoices, attempts, sequences, cash deposits), created with `dbDelta`; order link stored through the order CRUD API so that HPOS works. |
| Numbering | Next number found by querying the highest stored value. | Race condition under concurrent orders; duplicates possible. | Dedicated sequence table with row locking in a transaction (BR-N5). |
| Cancellation | A button in a meta box issues a cancellation; not connected to WooCommerce refunds. One code path reads the stored content with a different decoding than it was written with, so cancellation of stored data is fragile. | Weak. | Hook WooCommerce refunds: a full refund creates a full corrective invoice, a partial refund a partial one; manual action as well. |
| Customer e-mail | Adds the fiscal codes, a verification link and an inline QR image to customer e-mails; a PDF is produced from a template that themes can override. | Good pattern. | Keep: e-mail block with IKOF, JIKR, link and QR; overridable template; PDF optional. |
| Order screen | Meta box showing invoice data. | Good. | Keep, HPOS-compatible screen IDs; column in the orders list with fiscal status. |
| Checkout fields | Company and tax-number fields for business buyers. | Needed for non-cash invoices. | Provide for both classic and block checkout (additional checkout fields API). |
| Dependencies | Depends on a third-party fiscalization library pinned to a development branch. | Fragile. | Only our own core library, version-pinned and vendored in the release zip. |
| Legacy PKCS#12 | README documents that OpenSSL 3 cannot open old `.pfx` files without the legacy provider. | Relevant to us: PHP 8.5 links OpenSSL 3. | Detect the failure, show a clear message with the re-export command, document it (Q-21). |
| Distribution | Published through the WordPress.org directory. | Not our model. | GitHub releases only (decision document); self-hosted update check optional. |
| Compatibility declarations | None for HPOS or block checkout. | Would trigger incompatibility warnings today. | Declare `custom_order_tables` and `cart_checkout_blocks` compatibility. |
| Licence | GPL (file says 3.0, package metadata says 2.0-or-later). | Inconsistent. | GPL-2.0-or-later, stated identically in every place. |

## 3. Scenarios neither project covers

These come from the specification and the law and have no precedent in the reviewed code, so they carry the most design risk:

1. Issue-first, send-later flow with the 48-hour deadline (BR-O1, BR-O12).
2. Cash invoices from a web shop paid by card, with the automatic daily zero deposit that must precede them and the paper invoice they require (BR-K1, BR-K3, BR-D5, BR-P4).
7. The advance flow for orders paid before shipment (BR-K9, Q-24).
3. Automatic TCR registration from the plugin's setup wizard (BR-R1).
4. Refund to corrective invoice mapping, including partial refunds and refunds of shipping and fees (BR-C2, BR-C5).
5. Resending after certificate renewal (BR-I5).
6. Schema drift without a version signal (BR-T7).
7. Inspector reports and IKOF re-creation (BR-X3, BR-I6).
8. One process serving several taxpayers (multisite, agencies).

## 4. A third implementation found after the comparison

A source-available fork of an invoicing application with Montenegrin fiscalization (R-05) was found while searching for the current specification. It is written in PHP and, unlike OpenMicroFiscal, covers TCR registration, cash deposits, a send queue, offline export and fiscal reports. It was not compared line by line. Two things matter for MonteFiskal:

1. Its licence is the Elastic License 2.0. It is not open source and its code must not be used as a basis or copied, even in part.
2. Its comments cite the technical specification v5, which this analysis could not read. Where `business-rules.md` uses it, the rule stays UNVERIFIED and names R-05.

Its existence narrows, but does not close, the gap MonteFiskal addresses: there is still no permissively licensed library with the full scope for .NET, none at all for PHP, and no WooCommerce plugin.
