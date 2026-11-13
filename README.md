<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/brand/logo-wordmark-dark.svg">
    <img src="assets/brand/logo-wordmark.svg" alt="MonteFiskal" width="420">
  </picture>
</p>

<p align="center">
  Open-source fiscalization for Montenegro's EFI service: a .NET 10 library, a PHP 8.5 core with the same API, and a WooCommerce plugin.
</p>

<p align="center">
  <a href="https://github.com/atakancelik/MonteFiskal/actions/workflows/ci-dotnet.yml"><img alt=".NET" src="https://github.com/atakancelik/MonteFiskal/actions/workflows/ci-dotnet.yml/badge.svg"></a>
  <a href="https://github.com/atakancelik/MonteFiskal/actions/workflows/ci-php.yml"><img alt="PHP" src="https://github.com/atakancelik/MonteFiskal/actions/workflows/ci-php.yml/badge.svg"></a>
  <a href="https://github.com/atakancelik/MonteFiskal/actions/workflows/ci-plugin.yml"><img alt="WooCommerce" src="https://github.com/atakancelik/MonteFiskal/actions/workflows/ci-plugin.yml/badge.svg"></a>
  <a href="https://github.com/atakancelik/MonteFiskal/actions/workflows/vectors-parity.yml"><img alt="Parity" src="https://github.com/atakancelik/MonteFiskal/actions/workflows/vectors-parity.yml/badge.svg"></a>
</p>

MonteFiskal registers invoices with the Tax Administration of Montenegro (Poreska uprava, EFI, "fiskalizacija"). It
builds the request, computes the IKOF, signs the XML, sends it, retries it until the JIKR arrives, and keeps every
request and response. Issuing is local: an invoice gets its number and IKOF even when the service is down.

> [!WARNING]
> **Preview (0.1.0).** All unit, vector, fake-service and WordPress tests pass, but MonteFiskal has **not yet been run
> against the Tax Administration's test service (efitest)**. Try it on efitest with your own certificate before you use it
> for real invoices, and please report what you see. See [project status](docs/STATUS.md).

It is **not** a hosted service, not accounting advice and not a certificate provider. You bring your own qualified
certificate and your own codes from the SEP portal. Nothing is sent anywhere except to the Tax Administration.

## What is inside

