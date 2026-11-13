# Business rules

Rules a MonteFiskal implementation must enforce or support that are not fully expressed by the XSD. Source IDs are defined in `sources/README.md`; `S-nn` refers to "Schema observations" in `message-catalog.md`; `Q-nn` refers to `open-questions.md`.

Evidence labels: a rule with no label is stated by the cited official source. **OBSERVED** means seen in the live schema or service on 2026-11-13. **UNVERIFIED** means the only evidence is outdated, third-party or inferred; such a rule must be configurable and must not be hard-coded as a blocking check.

Which specification a rule is cited to: the current official set was read on 2026-11-13, technical specification **v5** (D-07), functional specification **v5** (D-08), the examples annex v4 (D-09) and two rulebooks (D-10, D-11). Rows that cite the 2020 documents (D-01, D-02) were written before v5 could be read; the v5 sections on controls, TCR registration, cash deposit, invoice fields, QR code, error messages and security repeat those rules unless a row below says otherwise, and section numbers differ between the versions. Every row that changed in substance now cites D-07, D-08 or D-09. The live schema is slightly newer than the v5 documents (see "Schema drift" in `message-catalog.md`): structure always follows the live schema. A reproduction of the Tax Administration's FAQ (T-01) and a third-party implementation (R-05) are corroboration only and never remove an UNVERIFIED label by themselves. In the fourth round the Tax Administration's own FAQ (D-13), the access instructions v4.1 (D-14), the current law text (D-12) and the rulebook annexes (D-16) were read; rows that used to rest on a third-party copy of the FAQ now cite D-13.

## 1. Terms

| Term (ME) | Term (schema) | Meaning | Source |
|---|---|---|---|
| IKOF, identifikacioni kôd obveznika fiskalizacije | IIC | Code generated and signed by the issuer for every invoice | [D-04 Art. 3], [D-01 §4.3.2] |
| JIKR, jedinstveni identifikacioni kôd računa | FIC | Code returned by the Tax Administration when the invoice is registered | [D-04 Art. 3], [D-02 §3.2] |
| ENU, elektronski naplatni uređaj | TCR | The device or software instance that issues invoices | [D-02 §5.2] |
| CIS | CIS | Central invoice system of the Tax Administration | [D-01 §1.1] |
| SEP | self-care portal | Taxpayer portal: registration of business units, operators, software; manual invoice entry. Test: `https://efitest.tax.gov.me/self-care`; production: `https://sep.tax.gov.me/self-care`. Chrome only (certificate login). Registers behind it: ROF (fiscalization taxpayers) and RSPOS (software, producers, maintainers). | [D-03], [D-14], [D-15] |
| PIB / JMB | TIN | 8-digit tax number of a legal entity / 13-digit personal number | [D-01 §3.5.1], S-12 |

## 2. Transport and envelope

| ID | Rule | Source |
|---|---|---|
| BR-T1 | Endpoints: test `https://efitest.tax.gov.me/fs-v1`, production `https://efi.tax.gov.me/fs-v1`. | [D-01 §2], [O-01], [O-04] |
| BR-T2 | SOAP 1.1, document/literal, HTTP POST, XML encoded as UTF-8. Server authentication by TLS only (one-way TLS); the client is authenticated by the XML signature, not by a client TLS certificate. | [D-01 §2.3.2, §2.3.3] |
| BR-T3 | The SOAP header is empty. The request element is the first and only child of the SOAP body and contains its own signature. | [D-01 §3.3, §4.3.1] |
| BR-T4 | `SOAPAction` values are declared in the WSDL (`https://efi.tax.gov.me/fs/RegisterInvoice`, `.../RegisterTCR`, `.../RegisterCashDeposit`). R-01 sends no `SOAPAction` header and works in production, so the server appears to dispatch on the body element. MonteFiskal should send the declared value. Whether it is required is UNVERIFIED. | [O-01], [R-01] |
| BR-T5 | A request larger than 150 kB is rejected (error 1). | [D-01 §3.4 Table 7] |
| BR-T6 | The issuer chooses the client timeout. CIS processing time is stated as at most 2 seconds, so the timeout must be longer than that plus network latency. | [D-01 §3.7], [D-02 §3.4] |
| BR-T7 | Versioning is semantic. A MAJOR change gets a new endpoint suffix (`-v2`); the previous version stays available for four weeks. MINOR changes are announced as backward compatible on the same endpoint. OBSERVED: the schema changed incompatibly (`InvType` and `TCRCode` became required) without a new endpoint or `Version` value. | [D-01 §1, §3.1], drift table |
| BR-T8 | Character set: "all items in all messages use only characters encoded as one byte in the standard ASCII set; allowed decimal codes are 9, 10, 13 and 32 to 126". v5 repeats the sentence unchanged, while its own examples contain names such as "Petar Petrović". The XSD has no such restriction. Whether the live service rejects non-ASCII text is UNVERIFIED (Q-07). The library must offer an optional transliteration step and must not silently alter text by default. | [D-01 §3.2], [D-07 §3.2] |

## 3. Request signature

