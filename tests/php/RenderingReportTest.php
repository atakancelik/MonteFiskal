<?php

declare(strict_types=1);

namespace MonteFiskal\Tests;

use DateTimeImmutable;
use MonteFiskal\Archive\OfflineExchange;
use MonteFiskal\Calculation\Dec;
use MonteFiskal\FiscalClient;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Outbox\InMemoryOutboxStore;
use MonteFiskal\Rendering\EscPosReceipt;
use MonteFiskal\Rendering\HtmlReceipt;
use MonteFiskal\Rendering\QrCode;
use MonteFiskal\Rendering\QrImages;
use MonteFiskal\Rendering\Receipt;
use MonteFiskal\Reports\InspectorReports;
use MonteFiskal\Reports\ReportInvoice;
use MonteFiskal\Security\StaticCertificateProvider;
use MonteFiskal\Testing\FakeEfiEndpoint;
use MonteFiskal\Testing\FrozenClock;
use MonteFiskal\Testing\TestCertificateFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RenderingReportTest extends TestCase
{
    public static function qr(): array
    {
        return VectorTest::ids('qr');
    }
    public static function report(): array
    {
        return VectorTest::ids('report');
    }

    #[DataProvider('qr')]
    public function testQrVector(string $id): void
    {
        $v = VectorTest::vector($id);
        $qr = QrCode::encode($v['input']['text']);
        $e = $v['expected'];
        self::assertSame($e['version'], $qr->version);
        self::assertSame($e['size'], $qr->size);
        self::assertSame($e['mask'], $qr->mask);
        self::assertSame($e['modules'], $qr->rows());
        self::assertSame($e['modulesSha256'], hash('sha256', implode("\n", $qr->rows())));
    }

    #[DataProvider('report')]
    public function testReportVector(string $id): void
    {
        $v = VectorTest::vector($id);
        $i = $v['input'];
        $invoices = array_map(static fn (array $x): ReportInvoice => ReportInvoice::fromArray($x), $i['invoices']);
        $deposits = array_map(static fn (array $d): array => ['operation' => $d['operation'], 'amount' => (string) $d['amount'], 'changeDateTime' => $d['changeDateTime']], $i['deposits']);
        $r = $i['report'] === 'daily'
            ? InspectorReports::daily($i['date'], $i['tcr'], $i['businessUnit'], $invoices, $deposits)
            : InspectorReports::periodic($i['from'], $i['to'], $i['tcr'], $i['businessUnit'], $invoices, $deposits);
        $e = $v['expected'];
        $m = static fn (string $d): string => Dec::money($d);
        self::assertSame($e['invoiceCount'], $r->invoiceCount);
        self::assertSame($e['correctiveCount'], $r->correctiveCount);
        self::assertSame($e['offlineCount'], $r->offlineCount);
        self::assertSame($e['totalWithoutVat'], $m($r->totalWithoutVat));
        self::assertSame($e['vatAmount'], $m($r->vatAmount));
        self::assertSame($e['total'], $m($r->total));
        self::assertSame(
            array_map(static fn (array $x): array => [$x['key'], $x['priceBeforeVat'], $x['vatAmount']], $e['byVatRate']),
            array_map(static fn (array $x): array => [$x['vatRate'], $m($x['priceBeforeVat']), $m($x['vatAmount'])], $r->byVatRate)
        );
        self::assertSame(
            array_map(static fn (array $x): array => [$x['method'], $x['amount']], $e['byPaymentMethod']),
            array_map(static fn (array $x): array => [$x['method'], $m($x['amount'])], $r->byPaymentMethod)
        );
        self::assertSame($e['initialDeposit'], $m($r->initialDeposit));
        self::assertSame($e['withdrawals'], $m($r->withdrawals));
        self::assertSame($e['expectedCash'], $m($r->expectedCash));
        self::assertSame($e['operators'], $r->operators);
        self::assertStringContainsString('expectedCash;' . $e['expectedCash'], InspectorReports::toCsv($r));
    }

    public function testPngAndSvgAreWellFormed_BR_Q8(): void
    {
        $qr = QrCode::encode('https://efitest.tax.gov.me/ic/#/verify?iic=0123456789ABCDEF0123456789ABCDEF');
        self::assertSame("\x89PNG", substr(QrImages::png($qr, 4), 0, 4));
        self::assertStringContainsString('width="25mm"', QrImages::svg($qr, 25));
        $this->expectException(\OutOfRangeException::class);
        QrImages::svg($qr, 20);
    }

    public function testReceiptsAndOfflineExportFromAStoredInvoice(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-13T09:15:30Z'));
        $store = new InMemoryOutboxStore();
        $client = new FiscalClient(ClientTest::profile(), new StaticCertificateProvider(TestCertificateFactory::create()), new FakeEfiEndpoint($clock), $store, null, $clock);
        $issued = $client->issueInvoice(ClientTest::cardDraft());
        $stored = $store->get($issued->id);
        $receipt = Receipt::fromStored($stored, true);
        $html = HtmlReceipt::render($receipt, 'en');
        self::assertStringContainsString($issued->iic, $html);
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('TEST', strtoupper($html));
        $pos = EscPosReceipt::render($receipt, 58, 'me');
        self::assertStringStartsWith("\x1B\x40", $pos);
        self::assertStringContainsString($issued->verificationUrl, $pos);

        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is not installed');
        }
        $dir = sys_get_temp_dir() . '/mf-offline-' . bin2hex(random_bytes(4));
        $paths = OfflineExchange::export([$stored], static fn ($s): string => $client->signForExport($s), 'cc123cc123', $clock->now(), 'Europe/Podgorica', $dir);
        self::assertSame([$dir . '/20261113101530_cc123cc123_request.zip'], $paths);
        $zip = new \ZipArchive();
        $zip->open($paths[0]);
        self::assertSame('20261113101530_cc123cc123_' . $issued->iic . '_request.xml', $zip->getNameIndex(0));
        $zip->close();
        $report = InspectorReports::fromStore($store->list($clock->now()->modify('-1 day'), $clock->now()->modify('+1 day')), 'cc123cc123');
        self::assertCount(1, $report['invoices']);
        self::assertFalse($report['invoices'][0]->fiscalized);
        self::assertSame(InvoiceState::Issued, $stored->state);
    }
}
