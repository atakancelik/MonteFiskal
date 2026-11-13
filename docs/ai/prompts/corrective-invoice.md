# Refunds and corrections

> Targets MonteFiskal 0.1.0. When to use: refunds, returns and mistakes on issued invoices.

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
Implement full refunds as a corrective invoice of the whole original (CorrectionRequest with the
original's id) and partial refunds as a corrective with only the refunded lines and negative
quantities (BR-C5). Never edit or delete an issued invoice (BR-C1). Send a corrective only after
the original has its JIKR (BR-C3); the library queues it until then. For a cash refund also
register a WITHDRAW cash deposit. When the SEP shows that an invoice failed the deferred checks,
issue an error-corrective (CorrectionType.ErrorCorrective) that carries all items again with the
correct values, within 8 days (BR-A4, BR-C4).
```

## Verification the assistant must show

A fake-service test: original, partial corrective, totals reconcile. The script below is the reference.

```php
<?php

// Verification: original, partial corrective with one negative line, totals reconcile (BR-C3, BR-C5).

declare(strict_types=1);

use MonteFiskal\CorrectionRequest;
use MonteFiskal\FiscalClient;
use MonteFiskal\FiscalProfile;
use MonteFiskal\Model\InvoiceDraft;
use MonteFiskal\Model\InvoiceLine;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Model\Payment;
use MonteFiskal\Model\PaymentMethod;
use MonteFiskal\Outbox\InMemoryOutboxStore;
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
$client = new FiscalClient($profile, new StaticCertificateProvider(TestCertificateFactory::create($profile->issuerTin)), new FakeEfiEndpoint(), new InMemoryOutboxStore());
$original = $client->issueInvoice(new InvoiceDraft(
    lines: [
        new InvoiceLine('Shirt', 'kom', '2', '24.20', priceIncludesVat: true, vatRate: '21'),
        new InvoiceLine('Cap', 'kom', '1', '12.10', priceIncludesVat: true, vatRate: '21'),
    ],
    payments: [new Payment(PaymentMethod::Account)],
));
$client->send($original->id);   // the corrective waits until the original has its JIKR (BR-C3)
$partial = $client->issueCorrective(new CorrectionRequest(
    $original->id,
    lines: [new InvoiceLine('Cap', 'kom', '-1', '12.10', priceIncludesVat: true, vatRate: '21')],
));
$sent = $client->send($partial->id);
$remaining = bcadd($original->totals->totPrice, $partial->totals->totPrice, 2);
echo "original={$original->totals->totPrice} corrective={$partial->totals->totPrice} remaining={$remaining}\n";
exit($sent->state === InvoiceState::Fiscalized && $remaining === '48.40' ? 0 : 1);
```
