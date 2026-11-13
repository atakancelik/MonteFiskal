# Error codes

Source IDs are defined in `sources/README.md`. `BR-…` refers to `business-rules.md`.

## 1. How an error arrives

| Aspect | Specification [D-07 §3.8] | OBSERVED on efitest, 2026-11-13 [P-01] |
|---|---|---|
| HTTP status | not stated | `500` |
| Content type | not stated | `text/xml; charset=UTF-8` |
| Body | SOAP 1.1 `Fault` inside `Envelope/Body` | same; envelope prefix `env`, namespace `http://schemas.xmlsoap.org/soap/envelope/` |
| `faultcode` | `CLIENT` or `SERVER`, prefixed with the envelope prefix | `env:CLIENT` |
| `faultstring` | free text; the sample shows `xml:lang="en"` | English text followed by ` Code=<n> ResponseUUID=<uuid>`; no `xml:lang` attribute |
| `detail/code` | integer, pattern `[1-9][0-9]{0,2}` | `11` |
| `detail/responseUUID` | UUID of the fault message | present |
| `detail/requestUUID` | UUID of the request "if available" | absent when the request had no readable header |
| Signature | faults are not signed | not signed |

`faultstring`, `detail`, `code`, `requestUUID` and `responseUUID` are unqualified elements (no namespace).

Parsing rules that follow:

1. Treat any HTTP status with a parseable SOAP fault as a fiscal error; do not stop at "HTTP 500".
2. Read the numeric code from `detail/code`. Do not parse it out of `faultstring`; the text is for people and may change.
3. Keep `faultstring`, `responseUUID` and `requestUUID` with the stored attempt; the Tax Administration's support will ask for them.
4. The pattern for `code` excludes `0`, yet code 0 is listed. Accept any non-negative integer.
5. A response that is not a SOAP envelope at all (proxy error page, empty body, TLS failure, timeout) is a **transport** failure, not a fiscal error: outcome unknown, resend allowed (BR-J3).

The service returns English fault strings. The specification is written in Montenegrin, so the table gives the specification's wording as "original" and a translation.

## 2. Codes

Source of the table: [D-07 §3.8.2 Table 169] (v5), which states that it is **not an exhaustive list**; the related checks are in [D-07 §3.4 Table 7, §3.5.3 Table 25, §3.6.3 Table 42, §3.7.3 Table 159]. Differences from the 2020 table [D-01 Table 146]: code **58 is new**; code **40 is no longer listed**; code **49** is absent from the v5 code table but still named in the cash-deposit control table. Codes 40 and 49 are kept below with that remark.

Columns: **Retry** says whether sending the *identical* message again can succeed. "No" means the message or the taxpayer's registration must change first. **Class** is the grouping proposed for the typed exception model in `architecture.md`.

