# Setup guide

From nothing to the first production invoice. The steps are the same for .NET, PHP and the WooCommerce plugin; where
they differ, the section says so. Rule IDs (`BR-…`) point to the [rule book](analysis/business-rules.md).

> MonteFiskal does not replace the taxpayer's legal responsibility. Your accountant decides the tax treatment, the
> invoicing mode for prepaid orders (Q-24) and the VAT mapping.

## 1. What you need before you start

| Item | From | Notes |
|---|---|---|
| PIB (8 digits) or JMB (13 digits for a natural person) | Company registration | Without a Montenegrin PIB there is no certificate and no SEP access. |
| VAT status | Tax registration | Decides `IssuerInVat` (BR-V1). |
| Qualified certificate for electronic signature, as a PKCS#12 file (`.p12`/`.pfx`) | A certification authority registered in Montenegro | Issued to the seller's PIB (BR-S5, BR-S7). A certificate on a token cannot be used by an unattended server. |
| Access to the SEP portal (Poreska uprava, e-services) | Tax Administration | Logged in with the certificate. |

## 2. Registrations on the SEP

Do these in order; some take effect only after a delay.

1. **Business unit.** My data, Business units. Note the ten-character code (`BusinessUnit`). A web shop registers a unit
   of type "internet trgovina". Register it for **cash** invoices if consumers pay by card: a consumer card payment is
   a cash invoice (BR-K3), and a cash unit needs the daily initial deposit (BR-D5). Register business unit data at
   least 24 hours before use (BR-R1).
2. **Operator.** My data, Operators: enter the personal number of the person responsible; note the operator code
   (`Operator`, BR-K16).
3. **Software and maintainer.** A company that uses MonteFiskal and runs its own installation registers the software
   itself and gets the software code (`Software`) and the maintainer code (`Maintainer`) (BR-R7). There is no shared
   MonteFiskal code: every installation has its own. When a MonteFiskal release says
   **"Fiscal behaviour changed: yes"**, register the new version and enter the new code.
4. **TCR (ENU).** Either on the SEP (Business units, ENU) or through MonteFiskal's `RegisterTcr` with your own internal
   ID (BR-R2). Register it **at least one hour** before the first invoice (BR-R1). The TCR code is permanent (BR-R5);
   nothing else may issue invoices on it.

Use the test environment's SEP to get test codes first; production codes are registered separately.

## 3. The certificate on the server

- Keep the `.p12` file **outside the repository and outside the web root**, readable only by the service account.
- Keep the password in an environment variable or a secret store, never in a file under version control.
- Never send the certificate or the password to anyone, including an AI assistant or an issue tracker.
- Note the expiry date and set a reminder; an expired certificate stops fiscalization (errors 33 to 39).

| Integration | Certificate path | Password |
|---|---|---|
| .NET | `MonteFiskal:Certificate:Path` in configuration | Environment variable named by `MonteFiskal:Certificate:PasswordEnvironmentVariable` (default `MONTEFISKAL_CERT_PASSWORD`), or `PasswordFile` |
| PHP | `Pkcs12CertificateProvider::fromEnvironment()` reads `MONTEFISKAL_CERT_PATH` | `MONTEFISKAL_CERT_PASSWORD` |
| WooCommerce | `define( 'MONTEFISKAL_CERT_PATH', '…' );` in `wp-config.php` | `define( 'MONTEFISKAL_CERT_PASSWORD', '…' );` in `wp-config.php` |

## 4. Install

### .NET

```sh
dotnet add package MonteFiskal
dotnet add package MonteFiskal.Extensions.DependencyInjection
dotnet add package MonteFiskal.Storage.EntityFrameworkCore   # durable outbox and numbering
```

`appsettings.json` (values are placeholders; the password is not here):

```json
{
  "MonteFiskal": {
    "Profile": {
      "Environment": "test",
      "IssuerTin": "<your TIN>",
      "IssuerName": "<your registered name>",
      "IssuerInVat": true,
      "BusinessUnit": "<your business unit code>",
      "Tcr": "<your TCR code>",
      "Software": "<your software code>",
      "Maintainer": "<your maintainer code>",
      "Operator": "<your operator code>"
    },
    "Certificate": { "Path": "<path outside the web root>.p12" }
  }
}
```

