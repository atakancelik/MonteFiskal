<?php

declare(strict_types=1);

namespace MonteFiskal\Tests;

use DateTimeImmutable;
use MonteFiskal\CorrectionRequest;
use MonteFiskal\FiscalClient;
use MonteFiskal\FiscalProfile;
use MonteFiskal\Model\CashDeposit;
use MonteFiskal\Model\CashDepositOperation;
use MonteFiskal\Model\InvoiceDraft;
use MonteFiskal\Model\InvoiceLine;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Model\Payment;
use MonteFiskal\Model\PaymentMethod;
use MonteFiskal\Model\TcrRegistration;
use MonteFiskal\Model\TcrType;
use MonteFiskal\Model\TypeOfInvoice;
use MonteFiskal\MonteFiskalOptions;
use MonteFiskal\Outbox\InMemoryOutboxStore;
use MonteFiskal\ResendOptions;
use MonteFiskal\ResponseSignaturePolicy;
use MonteFiskal\Security\CertificateChecks;
use MonteFiskal\Security\IkofParameters;
use MonteFiskal\Security\StaticCertificateProvider;
use MonteFiskal\Security\XmlSignatureVerifier;
use MonteFiskal\Testing\FakeEfiEndpoint;
use MonteFiskal\Testing\FrozenClock;
use MonteFiskal\Testing\TestCertificateFactory;
use MonteFiskal\TextPolicy;
use MonteFiskal\Validation\Finding;
use MonteFiskal\Validation\FiscalValidationException;
use MonteFiskal\Wire\ResponseParser;
use MonteFiskal\Wire\SchemaSet;
use PHPUnit\Framework\TestCase;

/** The same scenarios as the .NET ClientTests, against the in-process fake service. */
final class ClientTest extends TestCase
{
    public static function profile(): FiscalProfile
    {
        return new FiscalProfile(
            issuerTin: '12345678',
            issuerName: 'MonteFiskal Test Only DOO',
            businessUnit: 'bb123bb123',
            software: 'ss123ss123',
            operator: 'oo123oo123',
            tcr: 'cc123cc123',
            issuerAddress: 'Test Street 1',
            issuerTown: 'Podgorica',
            issuerInVat: true,
            maintainer: 'mm123mm123'
        );
    }

    public static function cardDraft(string $price = '12.10'): InvoiceDraft
    {
        return new InvoiceDraft(
            lines: [new InvoiceLine('Test item', 'kom', '1', $price, true, '21')],
            payments: [new Payment(PaymentMethod::Card)],
        );
    }