| Code | Origin | Original description (ME, specification) | Meaning | Typical cause | Retry | Action | Class |
|---|---|---|---|---|---|---|---|
| 0 | Client | Došlo je do izuzeća tokom ekstrakcije primljene XML poruke tokom provjere veličine. | Exception while extracting the XML during the size check. | Body is not well-formed XML or not a SOAP envelope; wrong encoding. | No | Software defect: fix serialization. | Malformed |
| 1 | Client | Primljena XML poruka premašuje dozvoljenu veličinu. | Message exceeds the allowed size (150 kB). | Too many or too long items. | No | Split the sale into several invoices; enforce the size check before issuing. Blocking: the invoice must not be issued. | Malformed |
| 2 | Client | Vrijeme klijenta razlikuje se od vremena servera u minutima više nego što je dozvoljeno (6 sati) ili je vrijeme u budućnosti. | Client time differs from server time by more than 6 hours, or is in the future. | Wrong system clock or time zone; `SendDateTime` copied from the original attempt on a resend. | After fix | Correct the clock; always set `SendDateTime` to the actual sending time. | Clock |
| 10 | Client | Izuzetak se dogodio tokom ekstrakcije primljene XML poruke tokom provjere XML-a na osnovu XSD-a. | Exception while extracting the XML during XSD validation. | As code 0. | No | Software defect. | Malformed |
| 11 | Client | Neuspješna potvrda XML poruke. | XSD validation failed. Also used for VAT-status consistency (in VAT without `SameTaxes`, or the reverse). | Invalid value format, missing attribute, schema drift between the library and the service. | No | Fix the data or update the library. The fault string names the offending node. Blocking: must not be issued. | Schema |
| 20 | Client | Izuzetak dogodilo se tokom ekstrakcije primljene XML poruke tokom provjere potpisa. | Exception while extracting the XML during the signature check. | Damaged signature structure. | No | Software defect. | Signature |
| 21 | Client | Element „potpis“ nedostaje u primljenoj XML poruci. | `Signature` element missing. | Unsigned message. | No | Software defect. | Signature |
| 22 | Client | Element „XML zahtjev“ nedostaje u primljenoj XML poruci. | Request element missing. | Empty SOAP body or unknown root element. | No | Software defect. | Signature |
| 23 | Client | Došlo je do izuzeća tokom ekstrakcije XML elementa potpisa tokom provjere potpisa. | Exception while extracting the signature element. | Malformed `ds:Signature`. | No | Software defect. | Signature |
| 24 | Client | Dostavljeno više od jednog XML elementa potpisa. | More than one signature element. | Message signed twice. | No | Software defect. | Signature |
| 25 | Client | Potpisan pogrešan XML element | Wrong element signed. | Reference URI is not `#Request`. | No | Software defect. | Signature |
| 26 | Client | Navedena pogrešna metoda potpisa klijenta. | Wrong signature method. | Not RSA-SHA256. | No | Software defect. | Signature |
| 27 | Client | Navedena pogrešna metoda kanonizacije | Wrong canonicalization method. | Not exclusive C14N. | No | Software defect. | Signature |
| 28 | Client | Navedena pogrešna metoda izvoda. | Wrong digest method. | Not SHA-256. | No | Software defect. | Signature |
| 29 | Client | Kriptografski potpis pogrešan. | Signature value does not verify. | Wrong key; message changed after signing; whitespace or namespace changes between signing and sending. | No | Send the exact signed bytes; software defect or wrong key pair. | Signature |
| 30 | Client | Pogrešan proračun izvoda. | Digest does not match. | Content changed after signing; wrong transforms. | No | As 29. | Signature |
| 31 | Client | Pogrešan cjelokupni potpis | Signature wrong as a whole. | As 29 and 30. | No | As 29. | Signature |
| 32 | Client | Postoji više ključnih informacija nego što je potrebno. | More key information than needed. | `KeyInfo` contains more than one certificate or extra elements such as `KeyValue` or the whole chain. | No | Emit only one `X509Certificate`. | Signature |
| 33 | Client | Priloženi certifikat nije certifikat vrste X509. | Certificate is not X.509. | Wrong content in `X509Certificate`. | No | Software or configuration defect. | Certificate |
| 34 | Client | Dostavljeni certifikat nije valjan. | Certificate not valid. | Not yet valid, wrong key usage, damaged. | No | Check the certificate; obtain a valid one. | Certificate |
| 35 | Client | Certifikat nije izdao Registrovani CA | Certificate not issued by a registered CA. | Self-signed, foreign or demo certificate; production certificate against test or the reverse. | No | Use a certificate from a registered Montenegrin CA for that environment. | Certificate |
| 36 | Client | Certifikat je istekao. | Certificate expired. | Expiry. | No | Renew; resend pending invoices signed with the new key and the original IKOF (BR-I5). | Certificate |
| 37 | Client | Uporediti TIN u XML-u s TIN-om u certifikatu | TIN in the XML differs from the TIN in the certificate. | Wrong seller number configured; certificate of another company. | No | Fix configuration. | Certificate |
| 38 | Client | Opozvan status certifikata | Certificate revoked. | Revocation. | No | Obtain a new certificate. | Certificate |
| 39 | Client | Status certifikata nepoznat | Certificate status unknown. | Revocation service of the CA not reachable by CIS. | Yes, later | Retry with back-off; if it persists, contact the CA. | Certificate (transient) |
| 40 | Client | Iznos računa prevelik za račun u gotovini | Amount too large for a cash invoice. **Listed in v02 only; absent from the v5 table.** | Cash invoice above a legal limit. The anti-money-laundering law forbids physical-cash payments of EUR 10,000 or more (D-19 Art. 65, Q-15); whether the service still checks anything is unknown. | No | Surface the fault string; locally refuse `BANKNOTE` payments of EUR 10,000 or more (Q-15). | Business |
| 41 | Client | Kôd poslovne jedinice ne odnosi se na aktivnu poslovnu jedinicu (prostor) poreskog obveznika. | Business unit code is not an active business unit of the taxpayer. | Typo; unit not registered or closed on the SEP. | No | Register or correct the business unit on the SEP. | Registration |
| 42 | Client | Kôd softvera ne odnosi se na aktivni softver. | Software code is not an active software. | Typo; software not registered or deactivated. | No | Correct the code; register the software. | Registration |
| 43 | Client | Kôd održavaoca ne odnosi se na aktivnog održavaoca. | Maintainer code is not an active maintainer. | Typo; maintainer not registered. | No | Correct or register. | Registration |
| 44 | Client | Status PDV-a izdavaoca ne odgovara atributu „izdavalac je obveznik PDV-a“. | Issuer's VAT status does not match `IsIssuerInVAT`. | Wrong setting; status changed in the register. | No | Fix the setting. Blocking: must not be issued. | Business |
| 45 | Client | „Važi od“ ne može biti u prošlosti. | `ValidFrom` cannot be in the past. | TCR registration with a past date. | No | Use today or later. | TCR |
| 46 | Client | „Važi do“ ne može biti u prošlosti. | `ValidTo` cannot be in the past. | As 45. | No | Use today or later. | TCR |
| 47 | Client | „Važi do“ ne može biti prije „važi od“. | `ValidTo` cannot be before `ValidFrom`. | Wrong dates. | No | Fix dates. | TCR |
| 48 | Client | Aktivni ENU nije moguće ažurirati. | An active TCR cannot be updated. | Re-registration tried to change something other than `ValidTo` or the maintainer code. | No | Send only allowed changes (BR-R3). | TCR |
| 49 | Client | Promjene u datumu i vremenu razlikuju se u minutima od vremena iz Centralnog registra računa više nego što je to dozvoljeno (6 sati). | `ChangeDateTime` differs from CIS time by more than the allowed time. In v5 the code appears only in the cash-deposit control table, without the number of hours. | Late or clock-skewed cash deposit. | After fix | Correct the clock; for a late deposit see Q-17 on how `SubseqDelivType` interacts with this check. | Clock |
| 50 | Client | Iznos gotovine za INITIAL operaciju ne može biti negativan. | Cash amount for `INITIAL` cannot be negative. | Bad input. | No | Fix amount. | CashDeposit |
| 51 | Client | Iznos gotovine ne može biti nula za operaciju WITHDRAW. | Cash amount cannot be zero for `WITHDRAW`. | Bad input. | No | Fix amount. | CashDeposit |
| 52 | Client | Poreski obveznik ne postoji u Registru poreskih obveznika. | Taxpayer does not exist in the taxpayer register. | Wrong TIN; not registered in ROF for this environment. | No | Register on the SEP of that environment ([D-03]). | Registration |
| 53 | Client | ENU kôd klijenta ne odnosi se na registrovani ili aktivni ENU ili ENU ne pripada navedenom izdavaču. | TCR code is not a registered or active TCR, or does not belong to the issuer. | Typo; TCR deregistered; code from the other environment. | No | Register the TCR or correct the code. | Registration |
| 54 | Client | Vrsta identifikacije mora biti TIN (JMB/PIB). | Identification type must be TIN. | Seller `IDType` is not `TIN`. | No | Software or configuration defect. | Business |
| 55 | Client | Poreski obveznik nije aktivan u registru poreskih obveznika. | Taxpayer is not active in the register. | Deregistered or suspended taxpayer. | No | Contact the Tax Administration. | Registration |
| 56 | Client | Gotovinski depozit s operacijom INITIAL već je registrovan za tekući dan. | An `INITIAL` cash deposit is already registered for the current day. | Sent twice; resend after a lost response. The specification also says the initial deposit may be changed until the day's first invoice (BR-D1); when exactly 56 is returned is to be observed (Q-17). | No | Treat as "already done" when the previous attempt's outcome was unknown; otherwise show it. | CashDeposit |
| 57 | Client | Deaktivirani ENU se ne može mijenjati. | A deactivated TCR cannot be changed. | Update after deregistration. | No | Register a new TCR. | TCR |
| 58 | Client | Gotovinski depozit s operacijom INITIAL nije registrovan za tekući dan. | No `INITIAL` cash deposit is registered for the current day. | A cash invoice was sent from a cash TCR before the day's initial deposit. | After fix | Register the `INITIAL` deposit (may be 0.00), then resend the identical invoice. The software must prevent this locally (BR-D5). | CashDeposit |
| 900 to 999 | Server | Izuzeci za interni server. | Internal server errors. | Fault on the CIS side. | Yes | Issue the invoice with IKOF only, queue with `SubseqDelivType="SERVICE"`, retry with back-off (BR-O3). | Server |