| ID | Rule | Source |
|---|---|---|
| BR-S1 | Every request is signed with XML-DSig. Signature type enveloped; the `ds:Signature` element is a child of the request root. | [D-01 §4.3.1], S-02 |
| BR-S2 | Exactly one reference, URI `#Request`, pointing at the root attribute `Id="Request"`. | [D-01 §4.3.1], S-01 |
| BR-S3 | Transforms: `http://www.w3.org/2000/09/xmldsig#enveloped-signature`, then `http://www.w3.org/2001/10/xml-exc-c14n#`. Canonicalization method `http://www.w3.org/2001/10/xml-exc-c14n#`. Digest `http://www.w3.org/2001/04/xmlenc#sha256`. Signature method `http://www.w3.org/2001/04/xmldsig-more#rsa-sha256`. | [D-01 §4.3.1] |
| BR-S4 | `KeyInfo` carries one `X509Data/X509Certificate` with the signing certificate. More key information than needed is an error (32), as is more than one signature (24), a wrong signed element (25) or any other algorithm (26, 27, 28). | [D-01 §3.8.2] |
| BR-S5 | The TIN in the message must equal the TIN in the certificate (error 37). For invoices the TIN is `Seller/@IDNum`; for TCR and cash-deposit messages it is `IssuerTIN`. | [D-01 §3.8.2], [D-02 §6.6] |
| BR-S6 | Responses are signed by CIS the same way, with `Id="Response"`. Fault responses are not signed. Verifying the response signature is not mandated by the specification; MonteFiskal should verify it by default. Which certificate chain CIS uses is not documented (Q-12). | [D-01 §3.3, §3.8], OBSERVED [P-01] |
| BR-S7 | Three certificates are involved: the service's TLS certificate (public CA); the service's message certificate, used to sign responses (registered CA); the issuer's message certificate, used to sign requests and the IKOF (registered CA). Only an issuer certificate from a registered CA is accepted (errors 33 to 39). | [D-01 §4.1, §3.8.2] |
| BR-S8 | The specification's sample signing code writes `Version="3"` in one place. The schema fixes `Version="1"`. The sample is wrong; follow the schema. | [D-01 Annex 1], S-01 |

## 4. IKOF (IIC) algorithm

Source for the whole section: [D-01 §4.3.2].

1. **Concatenate** seven values with the pipe character (decimal code 124), in this exact order:

   | # | Value | Field in the live schema | Format |
   |---|---|---|---|
   | 1 | Issuer PIB/JMB | `Seller/@IDNum` | digits as sent |
   | 2 | Date and time of issue | `Invoice/@IssueDateTime` | exactly as sent, including offset, for example `2019-06-12T17:05:43+02:00` |
   | 3 | Invoice ordinal number | `Invoice/@InvOrdNum` | integer without leading zeros |
   | 4 | Business unit code | `Invoice/@BusinUnitCode` | |
   | 5 | TCR code | `Invoice/@TCRCode` | |
   | 6 | Software code | `Invoice/@SoftCode` | |
   | 7 | Total price | `Invoice/@TotPrice` | exactly as sent, two decimals, for example `99.01` |

2. **Encode** the string as UTF-8. All seven values are ASCII, so UTF-8 and ASCII bytes are identical; the specification's own sample code uses ASCII.
3. **Sign** the bytes with the issuer's private key: RSASSA-PKCS1-v1_5 with SHA-256. Write the signature as hexadecimal. This is `IICSignature` (512 hex characters for a 2048-bit key, S-08).
4. **Hash** the raw signature bytes (not their hex text) with MD5. Write the 16 bytes as 32 hexadecimal characters. This is `IIC`.

Notes:

| ID | Note | Source |
|---|---|---|
| BR-I1 | The specification's parameter list calls value 3 "Broj računa" and points at the invoice-number section, but its worked example uses `9952`, the QR parameter is `ord` ("redni broj računa") and all reference implementations use the ordinal. The ordinal is correct. | [D-01 §4.3.2.1, §3.7.7], [R-01], [R-04] |
| BR-I2 | The worked example contains typing errors: the concatenated string is printed once with `bb123bb1231` and `cc123cc1231` (v02 also printed `199.01`; v5 corrected only the price). Recomputing with the specification's sample key shows that the printed signature and IIC belong to the input `12345678\|2019-06-12T17:05:43+02:00\|9952\|bb123bb123\|cc123cc123\|ss123ss123\|99.01`, which yields IIC `E4033D471FEEA47A3C664B15C669C709`. Verified by recomputation on 2026-11-13; v5 prints the same IIC. | [D-01 §4.3.2.1 to §4.3.2.3], [D-07 §4.3.2] |
| BR-I3 | Hex case: the specification prints upper case. The schema accepts both cases. Use upper case everywhere (XML, QR, print) so that string comparison is stable. Whether the verification application is case sensitive is UNVERIFIED. | [D-01 §4.3.2], S-08 |
| BR-I4 | The values signed must be byte-identical to the attribute values sent. Build both from the same already-formatted strings; never format twice. | derived from [D-01 §4.3.2] |
| BR-I5 | The IKOF is computed once, when the invoice is issued, and never changes. When an invoice is re-sent after a certificate renewal, the request is signed with the new key while `IIC` and `IICSignature` stay as originally computed. | [D-01 §4.3], [D-02 §3.4] |
| BR-I6 | The software must have a screen where the seven parameters are entered and the IKOF is recomputed with the taxpayer's key, so that an inspector can check a printed IKOF. This is required by the rulebook as well as by the specification. | [D-11 Prilog 2], [D-08 §5.11], [D-07 §4.3.2] |
| BR-I7 | Because RSASSA-PKCS1-v1_5 is deterministic, the same input and key always give the same IKOF. This makes the algorithm testable with fixed vectors. | property of the algorithm |

## 5. JIKR (FIC)

| ID | Rule | Source |
|---|---|---|
| BR-J1 | JIKR is the `FIC` element of `RegisterInvoiceResponse`, a UUID generated by CIS. It proves registration and must be stored with the invoice and printed on it. | [D-02 §3.2], [O-02] |
| BR-J2 | An invoice that could not be registered is still issued with the IKOF only (no JIKR) and must be registered later with the same IKOF. | [D-02 §3.2], [D-04 Art. 17] |
| BR-J3 | An IKOF must be unique and is sent **only once**; the software must not send a message with the same IKOF again except as a resend under the rules of section 12. What the live service answers to a second registration of the same IKOF (same FIC, or an error) is UNVERIFIED (Q-09). The client must treat "response lost" as "unknown" and resend the identical message with `SubseqDelivType` set. | [D-08 §3.1, §3.7] |

## 6. Verification URL and QR code

