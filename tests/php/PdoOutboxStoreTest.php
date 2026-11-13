<?php

declare(strict_types=1);

namespace MonteFiskal\Tests;

use DateTimeImmutable;
use MonteFiskal\CorrectionRequest;
use MonteFiskal\FiscalClient;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Outbox\PdoOutboxStore;
use MonteFiskal\Security\StaticCertificateProvider;
use MonteFiskal\Testing\FakeEfiEndpoint;
use MonteFiskal\Testing\FrozenClock;
use MonteFiskal\Testing\TestCertificateFactory;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/** The durable PDO store, on an SQLite file: ordinals, optimistic concurrency, queries, and a full client round trip. */
#[RequiresPhpExtension('pdo_sqlite')]
final class PdoOutboxStoreTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'mf-pdo-') ?: self::fail('no temp file');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function store(): PdoOutboxStore
    {
        $store = new PdoOutboxStore(new PDO('sqlite:' . $this->file));
        $store->createSchema();
        return $store;
    }

    /** @return array{0: FiscalClient, 1: PdoOutboxStore} */
    private function client(): array
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-13T09:15:30Z'));
        $store = $this->store();
        return [new FiscalClient(ClientTest::profile(), new StaticCertificateProvider(TestCertificateFactory::create()), new FakeEfiEndpoint($clock), $store, null, $clock), $store];
    }

    public function testOrdinalsAreGapFreeAndSurviveANewConnection_BR_N5(): void
    {
        [$client] = $this->client();
        $first = $client->issueInvoice(ClientTest::cardDraft());
        [$again] = [new FiscalClient(ClientTest::profile(), new StaticCertificateProvider(TestCertificateFactory::create()), new FakeEfiEndpoint(), $this->store())];
        $second = $again->issueInvoice(ClientTest::cardDraft());
        self::assertSame(1, $first->ordinal);
        self::assertSame(2, $second->ordinal);
    }

    public function testCreateSchemaIsIdempotent(): void
    {
        $this->store();
        $this->store();
        $this->addToAssertionCount(1);
    }

    public function testSendStoresJikrAndFindsByIic_BR_J1(): void
    {
        [$client, $store] = $this->client();
        $issued = $client->issueInvoice(ClientTest::cardDraft());
        $sent = $client->send($issued->id);
        self::assertSame(InvoiceState::Fiscalized, $sent->state);
        $stored = $store->findByIic(strtolower($issued->iic));
        self::assertNotNull($stored);
        self::assertSame($sent->fic, $stored->result);
        self::assertSame([], array_values(array_filter($store->pending(10), static fn ($r): bool => $r->id === $issued->id)));
    }

    public function testStaleUpdateIsRefused(): void
    {
        [$client, $store] = $this->client();
        $issued = $client->issueInvoice(ClientTest::cardDraft());
        $a = $store->get($issued->id);
        self::assertNotNull($a);
        $store->update($a->with(state: InvoiceState::Pending));
        $this->expectException(\RuntimeException::class);
        $store->update($a->with(state: InvoiceState::Pending));
    }

    public function testCorrectiveWaitsForOriginalAndListReturnsBoth_BR_C3(): void
    {
        [$client, $store] = $this->client();
        $original = $client->issueInvoice(ClientTest::cardDraft());
        $client->send($original->id);
        $corrective = $client->issueCorrective(new CorrectionRequest($original->id));
        self::assertSame(InvoiceState::Fiscalized, $client->send($corrective->id)->state);
        $all = $store->list(new DateTimeImmutable('2026-11-13T00:00:00Z'), new DateTimeImmutable('2026-11-14T00:00:00Z'));
        self::assertContains($original->id, array_map(static fn ($r): string => $r->id, $all));
        self::assertContains($corrective->id, array_map(static fn ($r): string => $r->id, $all));
    }

    public function testRejectsUnsafeTablePrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdoOutboxStore(new PDO('sqlite::memory:'), 'x; DROP TABLE y');
    }
}