## 3. Codes not in the specification

The v5 list ends at 58 and says it is incomplete (N-06, Q-04). The deferred checks (section 4) report their own error codes on the SEP; those codes are not published in the documents read.

Separately, the rulebook lists six notification texts for an invalid fiscal invoice, without numbers: message not in accordance with the XML schema; certificate not issued by a certification service provider; invalid electronic signature; tax identification number in the request differs from the one in the certificate; invoice contains too many items; system error while processing the request ([D-10 Art. 3]). They correspond to codes 11, 35, 29 to 31, 37, 11 and 9xx.

Required behaviour for an unknown code:

| Range | Treat as | Retry |
|---|---|---|
| 59 to 899, unknown | permanent client error | No. Keep the invoice in a "needs attention" state and show the fault string verbatim. |
| 900 to 999 | server error | Yes, with back-off. |
| `faultcode` ends with `SERVER`, any code | server error | Yes. |

The error catalogue must be data (a table shipped with the library and overridable), not a closed enumeration, so that a newly observed code can be added without an API break. An issue template "spec-change" collects fault strings for unknown codes (see `architecture.md` §8).

## 4. Deferred errors

CIS also runs checks after it has accepted an invoice and returned a JIKR, usually overnight. The results are shown to the taxpayer on the SEP with an error code, not returned by the web service, and must be resolved **within 8 days** with a corrective invoice of type `ERROR_CORRECTIVE` that cancels the whole original and repeats all items with correct values ([D-07 §3.7.3], [D-08 §3.6.2, §5.5], BR-A4, BR-C4). The library cannot observe them. The WooCommerce plugin should say so in its documentation and provide a manual "issue error-corrective invoice" action.