| ID | Rule | Source |
|---|---|---|
| BR-Q1 | Every invoice carries a QR code holding a URL, also when the invoice has not been registered yet. | [D-01 §3.7.7], [D-02 §5.6] |
| BR-Q2 | URL = base + `?iic=…&tin=…&crtd=…&ord=…&bu=…&cr=…&sw=…&prc=…`, parameters in this order: IIC, issuer TIN, `IssueDateTime`, `InvOrdNum`, `BusinUnitCode`, `TCRCode`, `SoftCode`, `TotPrice`. | [D-07 §3.7.7 Table 160] |
| BR-Q3 | Base for test: `https://efitest.tax.gov.me/ic/#/verify`. | [D-01 §3.7.7], OBSERVED [O-06] |
| BR-Q4 | Base for production: `https://mapr.tax.gov.me/ic/#/verify`. Specification v4.1 changed it from `https://efi.tax.gov.me/ic/#/verify`, which is why the 2020 documents name the old host. OBSERVED: the old path returns 404 and the `mapr` host serves the application. Keep the base configurable. | [D-07 version table and §3.7.7], [O-07] |
| BR-Q5 | Error correction level M or higher. | [D-01 §3.7.7] |
| BR-Q6 | The specification's example (also in v5) leaves `+` and `:` in `crtd` unencoded. R-01 percent-encodes each value. Which form the verification application expects is UNVERIFIED (Q-06). | [D-07 §3.7.7], [R-01] |
| BR-Q7 | A buyer can verify an invoice for 90 days. The application distinguishes: found; not found but issued less than 48 hours ago (issuer still has time); not found and older than 48 hours (buyer may report). | [D-04 Art. 22], [D-02 §5.6] |
| BR-Q8 | The prescribed size of the printed QR code is at least **21 mm × 21 mm**. | [D-08 §5.10] |

## 7. Invoice number and sequence

| ID | Rule | Source |
|---|---|---|
| BR-N1 | `InvNum` = `BusinUnitCode/InvOrdNum/year/TCRCode`. | S-10, OBSERVED |
| BR-N2 | The fiscal invoice number consists, for cash **and** for non-cash payment, of the numeric marks of: the invoice (ordinal), the business unit, the calendar year of issue, and the TCR. The ordinal is a continuous sequence that starts at 1 at the beginning of each calendar year and runs to n at its end, "for each business unit, or for each TCR in the business unit". (The 2019 text had no year for cash and no TCR for non-cash; the 2021 amendment aligned both, which matches the live `InvNum` pattern.) | [D-05 Art. 9], [D-01 §3.7.1.11] |
| BR-N3 | Scope of the sequence: one sequence per TCR per calendar year satisfies the law's wording and makes `InvNum` unique, because the TCR code is part of the number. Cash and non-cash invoices of one TCR share that sequence under this reading; the law does not separate them. Keep the scope an explicit configuration value (Q-08, now low risk). | [D-05 Art. 9] |
| BR-N4 | The year in `InvNum` is the year of `IssueDateTime` in Montenegrin local time. R-01 uses the year of a separate "tax date". UNVERIFIED which one CIS checks. | [D-04 Art. 9], [R-01] |
| BR-N5 | No gaps and no duplicates. The ordinal must be allocated transactionally together with the stored invoice, before the network call, and never reused when the call fails. | [D-04 Art. 9], [D-02 §3.4] |

## 8. Invoice kinds

`TypeOfInv` (cash or non-cash) and `InvType` are independent attributes, both required.

