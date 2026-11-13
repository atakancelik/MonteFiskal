# PHP API reference

> MonteFiskal 0.1.0, PHP 8.5 with bcmath, dom, libxml, mbstring and openssl (curl for `CurlTransport`, zip for the
> offline exchange, pdo for `PdoOutboxStore`). Composer name `montefiskal/core`, namespace `MonteFiskal`. The API mirrors the
> [.NET API](dotnet.md) method for method; both pass the same test vectors.

Published on GitHub only: add `https://github.com/atakancelik/MonteFiskal` as a Composer `vcs` repository and require
`montefiskal/core` (see the [setup guide](../setup-guide.md#php)).

## Money

Amounts, quantities and rates are **decimal strings** (`'12.10'`, `'21'`), calculated with bcmath. Passing a float is a
type error by design (BR-A5).

## Configuration

```php
new FiscalProfile(
    issuerTin: '<your TIN>',
    issuerName: '<your registered name>',
    businessUnit: '<your business unit code>',
    software: '<your software code>',
    operator: '<your operator code>',
    tcr: '<your TCR code>',
    environment: FiscalEnvironment::Test,
    issuerInVat: true,
    maintainer: '<your maintainer code>',
);
```

`MonteFiskalOptions` has the same switches as in .NET (`verificationUrlEncoding`, `text`, `duplicateIic`,
`responseSignature`, `itemDecimals`, `treatInitialAlreadyRegisteredAsSuccess`, `sendSoapAction`, `dateTimeFormat`,
`sequenceScope`, `cashLimit`, `autoInitialDeposit`, `sendTimeoutSeconds`, `deliveryDeadlineHours`), with the same
defaults.

## A durable setup

```php
<?php

declare(strict_types=1);

use MonteFiskal\Archive\FileArchiveStore;
use MonteFiskal\FiscalClient;
use MonteFiskal\FiscalProfile;
use MonteFiskal\Model\FiscalEnvironment;
use MonteFiskal\MonteFiskalOptions;
use MonteFiskal\Outbox\PdoOutboxStore;
use MonteFiskal\Security\Pkcs12CertificateProvider;
use MonteFiskal\Transport\CurlTransport;
use MonteFiskal\Transport\FiscalEndpoints;

function montefiskal(PDO $pdo, FiscalProfile $profile): FiscalClient
{
    $options = new MonteFiskalOptions();
    $store = new PdoOutboxStore($pdo);   // MySQL, MariaDB, PostgreSQL or SQLite; call $store->createSchema() once
    return new FiscalClient(
        $profile,
        Pkcs12CertificateProvider::fromEnvironment(),   // MONTEFISKAL_CERT_PATH and MONTEFISKAL_CERT_PASSWORD
        new CurlTransport(FiscalEndpoints::for($profile->environment), $options),
        $store,
        $options,
        archive: new FileArchiveStore('<folder outside the web root>'),
    );
}

echo FiscalEndpoints::for(FiscalEnvironment::Test), "\n";
```

`Psr18Transport($client, $requestFactory, $streamFactory, $endpoint, $options)` uses any PSR-18 client instead of curl.

## The client

| Method | Network | Does |
|---|---|---|
| `issueInvoice(InvoiceDraft)` | no | Validates, calculates, numbers, computes the IKOF; returns `IssuedInvoice` (`id`, `number`, `ordinal`, `iic`, `verificationUrl`, `totals`, `typeOfInv`, `warnings`). Throws `FiscalValidationException` for an invalid draft. |
| `send(string $id)` | yes | Returns `SendResult` (`state`, `fic`, `error`). Never throws for a fiscal fault. Registers the day's `INITIAL` deposit first when needed (BR-D5). |
| `issueAndSend(InvoiceDraft)` | yes | Both; `null` when not yet fiscalized. |
| `issueCorrective(CorrectionRequest)` | no | Full, partial (negative lines, BR-C5) or error-corrective (BR-C4); sent after the original's JIKR (BR-C3). |
| `registerTcr(TcrRegistration)`, `deregisterTcr($internalId, $validTo)` | yes | BR-R1 to BR-R4. |
| `registerCashDeposit(CashDeposit)` | yes | `INITIAL` or `WITHDRAW` (BR-D1). |
| `resendPending(?ResendOptions)` | yes | Resends open requests with the subsequent-delivery flag; same body and IKOF (BR-O7). Run it from cron. |
| `signForExport`, `applyImportedResponse` | no | Offline exchange with `OfflineExchange::export()` and `::readResponses()` (BR-O11). |
| `recomputeIkof(IkofParameters)`, `buildVerificationUrl(...)` | no | BR-I6, BR-Q1. |

## Model

Constructors use named arguments; every class is `readonly`.

- `InvoiceDraft(kind:, typeOfInv:, issuedAt:, buyer:, lines:, payments:, currency:, fees:, supplyPeriod:, iicRefs:, note:, …)`
- `InvoiceLine($name, $unit, $quantity, $unitPrice, priceIncludesVat:, vatRate:, exemption:, code:, rebate:)`
- `Payment(PaymentMethod $method, amount:, companyCard:, vouchers:, advanceIic:, bankAccount:)`
- `Party($idNum, $name, idType:, address:, town:, country:)`
- `CorrectionRequest($originalId, type:, lines:, payments:)`
- `CashDeposit($issuerTin, $tcr, CashDepositOperation $operation, $amount, changeDateTime:)`
- `TcrRegistration($issuerTin, $businessUnit, $internalId, software:, maintainer:, validFrom:, validTo:, type:)`

Enums use the wire values: `PaymentMethod::Card->value === 'CARD'`.

## Rendering and reports

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
use MonteFiskal\Rendering\EscPosReceipt;
use MonteFiskal\Rendering\HtmlReceipt;
use MonteFiskal\Rendering\QrCode;
use MonteFiskal\Rendering\QrImages;
use MonteFiskal\Rendering\Receipt;
use MonteFiskal\Reports\InspectorReports;
use MonteFiskal\Security\StaticCertificateProvider;
use MonteFiskal\Testing\FakeEfiEndpoint;
use MonteFiskal\Testing\TestCertificateFactory;

$profile = new FiscalProfile(
    issuerTin: '12345678',
    issuerName: 'MonteFiskal Test Only DOO',
    businessUnit: 'bb123bb123',
    software: 'ss123ss123',
    operator: 'oo123oo123',
    tcr: 'cc123cc123',
    issuerInVat: true,
);
$store = new InMemoryOutboxStore();
$client = new FiscalClient($profile, new StaticCertificateProvider(TestCertificateFactory::create($profile->issuerTin)), new FakeEfiEndpoint(), $store);
$issued = $client->issueInvoice(new InvoiceDraft(
    lines: [new InvoiceLine('Item', 'kom', '1', '12.10', priceIncludesVat: true, vatRate: '21')],
    payments: [new Payment(PaymentMethod::Banknote)],
));
$client->send($issued->id);

$receipt = Receipt::fromStored($store->get($issued->id) ?? throw new RuntimeException('missing'), testMode: true);
$html = HtmlReceipt::render($receipt, 'en');          // A4 invoice with a QR code of at least 21 mm (BR-Q8)
$escPos = EscPosReceipt::render($receipt, 80, 'en');  // 80 mm receipt printer
$svg = QrImages::svg(QrCode::encode($issued->verificationUrl), 25);

['invoices' => $invoices, 'deposits' => $deposits] = InspectorReports::fromStore($store->list(new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day')), $profile->tcr);
$csv = InspectorReports::toCsv(InspectorReports::daily(date('Y-m-d'), $profile->tcr, $profile->businessUnit, $invoices, $deposits));
echo 'html=' . (str_contains($html, $issued->iic) ? 'true' : 'false') . ' escpos=' . strlen($escPos) . ' csv=' . strlen($csv) . "\n";
exit(str_contains($html, $issued->iic) && str_starts_with($svg, '<svg') ? 0 : 1);
```

## Errors and testing

Fiscal faults are `FiscalError` values on the result (`code`, `class`, `retryable`, `faultString`, `hint`); see
[troubleshooting](../ai/troubleshooting.md). `FakeEfiEndpoint`, `FrozenClock` and `TestCertificateFactory` in
`MonteFiskal\Testing` give an offline service, a fixed clock and throw-away certificates.