## 5. Mapping to client behaviour

| Outcome | Invoice may be handed to the buyer? | Next step |
|---|---|---|
| Success, JIKR received | Yes, with IKOF and JIKR | Store response. |
| Transport failure or timeout | Yes, with IKOF only | Queue; resend identical content within 48 hours (BR-O1, BR-O7). |
| Server class (9xx) | Yes, with IKOF only | Queue with `SERVICE`. |
| Certificate status unknown (39) | Yes, with IKOF only | Queue; retry. |
| Malformed, Schema, Business code 44, size, **invalid signature** (0, 1, 10, 11, 44, 20 to 32) | **No** (blocking, [D-08 §3.6.1]) | Do not issue; these must be caught by local validation first. If the invoice was already handed over, fix the software within 48 hours and resend with `TECHNICALERROR`. |
| Certificate (33 to 38) | The specification is not consistent: errors "concerning the digital certificate" are named in the non-blocking group, while "uses a valid and correct digital certificate for signing" is among the conditions of the blocking group ([D-08 §3.6.1]). | Check the certificate locally before issuing (validity period, TIN) so that the case does not arise; if it arises anyway, stop issuing, alert the administrator, and resend pending invoices signed with the valid certificate and the original IKOF (BR-I5). |
| Missing initial deposit (58) | Not as a cash invoice: the software must block it ([D-08 §5.4.1]) | Register `INITIAL`, then send. |
| Registration, TCR, CashDeposit, other Business | Non-blocking by the general rule of [D-08 §3.6.1] (technical error) | Alert the administrator; configuration must change within 48 hours; resend. |
