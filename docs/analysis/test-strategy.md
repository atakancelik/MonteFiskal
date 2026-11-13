# Test strategy

Proposal for how MonteFiskal is tested. No test code exists yet. `BR-…` refers to `business-rules.md`, `S-…` to the schema observations in `message-catalog.md`, `Q-…` to `open-questions.md`.

## 1. Goals

1. The .NET library and the PHP library produce **byte-identical** output for the same input (IKOF string, canonical XML, URL) and each accepts what the other signs.
2. Everything that can be tested without the Tax Administration is tested without it: no network, no real certificate, no secrets in the default test run.
3. The live test environment (efitest) is exercised by a separate, opt-in suite that needs a secret supplied by the person running it.
4. No certificate, private key or password is ever committed.

## 2. Layers

| Layer | Runs | Needs | Covers |
|---|---|---|---|
| L1 Unit | every push, both languages | nothing | formatting, IKOF string, hashing, URL, number format, rounding, validation rules, error parsing, state machine of the outbox |
| L2 Vector | every push, both languages | `test-vectors/` | cross-language equality on shared inputs and expected outputs |
| L3 Schema | every push | bundled official XSD, unmodified (Q-20) | every generated request validates; every negative vector fails with the expected reason |
| L4 Cross-signature | every push, CI job | key pair generated in the job | .NET signs and PHP verifies; PHP signs and .NET verifies; canonical bytes compared |
| L5 Fake service | every push | in-process fake of the SOAP endpoint | success, each fault class, timeout, lost response, malformed body, retry and resend logic, response signature verification |
| L6 Plugin integration | every push | WordPress + WooCommerce test environment, fake service | order to invoice, refund to corrective, HPOS on and off, classic and block checkout fields, Action Scheduler jobs |
| L7 efitest | manual or scheduled, opt-in | taxpayer's own test registration and certificate (secret) | real registration of TCR, cash deposit, invoice, corrective; drift detection |
| L8 Drift watch | scheduled | network | SHA-256 of the live WSDL and XSD against the values in `message-catalog.json` |

## 3. Unit tests with self-generated certificates

The default suites create their own key material at run time.

| Item | Rule |
|---|---|
| Key | RSA 2048 generated in the test fixture (S-08 requires 2048 bits). |
| Certificate | Self-signed X.509 built in the fixture. Subject mimics the shape seen on Montenegrin seal certificates: `C=ME`, `O=MonteFiskal Test Only`, organization identifier `VATME-12345678`, a serial number attribute, `CN=MonteFiskal Test Only`. Validity: a few days around the test clock. |
| TIN | `12345678`, the dummy value used by the specification's examples. Never a real PIB. |
| Codes | Dummy registration codes in the specification's style, for example `bb123bb123` (business unit), `cc123cc123` (TCR), `ss123ss123` (software), `oo123oo123` (operator). |
| Container formats | Fixture writes PKCS#12 (modern encryption), PEM pair, and one PKCS#12 with legacy RC2-40 encryption to test the OpenSSL 3 legacy path (Q-21). Passwords are random per run. |
| Clock | Injected; tests never read the system clock. |
| UUIDs | Injected generator; fixed values in vector tests. |
| Negative fixtures | Expired certificate, not-yet-valid certificate, 1024-bit and 4096-bit keys (must be rejected locally because `IICSignature` would not be 512 hex characters), certificate whose TIN differs from the seller's, certificate without private key. |

What these tests can prove: our signature verifies with our certificate, digest and algorithms are the mandated ones, the structure passes the schema. What they cannot prove: that CIS accepts the certificate (errors 34 to 39). Only L7 can.

## 4. `test-vectors/` format

A directory shared by both languages. One JSON file per vector, UTF-8, LF line endings, grouped by kind.

```
test-vectors/
  README.md
  schema/vector.schema.json
  iic/            IKOF string and hashing
  url/            verification URL
  number/         invoice number
  amounts/        calculation and rounding
  xml/            request building and canonical form
  validate/       local validation, positive and negative
  fault/          SOAP fault parsing
  response/       response parsing
  offline/        export file names and content
```

Common envelope of a vector file:

| Key | Meaning |
|---|---|
| `id` | Stable identifier, equal to the file name, for example `iic-spec-001`. |
| `kind` | One of the directory names. |
| `title` | One line. |
| `source` | Where the expected values come from: a source ID with section (for example `D-01 §4.3.2`), or `derived` with the rule IDs it exercises. |
| `status` | `verified` (expected output confirmed by an official example or by efitest), `derived` (computed by us from the rules), `unverified` (depends on an open question; listed with the `Q` number). |
| `schemaRevision` | SHA-256 of the XSD the vector was written for. |
| `input` | Kind-specific object. |
| `expected` | Kind-specific object. |

Kind-specific content:

| Kind | `input` | `expected` |
|---|---|---|
| `iic` | the seven values as strings | `concat` (the pipe-joined string); optionally `publicKeyPem`, `signatureHex`, `iic` for verification-style checks (see §4.1) |
| `url` | environment, IIC and the seven values, encoding policy | `url` |
| `number` | business unit, ordinal, issue date-time, TCR | `invNum` or `error` |
| `amounts` | lines (quantity, unit price net or gross, rate or exemption, rebate), issuer VAT status, rounding policy | every item attribute and every total as final strings; `sameTaxes` |
| `xml` | a full invoice, TCR or cash-deposit model plus fixed UUID and clock | `unsignedXml` (the request element before signing, exact bytes), `canonicalSha256` (SHA-256 of the exclusive-C14N form of the element, which is the digest value input), `byteLength` |
| `validate` | a model | `valid` or a list of rule IDs with paths |
| `fault` | HTTP status, headers, body | parsed code, class, retryable flag, UUIDs |
| `response` | body | FIC, TCR code or FCDC; header values |
| `offline` | stored attempt | file name, zip name, file content hash |

### 4.1 Signatures in vectors

Signature *values* depend on the private key. The rule "no certificate, private key or password in the repository" therefore shapes the format:

1. Vectors hold **no private key**. Expected signature values appear only where an official document publishes them.
2. For those, the vector carries the **public key** only. The test verifies the published signature over `concat` with the public key and checks that MD5 of the signature bytes equals the published IIC. Because RSASSA-PKCS1-v1_5 is deterministic (BR-I7), a signature that verifies is the only one a correct signer could produce, so this proves the algorithm without the private key.
3. Signing itself is tested in L1 and L4 with keys generated at run time: sign, verify, compare canonical bytes across languages.
4. Full signed XML with a fixed certificate is **not** stored. The `xml` kind stops at the canonical digest input, which is key-independent.

**Decision applied (Q-19): no key material is committed, not even an artificial one.** Signing tests generate an RSA-2048 key and a self-signed certificate in memory at run time (.NET `CertificateRequest`, PHP `openssl_pkey_new` / `openssl_csr_sign`) and check the result by verifying the signature and recomputing the IKOF; the official examples are used in the verify direction only, with their public key. Reasons: the project brief forbids certificates and keys in the repository without exception; nothing in the vector format needs a fixed private key; a committed key is flagged by secret scanners and tends to be reused by integrators.

## 5. Vectors obtainable from the specification

| Vector | Source | Content | Status |
|---|---|---|---|
| `iic-spec-001` | [D-01 §4.3.2.1 to §4.3.2.3] | Input `12345678`, `2019-06-12T17:05:43+02:00`, `9952`, `bb123bb123`, `cc123cc123`, `ss123ss123`, `99.01`; published signature (512 hex characters, begins `A72977773A57`); published IIC `E4033D471FEEA47A3C664B15C669C709`; public key derived from the sample key printed in the specification. | verified by recomputation on 2026-11-13 |
| `iic-spec-001-typo` | same | The string as misprinted in the specification (`bb123bb1231`, `cc123cc1231`, `199.01`) must **not** verify against the published signature. Documents BR-I2. | verified |
| `url-spec-001` | [D-01 §3.7.7.1] | The example URL with `iic=EA26D5BE7F45827026108F825A8A512B`, `tin=91806031`, `crtd=2019-09-26T13:50:13+01:00`, `ord=6`, `bu=bg517kw842`, `cr=xb131ap287`, `sw=gz434bv927`, `prc=199.00`, unencoded policy. | verified as the specification's form; live behaviour Q-06 |
| `fault-spec-001` | [D-01 §3.8.3] | The sample fault, including its mismatched closing tag, which must be reported as a malformed response. | verified |
| `fault-live-011` | [P-01] | The fault observed on efitest for a schema-invalid request: HTTP 500, `env:CLIENT`, code 11, response UUID, no request UUID. UUID replaced by a fixed dummy. | verified |
| `number-live-001` | S-10 | `bb123bb123/9952/2019/cc123cc123` valid; leading zero, missing TCR, upper-case code invalid. | verified against the live XSD |
| `xml-spec-*` | [D-01 Annex and §3.5 to §3.7 examples] | **Not usable as positive vectors.** The specification's XML examples follow the 2020 schema and fail the live XSD (no `InvType`, old `InvNum`). They are kept as negative vectors that must fail local validation with a "schema revision" hint. | verified as invalid |
| `validate-catalog-*` | `message-catalog.json` | Generated: one positive and one negative case per pattern, length limit, enumeration and occurrence limit. | derived |
| `amounts-omf-*` | BR-A5 | Cases reproducing the proven production rounding (21 %, 7 %, 0 %, exempt; quantity with three decimals; negative quantities). | derived, unverified against CIS (Q-14) |

The specification's sample **private** key is not placed in the repository; only its public half is needed (§4.1).

## 6. efitest integration tests

### 6.1 Preconditions

All of these are the responsibility of the person running the suite; the project supplies none of them.