| ID | Rule | Source |
|---|---|---|
| BR-K1 | `TypeOfInv` is `CASH` or `NONCASH`. The law defines cash payment as payment made at the moment the fiscal invoice is issued, with money or with a payment card; non-cash payment is a transfer by a taxpayer to another taxpayer's account at a financial institution, and every other payment that is not cash. The specification makes this mechanical: a `CASH` invoice **must contain `BANKNOTE` or `CARD`** among its payment methods, and a `NONCASH` invoice **must contain neither**. A consumer paying by card is therefore a **cash** invoice. | [D-05 Art. 3 items 4 and 5], [D-08 §3.4] |
| BR-K2 | Payment methods (live enumeration): `BANKNOTE`, `CARD`, `BUSINESSCARD`, `SVOUCHER`, `COMPANY`, `ORDER`, `ADVANCE`, `ACCOUNT`, `FACTORING`, `OTHER`, `OTHER-CASH`. Schema descriptions are in `message-catalog.md`. | [O-02] |
| BR-K3 | Payment method and allowed `TypeOfInv`: `BANKNOTE` (notes and coins) → `CASH`; `CARD` (bank credit or debit card **issued to a natural person**) → `CASH`; `OTHER-CASH` → `CASH`; `BUSINESSCARD` (bank card issued to a taxpayer) → `NONCASH`; `SVOUCHER` → `NONCASH`; `COMPANY` → `NONCASH`; `ADVANCE` → `NONCASH`; `ACCOUNT` (bank transfer) → `NONCASH`; `FACTORING` → `NONCASH`; `OTHER` → `NONCASH`; `ORDER` (not yet paid, will be paid by summary invoice) → `CASH` and `NONCASH`. The schema does not express this; enforce it locally. Open detail: a payment gateway usually cannot tell a consumer card from a company card (Q-29). | [D-07 §3.7.1 Table 90] |
| BR-K4 | Several payment methods on one invoice: 1 to 10 `PayMethod` elements, each type at most once (S-13). The sum of `Amt` should equal the amount to pay; this arithmetic check is not stated by v02 and is UNVERIFIED. | [O-02] |
| BR-K5 | Attributes of a payment method: `CompCard` is the company card number when the method is `COMPANY`; `Vouchers` holds the serial numbers when the method is `SVOUCHER` (several vouchers may pay one supply, one voucher cannot be split over several); `AdvIIC` is the IKOF of the advance invoice and is given when the method is `ADVANCE` or `SVOUCHER`; `BankAcc` is the bank account to which the approved amount of a credit note is paid out. | [D-07 §3.7.1 Tables 92 to 95], [D-08 §3.5] |
| BR-K6 | `InvType` (introduced 26.5.2021, mandatory since 1.10.2021): `INVOICE` is the default, for regular turnover; `CORRECTIVE` corrects or cancels an already fiscalized invoice, including one that failed the deferred checks; `SUMMARY` and `PERIODICAL` are informative reports that reference other invoices; `ADVANCE` records an advance payment or the sale of a payment instrument; `CREDIT_NOTE` records a later reduction across invoices. `IICRefs` is mandatory for `SUMMARY`, `PERIODICAL` and `CREDIT_NOTE`; `Approvals` is mandatory for `CREDIT_NOTE`. `IsSimplifiedInv`, `SumInvIICRefs` and the voucher list inside an item are no longer used since 1.10.2021. | [D-07 §3.7.1 Tables 48, 49 and following], [D-08 §3.4] |
| BR-K7 | Summary invoice (`SUMMARY`): references **only** invoices whose payment method was `ORDER`, in `IICRefs/IICRef` with `IIC`, `IssueDateTime`, `Amount`. It contains the issuer data, the references, `SameTaxes` whose values are the sums over the referenced invoices (`NumOfItems` is then the number of referenced invoices with that rate), totals equal to the sums, all with two decimals, and the payment method used at the moment of payment. Buyer is optional (when given, all referenced invoices are for that buyer); items are optional; the period is arbitrary. It is informative: the turnover was recorded by the individual invoices. Corrections: correct the individual invoice first; a corrective summary then lists the originals and the correctives. | [D-08 §5.6], [D-09 examples 2, 4, 5] |
| BR-K8 | Periodic invoice (`PERIODICAL`): informative; buyer mandatory; covers at most one calendar month, stated as the tax period (`TaxPeriod`, for example `05/2021`); references the individual invoices in `IICRefs`; `SameTaxes` and totals are sums; it has **no payment method**; it must not reference summary or periodic invoices; items optional. Successive (monthly) supplies show the accounting period in `SupplyDateOrPeriod`, start and end in the same month. | [D-08 §3.4, §5.7], [D-07 §3.7.1 Tables 87, 88, 100] |
| BR-K9 | Advance (`ADVANCE`): an invoice is issued for every advance payment received before the supply. Flow: (1) the advance invoice, with VAT; when the future goods are known the items carry their own rates, when the payment is for an instrument usable for the whole assortment the whole amount is at the general rate and each instrument is one item with its serial number and expiry date; (2) when the supply takes place, a normal invoice whose `PayMethod` is `ADVANCE` (or `SVOUCHER`) with the amount and `AdvIIC` = IKOF of the advance invoice; (3) a **corrective invoice that reduces the advance invoice** by the delivered value, referencing the advance invoice. A refund of an unused advance is a fully negative corrective of the advance invoice. An advance invoice can be corrected like any other. | [D-06 Art. 31(2)], [D-08 §5.8], [D-09 example 1] |
| BR-K10 | Self-issued invoice (the buyer issues on behalf of the seller): `TypeOfSelfIss` = `AGREEMENT` (prior agreement of the parties), `DOMESTIC` (purchase from domestic farmers), `ABROAD` (services from abroad), `OTHER`; the live schema also has `SELF`. The synchronous check 54 requires the **buyer's** `IDType` to be `TIN` when `TypeOfSelfIss` is present, and the seller's otherwise, which indicates that the fiscalizing taxpayer is then the buyer. Whose TIN enters the IKOF and the certificate check is not spelled out in v5; UNVERIFIED (Q-11). | [D-07 §3.7.1 Table 53, §3.7.3 Table 159], [O-02] |
| BR-K11 | Reverse charge: `IsReverseCharge` is optional and, since v4.1, must always be sent as `false` (or omitted): "reverse charge is currently not subject to fiscalization". | [D-07 §3.7.1 Table 76] |
| BR-K12 | Foreign currency: `Currency/@Code` and `@ExRate` (EUR per one unit of the foreign currency). All amount attributes stay in EUR ("in the country currency"). | [O-02], S-17 |
| BR-K13 | Bad debt: `BadDebtInv` (`IICRef`, `IssueDateTime`) references the original invoice that the current invoice declares uncollectible; the invoice is marked `IsBadDebtInv` and prints the note "uncollectible debt"; its values are negative. Not needed for a web shop. | [D-07 §3.7.1 Tables 77, 152, 153], [D-08 §7.3.1] |
| BR-K14 | Export: `GoodsExAmt` is the total of exported goods, without VAT. Tax-free amount: `TaxFreeAmt`. Mark-up: `MarkUpAmt` (special margin schemes). Paragon block number: `ParagonBlockNum` when an invoice first written in the bound book is entered later. | [D-01 §3.7.1], [O-02] |
| BR-K15 | Buyer: mandatory by law on non-cash invoices between taxpayers (name, address, tax number are part of the invoice content). When `Buyer` is present the schema requires `IDType`, `IDNum`, `Name`. For a domestic taxpayer `IDType="TIN"`. | [D-04 Art. 7], S-16 |
| BR-K16 | `OperatorCode` is required on every invoice. The operator code is tied to the personal number of the person who issues invoices, is reported through the SEP, is never reassigned to another person, and changes are reported within 24 hours. For a natural-person taxpayer or a vending machine the taxpayer is the operator. For automated issuing (a web shop) the taxpayer registers an operator for that purpose. | [D-11 Prilog 1], [D-08 §4], [O-02] |
| BR-K17 | `TCRCode` is required on every invoice. The 2021 amendment changed the law accordingly: the fiscal service is used by "the electronic payment device for cash and for non-cash payment", and the non-cash invoice number now contains the TCR mark. v02's statement that non-cash invoices carry no TCR code is obsolete. A web shop therefore registers a TCR; the same TCR may issue both cash and non-cash invoices (official FAQ). | S-11, [D-05 Art. 3 item 7, Art. 9], [D-13] |
| BR-K18 | Credit note (`CREDIT_NOTE`): mandatory `IICRefs` (IKOF and amount of every affected invoice) and `Approvals`, one `Approval` per VAT rate or exemption with `DiscountAmt` and/or `ReturnAmt` (base without VAT), `VATRate`, `ExemptFromVAT`, `VATAmt` and mandatory `TotalAmt`; totals are negative. Optional `BankAcc` on the payment method for the refund, optional advance IKOF to which the amount is added. `Note` (200 characters) can tell the buyer to correct input VAT. The examples annex instead shows a later discount over several invoices as a summary invoice followed by a corrective with a negative "discount" item; both routes exist in the official documents. | [D-08 §5.9], [D-07 §3.7.1 Tables 80, 143 to 148], [D-09 example 6] |
| BR-K19 | Payment instruments: `SVOUCHER` is an instrument whose issue involves a payment (or a return of paid goods); its issue is recorded with an advance invoice in which each instrument is one item with `VD` (expiry date) and `VSN` (serial number). `COMPANY` is an instrument issued without payment; its issue is not recorded. When either is used to pay, the method and the serial or card number are given, and for `SVOUCHER` also the IKOF of the advance invoice. | [D-08 §3.5], [D-07 §3.7.1 Tables 90, 120, 121] |

