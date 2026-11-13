# PHP: first invoice

> Targets MonteFiskal 0.1.0. When to use: a plain PHP 8.5 application.

Paste the common frame, then the task. Fill in the angle brackets yourself.

## Common frame

```text
You are helping me integrate MonteFiskal 0.1.0 (Montenegro EFI fiscalization) into my project.

Read first: https://raw.githubusercontent.com/atakancelik/MonteFiskal/main/llms.txt and the briefing it links to. If you cannot fetch URLs, tell me and I
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

## Task

```text
Require montefiskal/core with Composer. Read the certificate with
Pkcs12CertificateProvider::fromEnvironment() (MONTEFISKAL_CERT_PATH and MONTEFISKAL_CERT_PASSWORD).
Use CurlTransport, or Psr18Transport with my PSR-18 client, against FiscalEndpoints for the test
environment. Store requests in PdoOutboxStore on my database (call createSchema() once). Issue and
send one invoice built from my order <type>, save number, IKOF, JIKR and verification URL on the
order, and add a cron job that calls resendPending().
```

## Verification the assistant must show

A PHPUnit test against the fake endpoint that asserts the state, the number format and a recomputed IKOF. The
script below is the reference.

```php
<?php

// Verification: issue against the fake service, check state, number format and a recomputed IKOF (BR-I1, BR-N1).

declare(strict_types=1);

use MonteFiskal\FiscalClient;
use MonteFiskal\FiscalProfile;
use MonteFiskal\Model\InvoiceDraft;
use MonteFiskal\Model\InvoiceLine;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Model\Payment;
use MonteFiskal\Model\PaymentMethod;
use MonteFiskal\Outbox\PdoOutboxStore;
use MonteFiskal\Security\IkofParameters;
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
$store = new PdoOutboxStore(new PDO('sqlite::memory:'));   // production: MySQL, MariaDB or PostgreSQL
$store->createSchema();
$client = new FiscalClient($profile, new StaticCertificateProvider(TestCertificateFactory::create($profile->issuerTin)), new FakeEfiEndpoint(), $store);
$issued = $client->issueInvoice(new InvoiceDraft(
    lines: [new InvoiceLine('Item', 'kom', '1', '12.10', priceIncludesVat: true, vatRate: '21')],
    payments: [new Payment(PaymentMethod::Card)],
));
$sent = $client->send($issued->id);
$check = $client->recomputeIkof(new IkofParameters($profile->issuerTin, $issued->issueDateTime, (string) $issued->ordinal, $profile->businessUnit, $profile->tcr, $profile->software, $issued->totals->totPrice));
$ok = $sent->state === InvoiceState::Fiscalized
    && preg_match('~^[a-z]{2}\d{3}[a-z]{2}\d{3}/\d+/\d{4}/[a-z]{2}\d{3}[a-z]{2}\d{3}$~', $issued->number) === 1
    && $check->iic === $issued->iic;
echo "state={$sent->state->value} number={$issued->number} ikofMatches=" . ($check->iic === $issued->iic ? 'true' : 'false') . "\n";
exit($ok ? 0 : 1);
```
