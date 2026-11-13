<?php

// Minimal correct path in PHP 8.5: configure, issue, send, handle the result, resend pending.
// Runs against the in-process fake service; for efitest use CurlTransport and Pkcs12CertificateProvider.

declare(strict_types=1);

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
    issuerTin: '12345678',          // <your TIN>; this value belongs to the fake service only
    issuerName: 'MonteFiskal Test Only DOO',
    businessUnit: 'bb123bb123',     // <your business unit code>
    software: 'ss123ss123',         // <your software code>
    operator: 'oo123oo123',         // <your operator code>
    tcr: 'cc123cc123',              // <your TCR code>
    issuerInVat: true,
);
// Production: Pkcs12CertificateProvider::fromEnvironment() reads MONTEFISKAL_CERT_PATH and MONTEFISKAL_CERT_PASSWORD.
$certificates = new StaticCertificateProvider(TestCertificateFactory::create($profile->issuerTin));
$client = new FiscalClient($profile, $certificates, new FakeEfiEndpoint(), new InMemoryOutboxStore());

$issued = $client->issueInvoice(new InvoiceDraft(
    lines: [new InvoiceLine('Item', 'kom', '2', '12.10', priceIncludesVat: true, vatRate: '21')],
    payments: [new Payment(PaymentMethod::Card)],   // consumer card: a CASH invoice (BR-K3)
));
// Amounts are decimal strings, never floats. Number and IKOF are final now (BR-J2).
$sent = $client->send($issued->id);
if ($sent->state !== InvoiceState::Fiscalized) {
    $client->resendPending();   // never renumber; body and IKOF stay (BR-O7)
}
echo "{$issued->number} IKOF {$issued->iic} JIKR {$sent->fic} {$issued->verificationUrl}\n";
exit($sent->state === InvoiceState::Fiscalized ? 0 : 1);
