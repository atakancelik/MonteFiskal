# Test vectors

Shared contract between the .NET library and the PHP core. Both languages load every file in
this directory and must produce the expected output; CI (`vectors-parity`) fails when one language
runs a vector ID that the other does not.

- One JSON file per vector, UTF-8, LF, named `<id>.json`, in the directory of its `kind`.
- Envelope: `test-vectors/schema/vector.schema.json`. Structural check:
  `python3 tools/vectors/check_vectors.py`.
- **No private key, certificate or password appears in any vector** (analysis Q-19). Signatures are
  tested with keys generated at run time; the only fixed signature is the specification's own
  example, checked in the verify direction with its public key.
- No real taxpayer data: TIN `12345678` (the specification's dummy), registration codes in the
  specification's style (`bb123bb123`), names containing "Test Only".

## Envelope

| Key | Meaning |
|---|---|
| `id` | Stable identifier, equal to the file name. |
| `kind` | Directory name, see below. |
| `title` | One line. |
| `source` | Where the expected values come from: a source ID with section (`D-01 §4.3.2`), or `derived`. |
| `status` | `verified` (official example or efitest), `derived` (computed from the rules), `unverified` (depends on an open question). |
| `rules` | Rule IDs from `docs/analysis/business-rules.md` that the vector exercises. |
| `openQuestions` | Open questions (`Q-..`) the expected value depends on. |
| `schemaRevision` | SHA-256 of the XSD the vector was written for. |
| `input`, `expected` | Kind-specific, below. |

## Kinds

| Kind | `input` | `expected` |
|---|---|---|
| `iic` | the seven IKOF values as strings | `concat`; for the specification example also `publicKeyPem`, `signatureHex`, `iic` and `signatureVerifies` |
| `url` | IIC, the seven values, `environment` (`test`/`production`), `encoding` (`percent`/`none`) | `url` |
| `number` | business unit, ordinal, `issuedAt`, TCR, time zone | `invNum` (and `issueDateTime`) or `error` (rule ID) |
| `time` | `instant`, `timeZone`, `policy` (`local`/`utc`) | `formatted` |
| `amounts` | `issuerInVat`, `itemDecimals`, `rounding`, `lines` | `items` (attribute map per item), `totals`, `sameTaxes` |
| `xml` | `message` (`invoice`, `tcr`, `deposit`) with `profile`, `draft`, `issue` (ordinal, IIC, IIC signature, UUID, send time) or `registration` / `deposit` | `unsignedXml` (exact bytes of the request element before signing; it is already in exclusive-C14N form), `canonicalSha256`, `byteLength`; for invoices `invNum`, `issueDateTime`, `iicConcat`, `typeOfInv` |
| `validate` | `message` and the model, plus `now` / `today` | `valid` and `findings` (rule ID and severity, sorted); `catalogTypes` lists valid and invalid samples for every pattern type |
| `fault` | HTTP status, content type, body | `outcome` (`fault`, `transport`, `malformedResponse`), code, class, retryable, blocking, fault code, fault string, UUIDs |
| `response` | HTTP status, body, `verifySignature` | result element and value (FIC, TCR code, FCDC), header values |
| `offline` | invoices (issue time, TCR, IIC), export time | file names, archive names, size limit |
| `qr` | text, error correction level, mode | version, mask, module matrix (rows of `0`/`1`) and its SHA-256 |
| `report` | report type, period, TCR, invoices, deposits | aggregates (counts, totals, per rate, per payment method, expected cash) |

## Draft model used by `xml`, `amounts` and `validate`

Field names are the camel-case names of the public API (`InvoiceDraft` in .NET, `InvoiceDraft` in PHP):
`kind`, `typeOfInv` (optional, derived from payments by BR-K3), `issuedAt`, `buyer`, `lines`
(`name`, `code`, `unit`, `quantity`, `unitPrice`, `priceIncludesVat`, `vatRate`, `exemption`, `rebate`,
`investment`, `voucherExpiry`, `voucherSerial`), `payments` (`method`, `amount` - null means the
invoice total -, `companyCard`, `vouchers`, `advanceIic`, `bankAccount`), `selfIssuing`,
`reverseCharge`, `payDeadline`, `supplyPeriod`, `currency`, `fees`, `iicRefs`, `correction`,
`badDebt`, `approvals`, `note`, `bankAccountNumber`, `taxFreeAmount`, `markUpAmount`,
`goodsExportAmount`, `totalToPay`, `taxPeriod`, `paragonBlockNumber`, `summaryTotals` (totals of
`SUMMARY`, `PERIODICAL` and `CREDIT_NOTE` invoices without items).

All amounts are decimal strings; never floating point.

## Serialization rules the `xml` vectors fix

1. Root element carries `xmlns="https://efi.tax.gov.me/fs/schema"`; children inherit it.
2. Attributes are written in exclusive-C14N order (by name, code point order); elements in the
   order of the schema's `xs:all` groups as listed in the catalog.
3. Empty elements are written as start and end tag; no whitespace between elements; no XML
   declaration inside the signed element.
4. Attribute values escape `&`, `<`, `"`, tab, LF and CR as C14N does.
5. Totals have two decimals, item amounts four, rates two, quantities the shortest form with at
   most three decimals; `-0` is written as `0`.
6. Date-times are written in the profile's time zone with offset (`Europe/Podgorica`), seconds
   precision (Q-23 default).

Because the written element is already canonical, `canonicalSha256` is the SHA-256 of
`unsignedXml`; both test suites also canonicalize it with the platform C14N and compare.

## Adding a vector

Write the input from the rule, compute the expected value by hand or from an official example,
run both test suites, and only then change code. A vector whose expected value depends on an open
question has status `unverified` and lists the question. See `CONTRIBUTING.md`.