| Component | For | Install |
|---|---|---|
| [.NET library](src/dotnet/README.md) | .NET 10 applications, point of sale, ERP | NuGet `MonteFiskal` and companion packages |
| [PHP core](docs/api/php.md) | PHP 8.5 applications | `composer require montefiskal/core` |
| [WooCommerce plugin](plugins/montefiskal-woocommerce/readme.txt) | WordPress 7.1 shops with WooCommerce 11.3 | Release zip from [GitHub releases](https://github.com/atakancelik/MonteFiskal/releases) |

All three share the same test vectors, so .NET and PHP produce byte-identical requests for the same input.

| Feature | .NET | PHP | Plugin |
|---|---|---|---|
| Invoice, corrective, error-corrective, summary, periodic, advance, credit note | ✓ | ✓ | invoice, corrective, error-corrective, advance flow |
| IKOF and verification URL, XML-DSig signing, response signature check | ✓ | ✓ | ✓ |
| TCR registration, daily `INITIAL` cash deposit (automatic), `WITHDRAW` | ✓ | ✓ | ✓ |
| Durable outbox, gap-free numbering, resend with subsequent-delivery flag | EF Core | PDO | WordPress tables, Action Scheduler |
| Archive of every signed request and response, offline export and import | ✓ | ✓ | ✓ |
| QR code, A4 HTML invoice, ESC/POS receipt | ✓ | ✓ | e-mail block, printable invoice |
| Inspector reports with CSV export | ✓ | ✓ | ✓ |
| In-process fake EFI service and throw-away test certificates | ✓ | ✓ | used by its tests |

## Quick start

.NET:

```csharp
using MonteFiskal;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Security;
using MonteFiskal.Testing;

// The fake service and a throw-away certificate: no network, no secrets. Replace each value with your own.
var profile = new FiscalProfile
{
    IssuerTin = "12345678", IssuerName = "MonteFiskal Test Only DOO", IssuerInVat = true,
    BusinessUnit = "bb123bb123", Tcr = "cc123cc123", Software = "ss123ss123", Operator = "oo123oo123",
};
var client = new FiscalClient(profile, new StaticCertificateProvider(TestCertificateFactory.Create(profile.IssuerTin)),
    new FakeEfiEndpoint(), new InMemoryOutboxStore());

var issued = await client.IssueInvoiceAsync(new InvoiceDraft
{
    Lines = [new InvoiceLine { Name = "Item", Unit = "kom", Quantity = 1, UnitPrice = 12.10m, VatRate = 21, PriceIncludesVat = true }],
    Payments = [new Payment { Method = PaymentMethod.Card }],
});
var sent = await client.SendAsync(issued.Id);
Console.WriteLine($"{issued.Number} IKOF {issued.Iic} JIKR {sent.Fic}");
```

PHP:

```php
<?php

declare(strict_types=1);

use MonteFiskal\FiscalClient;
use MonteFiskal\FiscalProfile;
use MonteFiskal\Model\InvoiceDraft;
use MonteFiskal\Model\InvoiceLine;
use MonteFiskal\Model\Payment;
use MonteFiskal\Model\PaymentMethod;
use MonteFiskal\Outbox\InMemoryOutboxStore;
use MonteFiskal\Security\StaticCertificateProvider;
use MonteFiskal\Testing\FakeEfiEndpoint;
use MonteFiskal\Testing\TestCertificateFactory;

// The fake service and a throw-away certificate: no network, no secrets. Replace each value with your own.
$profile = new FiscalProfile(
    issuerTin: '12345678',
    issuerName: 'MonteFiskal Test Only DOO',
    businessUnit: 'bb123bb123',
    software: 'ss123ss123',
    operator: 'oo123oo123',
    tcr: 'cc123cc123',
    issuerInVat: true,
);
$client = new FiscalClient($profile, new StaticCertificateProvider(TestCertificateFactory::create($profile->issuerTin)), new FakeEfiEndpoint(), new InMemoryOutboxStore());

$issued = $client->issueInvoice(new InvoiceDraft(
    lines: [new InvoiceLine('Item', 'kom', '1', '12.10', priceIncludesVat: true, vatRate: '21')],
    payments: [new Payment(PaymentMethod::Card)],
));
$sent = $client->send($issued->id);
echo "{$issued->number} IKOF {$issued->iic} JIKR {$sent->fic}\n";
```

Both run as they are. For the test service (efitest) and production, follow the [setup guide](docs/setup-guide.md).

## Before you go live

1. Register the software on the SEP and get your business unit, software, maintainer and operator codes.
2. Get a qualified certificate issued to your PIB; keep it outside the repository and the web root.
3. Register the TCR (MonteFiskal can do it) and issue test invoices on efitest.
4. Walk the [go-live checklist](docs/ai/prompts/go-live.md).

Every fiscal rule MonteFiskal applies is quoted with its source in the [rule book](docs/analysis/business-rules.md).
Where the official texts are silent, the library has a documented default and a switch
([open questions](docs/analysis/open-questions.md)); your accountant decides those.

## Using an AI assistant?

Point it at [`llms.txt`](llms.txt), or paste [`docs/ai/context.md`](docs/ai/context.md) when it cannot browse. Ready-made
prompts are in [`docs/ai/prompts/`](docs/ai/prompts/README.md); each ends with a check you can run. The assistant must ask
for your codes instead of inventing them, and must never see your certificate or password.

## Documentation

- [Setup guide](docs/setup-guide.md): SEP registration, certificate, test, production, WooCommerce
- [.NET API](docs/api/dotnet.md) and [PHP API](docs/api/php.md)
- [Error codes and what to do](docs/ai/troubleshooting.md)
- [Rule book](docs/analysis/business-rules.md), [architecture](docs/analysis/architecture.md), [message catalog](docs/analysis/message-catalog.md)
- [Changelog](CHANGELOG.md), [contributing](CONTRIBUTING.md), [security](SECURITY.md), [project status](docs/STATUS.md)

## Licence

The .NET library and the PHP core are MIT ([LICENSE](LICENSE)); the WooCommerce plugin is GPL-2.0-or-later, as WordPress
requires. The official schema files under `schemas/` are the Tax Administration's and are kept unmodified
([PROVENANCE](schemas/fs-v1/2026-11/PROVENANCE.md)). See [NOTICE](NOTICE).

The software does not replace the taxpayer's legal responsibility. Test on efitest with your own certificate before
production. MonteFiskal is not affiliated with the Tax Administration of Montenegro.