1. A taxpayer registered in the **test** taxpayer register through the test self-care portal `https://efitest.tax.gov.me/self-care` ([D-03]).
2. A certificate accepted by the test environment for that taxpayer, with its private key.
3. On the test portal: a business unit, an operator, a registered software (software code) and, if used, a maintainer code.
4. Network access to `efitest.tax.gov.me`.

Per the official FAQ (D-13) and the access instructions (D-14): fiscalization uses a software certificate issued to the taxpayer's PIB by a certification authority registered in Montenegro (Pošta Crne Gore, CoreIT, Crnogorski Telekom are named); a foreign CA is not accepted; and the certificate a taxpayer already holds on a token for tax filings can be used on the test environment as well as in production. A person without a company registered in Montenegro cannot obtain one (Q-18).

### 6.2 Configuration

Read from environment variables only; never from a file in the repository.

| Variable | Content |
|---|---|
| `MONTEFISKAL_IT_ENABLED` | must be `1`, otherwise the suite is skipped |
| `MONTEFISKAL_IT_PFX_BASE64`, `MONTEFISKAL_IT_PFX_PASSWORD` | certificate container and password |
| `MONTEFISKAL_IT_TIN`, `…_BU`, `…_SOFT`, `…_OPERATOR`, `…_MAINTAINER` | registered codes |
| `MONTEFISKAL_IT_TCR` | optional existing TCR code; when absent the suite registers one |

In CI these are GitHub Actions secrets of a protected environment. The job runs only on the default branch and on manual dispatch, never for pull requests from forks, and masks all values. Logs must not print request bodies, because they contain the taxpayer's TIN and certificate.

### 6.3 Scenarios

| # | Scenario | Confirms |
|---|---|---|
| 1 | Register TCR; register again with the same internal ID | BR-R1, BR-R3 |
| 2 | Cash deposit `INITIAL` 0.00; second `INITIAL` same day | BR-D1, code 56 |
| 3 | Non-cash invoice, VAT, one rate, `ACCOUNT` | baseline |
| 4 | Cash invoice, `CARD`, no buyer; once before and once after the day's `INITIAL` deposit (expect code 58 before) | BR-K1, BR-D5 |
| 5 | Mixed payment, two methods | BR-K4 |
| 6 | Several VAT rates and an exempt item | BR-V3, Q-13 |
| 7 | Non-ASCII characters in names | Q-07 |
| 8 | Full corrective of scenario 3 | BR-C2, BR-C4 |
| 9 | Partial corrective | Q-02 |
| 10 | Same invoice sent twice | Q-09 |
| 11 | Late delivery with `SubseqDelivType`, old `IssueDateTime`, current `SendDateTime` | BR-O7, BR-O9 |
| 12 | `IssueDateTime` with `Z` and with local offset | Q-23 |
| 13 | Deliberate faults: wrong TIN (37), bad signature (29), unknown TCR (53), VAT mismatch (44) | `error-codes.md` |
| 14 | Verification URL opened for a registered invoice, encoded and unencoded forms | Q-06 |
| 15 | Deregister the TCR created in scenario 1 | BR-R4 |

Every confirmed answer is written back: the vector's `status` becomes `verified` and the open question is closed.

### 6.4 Hygiene

Test invoices use obviously artificial item names and amounts. The suite never targets production: the production host name is rejected by the integration-test harness.

## 7. Warning about shared test certificates

A PKCS#12 file with a published password circulates in open-source Montenegrin fiscalization projects. The same file (SHA-256 `52c92c03973e081692627c4abf2a023231f7cc55fb3609c9e6869fa3e171f6f4`) is present in R-01 and R-03.

| Fact | Value |
|---|---|
| Subject | organization "Test Only Company", organization identifier `VATME-12345678` |
| Issuer | the subordinate CA of a private company, not the Tax Administration. That company is named as one of the registered certification authorities in the official FAQ (D-13), so the file looks like a CA-issued demonstration certificate for a dummy company |
| Validity | 2020-10-05 to 2023-10-05: **expired** |
| Official publication | none found; the access instructions [D-03] describe test onboarding with the taxpayer's own certificate and mention no shared test certificate |

Conclusion: it is not proven to be an official test certificate published by the Tax Administration, it may belong to a third party, and it is expired. **MonteFiskal does not use it, does not copy it, and does not reference its password.** End-to-end testing requires the tester's own certificate and their own registration in the test environment. Contributors must not attach certificates, keys or passwords to issues or pull requests; `SECURITY.md` and the issue templates repeat this.

## 8. Coverage targets

| Area | Target |
|---|---|
| Every node in `message-catalog.json` | written by at least one `xml` vector and constrained by at least one `validate` vector; enforced by a test that walks the catalog |
| Every error code in `error-codes.md` | one `fault` vector |
| Every `BR` rule marked as enforced | one positive and one negative test, test name contains the rule ID |
| .NET and PHP | same vector set, same pass list; CI fails when one language skips a vector the other runs |