## 9. VAT

| ID | Rule | Source |
|---|---|---|
| BR-V1 | `IsIssuerInVAT` must equal the issuer's status in the VAT register (error 44). | [D-01 §3.7.3, §3.8.2] |
| BR-V2 | Issuer in the VAT system: `SameTaxes` must be present and `TotVATAmt` given. Issuer not in the VAT system: `SameTaxes` and VAT attributes must be absent. A violation is reported as XSD error 11 although the schema itself allows both. | [D-01 §3.7.3 Table 136] |
| BR-V3 | Per item: `VR` (rate in percent), `VA` (VAT amount), or `EX` (exemption code) when exempt. Per rate: one `SameTax` with `NumOfItems`, `PriceBefVAT`, and either `VATRate` + `VATAmt` or `ExemptFromVAT`. | [O-02], S-15 |
| BR-V4 | VAT rates in the current VAT law: general rate **21 %**; reduced rates **7 %** and **15 %** (each for an enumerated list of goods and services); **0 %** for exports and the other cases of the zero-rate article. v02 lists only 0, 7 and 21. The schema does not restrict the rate. Rates are data supplied by the caller, never constants in the library; the lists of goods per rate change by law. | [D-06 Arts. 24, 24a, 25], [D-02 §7.3] |
| BR-V5 | Exemption codes (live enumeration): `VAT_CL3`, `VAT_CL4`, `VAT_CL14`, `VAT_CL15`, `VAT_CL17`, `VAT_CL20`, `VAT_CL26`, `VAT_CL27`, `VAT_CL28`, `VAT_CL29`, `VAT_CL30`, `VAT_CL44`. The number is the article of the VAT law on which the non-charging of VAT rests; the schema descriptions match the article headings (3 subject of taxation, 4 supply of goods, 14 place of taxation, 15 place of supply of goods, 17 place of supply of services, 20 tax base, 26 public-interest exemptions, 27 other exemptions, 28 import exemptions, 29 temporary import, 30 special exemptions, 44 travel agencies). The VAT law requires an invoice without VAT to refer to the article that provides the zero rate or exemption. There is no code for Article 25 (zero rate); how a zero-rated supply is flagged is UNVERIFIED (`GoodsExAmt` covers exported goods). | [O-02], [D-06 Art. 32(3) and article headings] |
| BR-V6 | Non-VAT seller: `IsIssuerInVAT="false"`, item prices before and after VAT equal, no `VR`, no `SameTaxes`. | [D-01 §3.7.3] |

## 10. Amounts, rounding and consistency

| ID | Rule | Source |
|---|---|---|
| BR-A1 | Formatting: totals with exactly two decimals; item amounts with two to four; quantity up to three; decimal point; no thousands separator; no exponent (S-03, S-04, S-05). | [O-02] |
| BR-A2 | Unit prices and rates are never negative; quantities and amounts may be (S-06). | [O-02] |
| BR-A3 | Schema-documented identities: `UPA` "is calculated as PA/Q"; `PB` is unit price before VAT times quantity; `PA` is the price after VAT for the line. | [O-02] |
| BR-A4 | CIS runs deferred checks after accepting the invoice, usually overnight. Amount checks: total of all items per tax rate, total VAT, total without VAT, total with VAT, total with VAT of the items, tax base, VAT, unit price with VAT. Business checks: issuer status, valid issuer TIN, valid TCR, business unit, operator and software codes, corrective reference, first deposit, valid voucher, existing VAT rate, summary invoice, unique message UUID. A failed invoice appears on the SEP with an error code and the taxpayer must send an `ERROR_CORRECTIVE` **within 8 days**. The formulas are not published (Q-14). | [D-07 §3.7.3], [D-08 §3.6.2, §5.5] |
| BR-A5 | Rounding as far as v5 states it: within the items every amount must use the **same number of decimals** (if one item value has four, all have four); values are rounded to two decimals only after grouping per tax rate and for the totals, and that rounding is checked with a **tolerance of EUR 0.01**. The rounding mode is not stated. R-01 rounds half away from zero and derives `UPA = UPB × (1 + VR/100)` to four decimals, `PB = UPB × Q`, `PA = UPA × Q`, `VA = PA − PB`; this is behaviour of a library used in production, UNVERIFIED as a rule. Item attribute `RR` states whether the calculation base of the item is the unit price without discount and VAT. | [D-07 §3.7.1 "Stavke računa" and Table 130], [R-01] |
| BR-A6 | Recommended internal identities to enforce before sending (derived from the list of deferred checks in BR-A4; the exact CIS formulas are UNVERIFIED): Σ item `PB` = `TotPriceWoVAT` and Σ item `VA` = `TotVATAmt` within 0.01 after rounding to two decimals; `TotPriceWoVAT + TotVATAmt` = `TotPrice`; per rate, `SameTax` values equal the sums of the items with that rate and `NumOfItems` equals their count; Σ `PayMethod/@Amt` = `TotPrice`. `TotPriceToPay` is purely informative (what the buyer pays after reductions that do not affect the tax base) and takes no part in the checks. | derived, [D-07 §3.7.1 Table 66] |
| BR-A7 | A web shop stores VAT-inclusive prices. The calculation must therefore support both directions (from net and from gross) and must define where the rounding difference lands. This is a design decision recorded in `architecture.md` §2.6. | derived |
| BR-A8 | v02 listed error 40, "amount too large for a cash invoice". The v5 error table no longer contains code 40 and no cash limit is stated in the specifications read; whether the service still enforces one is UNVERIFIED (Q-15). | [D-01 §3.8.2], [D-07 §3.8.2 Table 169] |