Register with `services.AddMonteFiskal(configuration.GetSection("MonteFiskal"))` and replace the in-memory outbox with
`EfOutboxStore` before production. See the [.NET API](api/dotnet.md).

### PHP

```sh
composer require montefiskal/core
```

Use `CurlTransport` (or `Psr18Transport` with your PSR-18 client) and `PdoOutboxStore` on MySQL, MariaDB, PostgreSQL or
SQLite. See the [PHP API](api/php.md).

### WooCommerce

1. Download `montefiskal-woocommerce-<version>.zip` and its `.sha256` from the
   [releases](https://github.com/atakancelik/MonteFiskal/releases) and compare the checksum.
2. Plugins, Add New, Upload Plugin; activate.
3. Add the two `MONTEFISKAL_CERT_*` constants to `wp-config.php`.
4. WooCommerce, MonteFiskal, run the setup wizard: issuer data, codes, certificate check, payment gateway mapping,
   then "Test connection" and, if needed, TCR registration.
5. Map every payment gateway (consumer card: `CARD`; bank transfer: `ACCOUNT`; cash on delivery: `BANKNOTE`) and every
   zero-rate tax class to an exemption code. An order with an unmapped gateway is not fiscalized and gets an order note.
6. Choose when to issue: at payment (default) or the advance flow (BR-K9) for pre-orders. Your accountant decides (Q-24).

Check the state at any time:

```sh
wp montefiskal status
```

The plugin issues at payment, sends through Action Scheduler, registers the daily `INITIAL` deposit of 0.00 before the
first cash invoice of the day, warns before the 48-hour deadline, and shows IKOF, JIKR and the QR code in e-mails, on the
order page and on a printable invoice. Uninstalling never deletes fiscal records (BR-X1).

## 5. Test on efitest

The test service is `https://efitest.tax.gov.me/fs-v1` (BR-T1). Keep `Environment` on test until every check passes:

1. Register the TCR (or enter the code from the SEP).
2. Issue and send one invoice of each kind you will use: cash sale, card sale, bank transfer, refund (corrective),
   partial refund, and the advance flow if you use it.
3. Open each verification link; the invoice must appear.
4. Simulate an outage (block the endpoint), issue an invoice, restore the endpoint and check that the same IKOF is
   delivered with the subsequent-delivery flag.
5. Check the printed or e-mailed invoice: number, IKOF, JIKR, QR code of at least 21 mm, operator and software codes
   (BR-P1 to BR-P4).

Some behaviour can only be confirmed on efitest (open questions Q-06, Q-07, Q-09, Q-12, Q-14, Q-17, Q-22, Q-23). Each has
a documented default and one switch in `MonteFiskalOptions` (plugin: Settings, Advanced). Leave the defaults unless the
test service rejects them, and report what you saw in a `spec-change` issue.

The repository's own efitest suite (`tests/dotnet/MonteFiskal.IntegrationTests`, workflow `efitest`) runs only when
the maintainer sets the secrets of the `efitest` environment; it never runs on pull requests.

## 6. Go live

Walk the [go-live checklist](ai/prompts/go-live.md). In short: production codes registered, production TCR registered,
production certificate installed with an expiry reminder, backups of the outbox and the sequence tables tried once,
the resend job running, the deadline alert reaching a person, and your accountant's confirmation of the invoicing mode
and the VAT mapping. Then switch `Environment` to production.

## 7. Day to day

- An invoice is never edited or deleted. Refunds are corrective invoices (BR-C1); an invoice that failed the deferred
  checks shown on the SEP is replaced by an error-corrective within 8 days (BR-C4).
- An invoice that could not be sent is still handed over with its IKOF and is resent within 48 hours (BR-J2, BR-O12).
- Keep fiscal records at least five years after the end of the year (BR-X1); MonteFiskal defaults to ten.
- For error codes see [troubleshooting](ai/troubleshooting.md).