    /** @return array{0: FiscalClient, 1: FakeEfiEndpoint, 2: InMemoryOutboxStore, 3: FrozenClock} */
    private static function create(?MonteFiskalOptions $options = null): array
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-13T09:15:30Z'));
        $fake = new FakeEfiEndpoint($clock);
        $store = new InMemoryOutboxStore();
        $client = new FiscalClient(self::profile(), new StaticCertificateProvider(TestCertificateFactory::create()), $fake, $store, $options, $clock);
        return [$client, $fake, $store, $clock];
    }

    public function testIssueAndSendRegistersInitialDepositFirst_BR_D5(): void
    {
        [$client, $fake] = self::create();
        $issued = $client->issueInvoice(self::cardDraft());
        self::assertSame('bb123bb123/1/2026/cc123cc123', $issued->number);
        self::assertSame(TypeOfInvoice::Cash, $issued->typeOfInv);
        self::assertMatchesRegularExpression('/^[0-9A-F]{32}$/', $issued->iic);
        self::assertStringStartsWith('https://efitest.tax.gov.me/ic/#/verify?iic=' . $issued->iic, $issued->verificationUrl);
        $sent = $client->send($issued->id);
        self::assertSame(InvoiceState::Fiscalized, $sent->state);
        self::assertNotNull($sent->fic);
        self::assertSame(['RegisterCashDepositRequest', 'RegisterInvoiceRequest'], array_column($fake->exchanges(), 'requestElement'));
        self::assertStringContainsString('Operation="INITIAL"', $fake->exchanges()[0]['body']);
        self::assertStringContainsString('CashAmt="0.00"', $fake->exchanges()[0]['body']);
    }

    public function testWithoutInitialDepositCashInvoiceIsRefusedWithCode58(): void
    {
        [$client, $fake] = self::create(new MonteFiskalOptions(autoInitialDeposit: false));
        $sent = $client->send($client->issueInvoice(self::cardDraft())->id);
        self::assertSame(InvoiceState::Rejected, $sent->state);
        self::assertSame(58, $sent->error?->code);
        self::assertCount(1, $fake->exchanges());
    }

    public function testRequestSignatureVerifiesAndIsSchemaValid_BR_S1_to_BR_S4(): void
    {
        [$client, $fake, $store] = self::create();
        $issued = $client->issueInvoice(self::cardDraft());
        $client->send($issued->id);
        $signed = (string) $store->get($issued->id)?->lastSignedRequest;
        $check = XmlSignatureVerifier::verify($signed, 'Request');
        self::assertTrue($check->valid, (string) $check->problem);
        self::assertSame([], SchemaSet::validate($signed));
        foreach ($fake->exchanges() as $x) {
            self::assertSame(200, $x['statusCode']);
        }
    }

    public function testResponseSignatureIsVerified_BR_S6(): void
    {
        [$client, $fake, $store] = self::create(new MonteFiskalOptions(responseSignature: ResponseSignaturePolicy::Require));
        $issued = $client->issueInvoice(self::cardDraft());
        self::assertSame(InvoiceState::Fiscalized, $client->send($issued->id)->state);
        $parsed = ResponseParser::parse(200, $store->get($issued->id)?->lastResponse);
        self::assertTrue($parsed->signature?->valid);
        self::assertSame($fake->serviceCertificate->der(), $parsed->signature->certificateDer);
    }

    public function testUnsignedResponseIsMalformedWhenSignatureIsRequired(): void
    {
        [$client, $fake] = self::create(new MonteFiskalOptions(responseSignature: ResponseSignaturePolicy::Require, autoInitialDeposit: false));
        $fake->requireInitialDeposit = false;
        self::assertSame(InvoiceState::Fiscalized, $client->send($client->issueInvoice(self::cardDraft())->id)->state);
        $other = $client->issueInvoice(self::cardDraft('5'));
        $fake->signResponses = false;
        self::assertSame(InvoiceState::Pending, $client->send($other->id)->state);
    }

    public function testResendKeepsBodyAndIkofAndSetsSubseqDelivType_BR_O7(): void
    {
        [$client, $fake, $store, $clock] = self::create();
        $issued = $client->issueInvoice(self::cardDraft());
        $fake->failNextWithHttp(503)->failNextWithHttp(503);
        self::assertSame(InvoiceState::Pending, $client->send($issued->id)->state);
        $clock->advance('PT10M');
        $summary = $client->resendPending(new ResendOptions());
        self::assertGreaterThanOrEqual(1, $summary->fiscalized);
        $stored = $store->get($issued->id);
        self::assertSame(InvoiceState::Fiscalized, $stored?->state);
        self::assertStringContainsString('SubseqDelivType="NOINTERNET"', (string) $stored->lastSignedRequest);
        self::assertStringContainsString($stored->body, (string) $stored->lastSignedRequest);
        self::assertStringContainsString('IIC="' . $issued->iic . '"', (string) $stored->lastSignedRequest);
    }

    public function testServerFaultIsRetryableAndBecomesPending(): void
    {
        [$client, $fake] = self::create(new MonteFiskalOptions(autoInitialDeposit: false));
        $fake->requireInitialDeposit = false;
        $issued = $client->issueInvoice(self::cardDraft());
        $fake->failNextWith(999, 'Internal error.', true);
        $r = $client->send($issued->id);
        self::assertSame(InvoiceState::Pending, $r->state);
        self::assertTrue($r->error?->retryable);
    }

    public function testOverdueAfter48Hours_BR_O12(): void
    {
        [$client, $fake, , $clock] = self::create(new MonteFiskalOptions(autoInitialDeposit: false));
        $fake->requireInitialDeposit = false;
        $issued = $client->issueInvoice(self::cardDraft());
        $clock->advance('PT49H');
        $fake->failNextWithHttp(502);
        self::assertSame(InvoiceState::Overdue, $client->send($issued->id)->state);
    }

    public function testValidationErrorConsumesNoOrdinal_BR_N5(): void
    {
        [$client] = self::create();
        try {
            $client->issueInvoice(self::cardDraft()->with(reverseCharge: true));
            self::fail('expected FiscalValidationException');
        } catch (FiscalValidationException $e) {
            self::assertContains('BR-K11', array_map(static fn (Finding $f): string => $f->rule, $e->result->errors()));
        }
        self::assertSame(1, $client->issueInvoice(self::cardDraft())->ordinal);
        self::assertSame(2, $client->issueInvoice(self::cardDraft())->ordinal);
    }

    public function testSchemaErrorConsumesNoOrdinal_BR_O10(): void
    {
        [$client] = self::create();
        $bad = self::cardDraft()->with(lines: [new InvoiceLine(str_repeat('x', 51), 'kom', '1', '1', false, '21')]);
        try {
            $client->issueInvoice($bad);
            self::fail('expected FiscalValidationException');
        } catch (FiscalValidationException $e) {
            self::assertContains('SCHEMA', array_map(static fn (Finding $f): string => $f->rule, $e->result->errors()));
        }
        self::assertSame(1, $client->issueInvoice(self::cardDraft())->ordinal);
    }

    public function testCorrectiveWaitsForOriginal_BR_C3_AndReversesQuantities_BR_C2(): void
    {
        [$client, $fake, $store] = self::create();
        $original = $client->issueInvoice(self::cardDraft());
        $corrective = $client->issueCorrective(new CorrectionRequest($original->id));
        self::assertSame('-12.10', $corrective->totals->totPrice);
        $early = $client->send($corrective->id);
        self::assertSame(InvoiceState::Issued, $early->state);
        self::assertStringContainsString('BR-C3', (string) $early->problem);
        $client->send($original->id);
        self::assertSame(InvoiceState::Fiscalized, $client->send($corrective->id)->state);
        $body = $store->get($corrective->id)?->body;
        self::assertStringContainsString('<CorrectiveInv IICRef="' . $original->iic . '"', (string) $body);
        self::assertStringContainsString('Q="-1"', (string) $body);
        self::assertStringContainsString('UPA="12.1000"', (string) $body);
        self::assertCount(3, $fake->exchanges());
    }

    public function testTcrRegistrationReturnsTheSameCodeForTheSameInternalId_BR_R3(): void
    {
        [$client] = self::create();
        $reg = new TcrRegistration('12345678', 'bb123bb123', '1', 'ss123ss123', 'mm123mm123', type: TcrType::Regular);
        $a = $client->registerTcr($reg);
        $b = $client->registerTcr($reg);
        self::assertTrue($a->success);
        self::assertSame($a->tcrCode, $b->tcrCode);
        self::assertMatchesRegularExpression('/^[a-z]{2}[0-9]{3}[a-z]{2}[0-9]{3}$/', (string) $a->tcrCode);
    }

    public function testWithdrawZeroIsRefusedLocally_BR_D2(): void
    {
        [$client] = self::create();
        $this->expectException(FiscalValidationException::class);
        $client->registerCashDeposit(new CashDeposit('12345678', 'cc123cc123', CashDepositOperation::Withdraw, '0'));
    }

    public function testRecomputeIkofIsDeterministic_BR_I6(): void
    {
        [$client] = self::create();
        $p = new IkofParameters('12345678', '2026-11-13T10:15:30+01:00', '1', 'bb123bb123', 'cc123cc123', 'ss123ss123', '12.10');
        self::assertEquals($client->recomputeIkof($p), $client->recomputeIkof($p));
    }

    public function testTransliterationIsOptional_Q07(): void
    {
        [$client, , $store] = self::create(new MonteFiskalOptions(text: TextPolicy::Transliterate));
        $issued = $client->issueInvoice(self::cardDraft()->with(lines: [new InvoiceLine('Čokolada đak', 'kom', '1', '2', true, '21')]));
        self::assertSame([], $issued->warnings);
        self::assertStringContainsString('N="Cokolada djak"', (string) $store->get($issued->id)?->body);
    }

    public function testDuplicateInitialCountsAsSuccess_Q17(): void
    {
        [$client, $fake] = self::create();
        $fake->failNextWith(56, 'INITIAL already registered for the current day.');
        $r = $client->registerCashDeposit(new CashDeposit('12345678', 'cc123cc123', CashDepositOperation::Initial, '0'));
        self::assertSame(InvoiceState::Fiscalized, $r->state);
    }

    public function testCertificateChecksFindTheTinAndKeySize(): void
    {
        $cert = TestCertificateFactory::create('12345678');
        $now = new DateTimeImmutable();
        self::assertSame(['errors' => [], 'warnings' => []], CertificateChecks::inspect($cert, '12345678', $now));
        self::assertCount(1, CertificateChecks::inspect($cert, '87654321', $now)['warnings']);
        self::assertNotEmpty(CertificateChecks::inspect($cert, '12345678', $now->modify('+2 years'))['errors']);
    }
}