## 11. Corrective invoices

| ID | Rule | Source |
|---|---|---|
| BR-C1 | A registered invoice cannot be deleted or changed. It is corrected by a corrective invoice that references the original IKOF. | [D-02 §5.5], [D-04 Art. 21] |
| BR-C2 | Full reversal: same items, all quantities and amounts negative, unit prices stay positive; then, if needed, a new correct invoice. | [D-08 §5.5], [D-09 example 3], S-06 |
| BR-C3 | The original must be registered before the corrective invoice is sent. | [D-02 §5.5] |
| BR-C4 | `InvType="CORRECTIVE"` with `CorrectiveInv` (`IICRef`, `IssueDateTime` of the original, `Type`). `Type="CORRECTIVE"`: the original was fiscalized correctly and is changed for a business reason (return, wrong quantity). `Type="ERROR_CORRECTIVE"`: only for an original that failed the deferred checks; it cancels the **whole** original and carries all items again with the correct values, within 8 days. | [D-07 §3.7.1 Table 75], [D-08 §5.5] |
| BR-C5 | Partial correction: only the affected item is sent, with negative quantity and amounts; the examples annex shows this on a non-cash invoice. A corrective invoice references exactly one original IKOF. A reduction that spans several invoices is made with a credit note (BR-K18) or, in the annex's example, a summary invoice followed by its corrective. A cash refund is accompanied by a `WITHDRAW` message (official FAQ; consistent with BR-D3). | [D-09 examples 4 and 6], [D-08 §5.5, §5.9], [D-13] |
| BR-C6 | A corrective invoice is itself a new invoice: it takes the next ordinal, its own IKOF and JIKR. | [D-02 §5.5] |
| BR-C7 | Corrections are possible during the retention period, five years from the end of the year of issue. | [D-02 §6.8] |

## 12. When the invoice cannot be registered

| ID | Situation | Rule | `SubseqDelivType` | Source |
|---|---|---|---|---|
| BR-O1 | Temporary loss of internet | Issue the invoice with IKOF, without JIKR. Restore the connection and deliver within two days (48 hours) from the interruption. If that is impossible, notify the Tax Administration. | `NOINTERNET` | [D-05 Art. 17], [D-02 §3.2] |
| BR-O2 | The fiscal service (TCR or software) is out of order | Write invoices in the bound book certified by the Tax Administration; restore the service within five days or notify the Tax Administration on the prescribed form; deliver the invoices electronically within five days. `ParagonBlockNum` carries the book number. | `BOUNDBOOK` | [D-05 Art. 18] |
| BR-O3 | The Tax Administration's service fails or does not answer | Issue with IKOF, resend later. | `SERVICE` | [D-01 §3.7], [O-02] |
| BR-O4 | Technical error on the issuer's side (for example a blocking validation error that needs a software fix) | Fix within 48 hours and resend. | `TECHNICALERROR` | [D-02 §3.4], [O-02] |
| BR-O5 | Area with no internet, with prior approval | Work in offline mode; deliver the month's invoices by the 10th of the following month, on portable media or by SEP upload; load the responses within five days. | `NOINTERNET` | [D-04 Art. 19], [D-02 §5.4], [D-01 §3.7.6] |
| BR-O6 | Business reasons | "Subsequent sending conditioned by the way of doing business". v5 spells the value `BUSINESSNEED`; the live schema has `BUSINESSNEEDS`, which is what must be sent. No further conditions are given. | `BUSINESSNEEDS` | [D-07 §3.7.1 Table 47], [O-02] |

Common rules:

| ID | Rule | Source |
|---|---|---|
| BR-O7 | A resent invoice has identical content and identical IKOF. Only the header changes: new `UUID`, new `SendDateTime`, and `SubseqDelivType` set. `IssueDateTime` stays the original time. The request signature is recomputed. | [D-02 §3.4], [D-01 §3.7.6] |
| BR-O8 | New invoices take priority over the backlog; resending must not delay live issuing. | [D-02 §3.4] |
| BR-O9 | Client clock: a `SendDateTime` that differs from server time by more than six hours, or lies in the future, is rejected (error 2). The same six-hour window applies to `ChangeDateTime` of a cash deposit (error 49). For a late delivery the header time is the actual sending time, so the check is on the header, not on `IssueDateTime`. | [D-01 §3.4, §3.8.2] |
| BR-O10 | Blocking errors: message too large; schema-invalid or missing mandatory data (including more than 1000 items, which must be split over several invoices); `IsIssuerInVAT` not matching the register; **invalid electronic signature**. With any of these the invoice must **not** be issued to the buyer, and the software must prevent them with automatic checks at the moment the invoice is created. Every other error (system, technical, certificate) does not stop issuing: the invoice goes out without JIKR, the cause is fixed within 48 hours and the same content is resent. | [D-08 §3.6.1] |
| BR-O11 | Offline export format: one file per invoice named `<yyyyMMddHHmmSS>_<TCRCode>_<IIC>_request.xml` containing only the signed body content with `SubseqDelivType` set; files zipped as `<yyyyMMddHHmmSS>_<TCRCode>_request.zip`, at most 15 MB; responses come back as `_response.xml` in a `_response.zip`. | [D-01 §3.7.6] |
| BR-O12 | The law's deadline is "two days"; the specification says 48 hours. Treat 48 hours from `IssueDateTime` as the deadline and surface invoices approaching it. | [D-04 Art. 17], [D-02 §3.2] |

## 13. TCR registration

