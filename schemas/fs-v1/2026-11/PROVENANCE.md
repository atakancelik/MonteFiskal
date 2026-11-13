# Provenance of the official files in `official/`

| File | Source URL | Retrieved | SHA-256 |
|---|---|---|---|
| `eficg-fiscalization-service.xsd` | https://efitest.tax.gov.me/fs-v1/eficg-fiscalization-service.xsd | 2026-11-13 | `242c657173ec62f2193447ebc52e3ef26fc4eaecaf7e335c9b9631935591243a` |
| `FiscalizationService.wsdl` | https://efitest.tax.gov.me/fs-v1/FiscalizationService.wsdl | 2026-11-13 | `eb7d2152a5ff6f739dbcddea176d528125c2a39a6e6de8594bb5c8b24fa2d959` |
| `xmldsig-core-schema.xsd` | https://efitest.tax.gov.me/fs-v1/xmldsig-core-schema.xsd | 2026-11-13 | `3c6f965494dbbb62db13d9261739c36068010fc9be0b019785570d63d9ac42ed` |

The same digests were recorded by the analysis (`docs/analysis/sources/README.md`, O-01 to O-03).
The production XSD (`https://efi.tax.gov.me/fs-v1/eficg-fiscalization-service.xsd`) was
byte-identical to the test XSD on that date. The production WSDL differs only in
`soap:address`; it is not bundled.

## Status of these files

- They are **official texts of the Tax Administration of Montenegro** (Poreska uprava),
  published for every taxpayer and software producer that must send fiscalization messages.
  Article 8 of the Montenegrin Law on Copyright and Related Rights excludes official texts
  from the fields of legislation, administration and the judiciary from copyright protection
  (analysis, `open-questions.md` Q-20).
- They are copied **unmodified**. They are **not** covered by MonteFiskal's licences.
- `xmldsig-core-schema.xsd` is the W3C XML Signature schema as served next to the EFI XSD,
  which imports it by relative location.
- Do not edit them. A change on the server is a new revision directory (see
  `CONTRIBUTING.md`, "Handling a specification change").
- The PHP core needs a copy in which the two `\/` escapes (patterns of `InvNumSType` and
  `TaxPeriodSType`) are replaced by `/`, because libxml2 cannot compile them (analysis S-23).
  That copy is produced in memory or in a temporary directory at run time and is never
  committed.

If the Tax Administration asks for removal, the files are deleted and the libraries fall back
to downloading the schema and checking it against `SHA256SUMS`.

## Other files in this directory

| File | Content |
|---|---|
| `message-catalog.json` | Generated from the two official files by `tools/catalog/generate_catalog.py`; regenerating it must give identical bytes (CI job `ci-tools`). |
| `SHA256SUMS` | Digests of the files in `official/`, checked by tests and by the `schema-drift` workflow. |