| ID | Rule | Source |
|---|---|---|
| BR-R1 | Every TCR that issues cash **or non-cash** invoices is registered before its first invoice, **at least one hour before** it starts fiscalizing, through the SEP or with `RegisterTCRRequest`; CIS returns `TCRCode`. The taxpayer, the business unit and the operator must already be registered on the SEP (law: business unit data at least 24 hours before use), and the software and maintainer codes must be active (errors 41, 42, 43). | [D-08 §5.2], [D-07 §3.5], [D-05 Art. 14], [D-11 Arts. 8, 9] |
| BR-R2 | Fields: `IssuerTIN`, `BusinUnitCode`, `TCRIntID` (the taxpayer's own ordinal of the device, starting from 1; 1 to 50 characters), `SoftCode` and `MaintainerCode` (optional in the schema, **mandatory for a new registration**), `ValidFrom`, `ValidTo`, `Type` (`REGULAR` or `VENDING`). | [D-07 §3.5.1 Table 8], [D-08 §5.2], [O-02] |
| BR-R3 | Sending the request again with the same `TCRIntID` and business unit returns the same `TCRCode` and may only change `ValidTo` and the maintainer code (error 48 otherwise). | [D-01 §3.5] |
| BR-R4 | Deregistration: send `ValidTo` = today. A deactivated TCR cannot be changed again (error 57). `ValidFrom` and `ValidTo` cannot be in the past, and `ValidTo` cannot precede `ValidFrom` (errors 45, 46, 47). | [D-01 §3.5 Table 25] |
| BR-R5 | The TCR code is permanent for the device. It must be stored; it is needed for every invoice, cash deposit, IKOF and QR code. | [D-02 §5.2] |
| BR-R6 | Invoices entered manually on the SEP carry no TCR or software code; this is the Tax Administration's fallback for small non-VAT taxpayers and is out of MonteFiskal's scope. | [D-02 §3.1, §5.4.1] |
| BR-R7 | Software and maintainer codes. The law: before putting a software solution on the market or maintaining it, the producer and the maintainer send their name and seat, the software name and their TIN electronically; the Tax Administration **assigns the identification mark automatically**; the producer must build it into the software (fine EUR 4,000 to 20,000 for a legal entity otherwise) and the maintainer gives its mark to the taxpayer; the taxpayer reports producer and maintainer before first use. The mark has ten alphanumeric characters; CIS compares `SoftCode` with its database of registered software and `MaintainerCode` with the register of producers (errors 42, 43). Official FAQ (2021): the producer or maintainer **must be registered as a legal entity or entrepreneur in Montenegro or have a local partner that represents it**; **a company that uses the software and owns it (copyright not required) may register it itself**; **every upgrade that changes functionality must be registered and receives a separate mark**, which must be built in and sent on invoices, and the taxpayer must register that version as used on its TCRs; several software solutions may be registered. Access instructions: the producer first registers as a taxpayer in ROF, then signs the producer-and-software request on the SEP (register RSPOS); new versions are registered there; the maintainer registers after the producer; the whole procedure can be rehearsed on the test SEP. The SEP shows the producer its products, versions, maintainers and the taxpayers using each version. How this applies to open-source software is derived, not stated (Q-26). | [D-12 Arts. 15, 16, 27], [D-11 Art. 10], [D-08 §4, §5.2, §6.2], [D-13], [D-14 §3.2, §3.3, §4], [D-15] |

## 14. Cash deposit

| ID | Rule | Source |
|---|---|---|
| BR-D1 | The taxpayer notifies the Tax Administration of the cash deposit for **each cash TCR, each working day (00:00:00 to 23:59:59), before the first invoice**, with `Operation="INITIAL"`; the amount may be `0.00`. The initial deposit is registered once per day, but it **may be changed until the first invoice is issued; the last value registered before the first invoice counts**. Shops open around the clock report right after midnight. How the live service combines "may be changed" with error 56 ("INITIAL already registered for the current day") is to be observed on efitest (Q-17). | [D-05 Art. 3 item 12, Art. 12], [D-07 §3.6], [D-08 §5.3, §6.3] |
| BR-D2 | Every withdrawal of cash from the TCR during the day is reported with `Operation="WITHDRAW"` when it happens; the amount cannot be zero (error 51). `INITIAL` cannot be negative (error 50). | [D-08 §5.3], [D-07 §3.6.3, §3.8.2] |
| BR-D3 | Expected cash in the TCR = initial + `BANKNOTE` sales − withdrawals. Card payments do not change it. | [D-08 §5.3] |
| BR-D4 | Response: `FCDC`, a UUID. A deposit that could not be sent is delivered later with `SubseqDelivType`. | [O-02] |
| BR-D5 | A TCR in a business unit registered **only for non-cash invoices** has no duty to send the initial deposit. A TCR in a business unit registered for cash invoices must send it before the first invoice of the day. The official FAQ gives the reason: such a unit "has no cash (`BANKNOTE`) as a payment method". The software **must have a built-in control that refuses to fiscalize a cash invoice before the day's `INITIAL` deposit is registered** for that TCR; the service answers code 58 otherwise. Consequence for a web shop: consumer card payments are `CASH` invoices (BR-K1, BR-K3), so its TCR sits in a cash business unit and needs an `INITIAL` of `0.00` every day on which it issues a cash invoice. The plugin must send it automatically. | [D-08 §5.3, §5.4.1, §6.3], [D-07 §3.8.2 code 58], [D-13] |

## 15. Checks to run before sending

The functional specification requires that blocking errors are prevented by the software ([D-08 §3.6.1]); the technical specification lists what CIS checks synchronously ([D-07 §3.4 Table 7, §3.7.3 Table 159]). MonteFiskal runs these locally, in this order, and refuses to issue when one fails.

| # | Check | Would cause |
|---|---|---|
| 1 | Message validates against the bundled XSD (structure, patterns, enumerations, limits such as 1000 items) | 11 |
| 2 | Serialized request is at most 150 kB | 1 |
| 3 | Exactly one enveloped signature over `#Request` with the algorithms of BR-S3 | 21 to 32 |
| 4 | Certificate is X.509, has a private key, is within its validity period, RSA 2048 | 33, 34, 36 |
| 5 | TIN in certificate subject equals the issuer TIN in the message | 37 |
| 6 | `SendDateTime` is the current time with offset; system clock sanity (warn when the clock is obviously wrong) | 2 |
| 7 | `IsIssuerInVAT` equals the configured VAT status; `SameTaxes` present if and only if in VAT | 44, 11 |
| 8 | Seller `IDType` is `TIN` | 54 |
| 9 | Recomputed IKOF equals `IIC`/`IICSignature`; `InvNum` parts equal `BusinUnitCode`, `InvOrdNum`, year, `TCRCode` | deferred check |
| 10 | Arithmetic identities of BR-A6 | deferred check |
| 11 | Corrective invoice: original exists locally and has a JIKR | BR-C3 |
| 12 | Cash deposit: amounts within BR-D2; a second `INITIAL` on the same day only before the day's first invoice (BR-D1) | 50, 51, 56 |
| 12a | Cash invoice: the day's `INITIAL` deposit for this TCR is registered (BR-D5) | 58 |
| 12b | Payment methods fit `TypeOfInv` (BR-K1, BR-K3); kind-specific elements present (BR-K6 to BR-K9, BR-K18) | 11 or deferred check |
| 13 | TCR registration: date rules of BR-R4 | 45, 46, 47 |

Checks 9 to 11 are MonteFiskal's own; CIS does not reject on them synchronously but covers most of them in the deferred checks (BR-A4).

## 16. Printed invoice

| ID | Rule | Source |
|---|---|---|
| BR-P1 | In addition to the content required by the VAT law, a fiscal invoice shows: the fiscal invoice number; time of issue (hour and minute); the operator code; the IKOF; the JIKR; the payment method (cash, payment card, bank transfer, other); the payment deadline when not paid at issue; the buyer's tax identification number when the supply is between taxpayers or the payment is non-cash. | [D-05 Art. 7] |
| BR-P2 | The QR code of section 6 is printed on every invoice. | [D-01 §3.7.7], [D-02 §5.6] |
| BR-P3 | The software code and TCR code are needed by anyone verifying the IKOF by hand; whether printing them is mandatory is UNVERIFIED. The annexes of the rulebook on message form (which would fix such details) are not part of the published file (N-05). The operator code is "shown on the fiscal invoice". | [D-08 §5.11], [D-11 Prilog 1] |
| BR-P4 | Delivery form: a **cash invoice must be printed on paper and handed to the buyer**. A non-cash invoice may be issued on paper or electronically, at the seller's choice, electronic form requiring the buyer's consent; a valid non-cash invoice carries JIKR, IKOF and QR code either way. For a web shop this means that an order paid by consumer card or on delivery (cash invoice) needs a paper invoice, for example in the parcel; e-mail alone is sufficient only for non-cash invoices with consent. The 2021 FAQ still says the seller must hand over the fiscalized invoice "in paper form" on delivery; v5 is newer and allows the electronic form for non-cash invoices. How a seller of purely digital goods hands over a paper cash invoice is not addressed (Q-28). | [D-08 §5.4.2, §6.5], [D-06 Art. 32(5)], [D-13] |

## 17. Retention and inspection

| ID | Rule | Source |
|---|---|---|
| BR-X1 | Retention: the VAT law requires issued and received invoices, documents on invoice corrections and related records to be kept **at least five years after the end of the year they relate to** (twenty years for documents on real estate). The functional specification uses the same five years for corrections. The project's decision document assumes ten years; that is a conservative project choice, not a requirement found in the retrieved texts. The archive must not delete automatically; the period is configurable with a default of ten years. | [D-06 Art. 54], [D-02 §6.8] |
| BR-X2 | What to keep: for every invoice, cash deposit and TCR registration, the exact signed request XML as sent, the response XML as received, and the status. Offline export needs the original signed body unchanged (BR-O11). | [D-01 §3.7.6], [D-02 §5.4] |
| BR-X3 | Mandatory reports for the inspector, in structured form, printable (receipt roll or A4) and exportable, each showing TCR, business unit and operator codes: (1) fiscal state cut (current turnover); (2) daily fiscal report at the end of the day (00:00 to 23:59:59); (3) periodic fiscal turnover report; (4) periodic analytic report with items, the "electronic journal"; (5) journal of invoices issued offline (not fiscalized) with items and daily subtotals; (6) journal of corrective invoices with items and daily subtotals. | [D-08 §5.12] |
| BR-X4 | IKOF re-creation screen for the inspector (BR-I6). | [D-08 §5.11], [D-11 Prilog 2] |
| BR-X5 | Penalties (current text): Article 26, EUR 8,000 to 40,000 for a legal entity; Article 27, EUR 4,000 to 20,000 (item 6: a producer that does not build the identification mark into the software); Article 28, EUR 3,500 to 15,000. | [D-12 Arts. 26 to 28] |
| BR-X6 | Annexes of the message rulebook (2020 version). Ten annexes: 1 delivery of the fiscal invoice; 2 form and structure of the invoice message; 3 protocols and security mechanisms; 4 delivery when no JIKR can be assigned; 5 length and form of the JIKR; 6 model with a central information system; 7 model with signing on each TCR, including vending machines; 8 standard error messages; 9 protocols in case of errors; 10 delivery of invoices issued during an interruption of the internet connection. Points worth keeping: at most **1000 items** per invoice; a corrective invoice may total 0.00; the initial deposit may be 0.00; the response carries the JIKR as a UUID of 32 hexadecimal characters in groups 8-4-4-4-12; signature RSA-SHA256; error codes are three-digit numbers; the time-out is set by the taxpayer considering the connection and a processing time of **at most two seconds** in the Administration's system; after an error the invoice is still handed to the buyer and the corrected message is re-sent **within two days**; when re-sending, messages for invoices being issued right now have priority and the backlog goes in a quieter period; bound-book invoices are entered afterwards with their serial number. The annexes describe the 2020 design in general terms (for example one payment letter per invoice and separate numbering of cash and non-cash invoices); where they differ from v5, the v5 specification and the live schema are what the service enforces. | [D-16 Annexes 1 to 10], [D-07], [D-08] |
