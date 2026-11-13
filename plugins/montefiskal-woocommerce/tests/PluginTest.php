<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Tests;

use Automattic\WooCommerce\Utilities\OrderUtil;
use MonteFiskal\Model\InvoiceKind;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Model\TypeOfInvoice;
use MonteFiskal\Security\XmlSignatureVerifier;
use MonteFiskal\Transport\FiscalOperation;
use MonteFiskal\Wire\SchemaSet;
use MonteFiskal\WooCommerce\Admin\ReportsPage;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Frontend\CustomerOutput;
use MonteFiskal\WooCommerce\Install;
use MonteFiskal\WooCommerce\Orders\Invoicing;
use MonteFiskal\WooCommerce\Orders\OrderMapper;
use MonteFiskal\WooCommerce\Privacy\Privacy;
use MonteFiskal\WooCommerce\Queue\Queue;
use MonteFiskal\WooCommerce\Settings;

/** Plugin integration tests on a real WordPress + WooCommerce site, run with HPOS on and off. */
final class PluginTest extends PluginTestCase
{
    public function testTablesExistAndHposModeMatchesTheRun(): void
    {
        global $wpdb;
        foreach (Install::tables() as $t) {
            $this->assertSame($t, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)));
        }
        $this->assertSame(getenv('MONTEFISKAL_HPOS') !== '0', OrderUtil::custom_orders_table_usage_is_enabled());
    }

    public function testCardOrderIsFiscalizedAfterTheDailyInitialDeposit_BR_D5_BR_K3(): void
    {
        $order = self::order('stripe', [[self::product('12.10'), 2]]);
        $order->payment_complete('txn-1');
        $docs = self::docs($order);
        $this->assertCount(1, $docs);
        $this->assertSame(TypeOfInvoice::Cash, $docs[0]->typeOfInv);
        $this->assertSame('24.20', $docs[0]->total);
        $this->assertSame(1, $docs[0]->ordinal);
        self::drainQueue();
        $operations = array_map(static fn (array $e): string => $e['requestElement'], self::$fake->exchanges());
        $this->assertSame(['RegisterCashDepositRequest', 'RegisterInvoiceRequest'], $operations);
        $stored = ClientFactory::store()->get($docs[0]->id);
        $this->assertSame(InvoiceState::Fiscalized, $stored->state);
        $this->assertNotNull($stored->result);
        $this->assertSame(InvoiceState::Fiscalized->value, wc_get_order($order->get_id())->get_meta(Invoicing::META_STATE));
        $this->assertTrue(Queue::initialRegisteredToday(new \DateTimeImmutable()));
    }

    public function testEverySignedRequestIsSchemaValidAndSigned_BR_S1(): void
    {
        $order = self::order('bacs', [[self::product('50.00'), 1]], '03012345', 'Buyer DOO');
        $order->payment_complete();
        self::drainQueue();
        $this->assertNotEmpty(self::$fake->exchanges());
        foreach (ClientFactory::store()->forOrder($order->get_id()) as $doc) {
            $this->assertNotNull($doc->lastSignedRequest);
            $this->assertSame([], SchemaSet::validate($doc->lastSignedRequest));
            $this->assertTrue(XmlSignatureVerifier::verify($doc->lastSignedRequest)->valid);
            $this->assertStringContainsString('<Buyer', $doc->body);
            $this->assertStringContainsString('IDNum="03012345"', $doc->body);
        }
    }

    public function testBankTransferIsNonCashAndNeedsNoDeposit_BR_K3(): void
    {
        $order = self::order('bacs', [[self::product('10.00'), 1]]);
        $order->payment_complete();
        self::drainQueue();
        $this->assertSame(TypeOfInvoice::NonCash, self::docs($order)[0]->typeOfInv);
        $this->assertSame(['RegisterInvoiceRequest'], array_map(static fn (array $e): string => $e['requestElement'], self::$fake->exchanges()));
    }

    public function testUnmappedGatewayIssuesNothingAndRaisesANotice(): void
    {
        $order = self::order('unknown-gateway', [[self::product('10.00'), 1]]);
        $order->payment_complete();
        $this->assertSame([], self::docs($order));
        $this->assertArrayHasKey('unmapped_unknown-gateway', self::notices());
    }

    public function testGatewayMappedToSkipIsNotFiscalized(): void
    {
        $order = self::order('cheque', [[self::product('10.00'), 1]]);
        $order->payment_complete();
        $this->assertSame([], self::docs($order));
        $this->assertSame([], self::notices());
    }

    public function testBusinessCardOnlyWithSettingTinAndCommercialCard_Q29(): void
    {
        $consumer = self::order('stripe', [[self::product('10.00'), 1]]);
        $consumer->update_meta_data(OrderMapper::COMMERCIAL_CARD_META, 'yes');
        $this->assertSame('CARD', OrderMapper::paymentMethod($consumer)->value, 'setting off');
        Settings::update(['business_card' => true]);
        $this->assertSame('CARD', OrderMapper::paymentMethod($consumer)->value, 'no buyer tax number');
        $company = self::order('stripe', [[self::product('10.00'), 1]], '03012345', 'Buyer DOO');
        $this->assertSame('CARD', OrderMapper::paymentMethod($company)->value, 'gateway did not report a commercial card');
        $company->update_meta_data(OrderMapper::COMMERCIAL_CARD_META, 'yes');
        $company->save();
        $this->assertSame('BUSINESSCARD', OrderMapper::paymentMethod($company)->value);
        $company->payment_complete();
        $this->assertSame(TypeOfInvoice::NonCash, self::docs($company)[0]->typeOfInv);
    }

    public function testFullRefundGivesAFullCorrectiveThatWaitsForTheOriginal_BR_C2_BR_C3(): void
    {
        $order = self::order('bacs', [[self::product('30.00'), 1]]);
        $order->payment_complete();
        self::$fake->failNextWithHttp(503);
        self::drainQueue(1);
        wc_create_refund(['order_id' => $order->get_id(), 'amount' => $order->get_total(), 'reason' => 'test']);
        $docs = self::docs($order);
        $this->assertCount(2, $docs);
        $corrective = $docs[1];
        $this->assertSame(InvoiceKind::Corrective, $corrective->kind);
        $this->assertSame($docs[0]->iic, $corrective->dependsOnIic);
        $this->assertStringContainsString('Q="-1"', $corrective->body);
        // The original failed once; the corrective must not go out before it (BR-C3).
        $result = ClientFactory::client()->send($corrective->id);
        $this->assertNull($result->fic);
        $this->assertStringContainsString('BR-C3', (string) $result->problem);
        Queue::runSend($docs[0]->id);
        Queue::runSend($corrective->id);
        $this->assertSame(InvoiceState::Fiscalized, ClientFactory::store()->get($corrective->id)->state);
    }

    public function testPartialRefundGivesACorrectiveOfTheRefundedLine_BR_C5(): void
    {
        $a = self::product('10.00');
        $b = self::product('20.00');
        $order = self::order('bacs', [[$a, 1], [$b, 2]]);
        $order->payment_complete();
        self::drainQueue();
        $items = array_values($order->get_items());
        $line = $items[1];
        wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => '20.00',
            'line_items' => [$line->get_id() => ['qty' => 1, 'refund_total' => '16.53', 'refund_tax' => ['1' => '3.47']]],
        ]);
        $docs = self::docs($order);
        $this->assertCount(2, $docs);
        $this->assertSame(InvoiceKind::Corrective, $docs[1]->kind);
        $this->assertSame('-20.00', $docs[1]->total);
        $this->assertSame(1, substr_count($docs[1]->body, '<I '));
    }

    public function testAdvanceFlowIssuesAdvanceThenInvoiceAndCorrective_BR_K9(): void
    {
        Settings::update(['mode' => Settings::MODE_ADVANCE]);
        Invoicing::register();
        $order = self::order('bacs', [[self::product('40.00'), 1]]);
        $order->payment_complete();
        $docs = self::docs($order);
        $this->assertCount(1, $docs);
        $this->assertSame(InvoiceKind::Advance, $docs[0]->kind);
        self::drainQueue();
        Invoicing::deliver(wc_get_order($order->get_id()));
        $docs = self::docs($order);
        $this->assertCount(3, $docs);
        $this->assertSame(InvoiceKind::Invoice, $docs[1]->kind);
        $this->assertStringContainsString('Type="ADVANCE"', $docs[1]->body);
        $this->assertStringContainsString('AdvIIC="' . $docs[0]->iic . '"', $docs[1]->body);
        $this->assertSame(InvoiceKind::Corrective, $docs[2]->kind);
        $this->assertSame($docs[0]->iic, $docs[2]->dependsOnIic);
        self::drainQueue();
        foreach (self::docs($order) as $d) {
            $this->assertSame(InvoiceState::Fiscalized, $d->state, (string) $d->invoiceNumber);
        }
        remove_all_actions('woocommerce_order_status_completed');
    }

    public function testRejectedDraftConsumesNoOrdinal_BR_N5_BR_A8(): void
    {
        $first = self::order('cod', [[self::product('10000.00'), 1]]);
        $first->payment_complete();
        $this->assertSame([], self::docs($first), 'cash at or above the limit is refused');
        $this->assertArrayHasKey('invalid_' . $first->get_id(), self::notices());
        $second = self::order('bacs', [[self::product('10.00'), 1]]);
        $second->payment_complete();
        $this->assertSame(1, self::docs($second)[0]->ordinal);
        $third = self::order('bacs', [[self::product('10.00'), 1]]);
        $third->payment_complete();
        $this->assertSame(2, self::docs($third)[0]->ordinal);
    }

    public function testIssuingTwiceForTheSameOrderIsIdempotent(): void
    {
        $order = self::order('bacs', [[self::product('10.00'), 1]]);
        $order->payment_complete();
        $order->update_status('completed');
        Invoicing::issueForOrder(wc_get_order($order->get_id()));
        $this->assertCount(1, self::docs($order));
    }

    public function testTransportFailureLeavesThePendingInvoiceQueued_BR_O7(): void
    {
        $order = self::order('bacs', [[self::product('10.00'), 1]]);
        $order->payment_complete();
        self::$fake->failNextWithHttp(503);
        self::drainQueue(1);
        $doc = self::docs($order)[0];
        $this->assertSame(InvoiceState::Pending, $doc->state);
        $this->assertNotNull($doc->nextAttemptAt);
        $this->assertTrue(as_has_scheduled_action(Queue::SEND, ['id' => $doc->id], Queue::GROUP));
        Queue::runSend($doc->id);
        $after = ClientFactory::store()->get($doc->id);
        $this->assertSame(InvoiceState::Fiscalized, $after->state);
        $this->assertStringContainsString('SubseqDelivType', (string) $after->lastSignedRequest);
    }

    public function testCustomerSeesNumberIkofJikrAndQr_BR_P4_BR_Q8(): void
    {
        $order = self::order('stripe', [[self::product('15.00'), 1]]);
        $order->payment_complete();
        self::drainQueue();
        $doc = CustomerOutput::documents(wc_get_order($order->get_id()))[0];
        ob_start();
        CustomerOutput::orderDetails(wc_get_order($order->get_id()));
        $page = (string) ob_get_clean();
        $this->assertStringContainsString((string) $doc->iic, $page);
        $this->assertStringContainsString((string) $doc->result, $page);
        $this->assertStringContainsString('<svg', $page);
        $this->assertStringContainsString('width="25mm"', $page);
        ob_start();
        CustomerOutput::email(wc_get_order($order->get_id()), false, true);
        $plain = (string) ob_get_clean();
        $this->assertStringContainsString('IKOF: ' . $doc->iic, $plain);
        $html = CustomerOutput::printableHtml($doc->id, $order->get_order_key());
        $this->assertNotNull($html);
        $this->assertStringContainsString((string) $doc->invoiceNumber, $html);
        $this->assertNull(CustomerOutput::printableHtml($doc->id, 'wrong-key'));
    }

    public function testPrivacyEraserRetainsFiscalRecords_BR_X1(): void
    {
        $order = self::order('bacs', [[self::product('10.00'), 1]]);
        $order->payment_complete();
        $export = Privacy::export('buyer@example.test');
        $this->assertNotEmpty($export['data']);
        $erase = Privacy::erase('buyer@example.test');
        $this->assertTrue($erase['items_retained']);
        $this->assertFalse($erase['items_removed']);
        $this->assertNotEmpty(self::docs($order));
    }

    public function testDailyReportCountsTheDaysInvoices(): void
    {
        foreach (['10.00', '20.00'] as $price) {
            self::order('stripe', [[self::product($price), 1]])->payment_complete();
        }
        self::drainQueue();
        $today = \MonteFiskal\Numbering\FiscalTime::localDate(new \DateTimeImmutable(), 'Europe/Podgorica');
        $report = ReportsPage::build('daily', $today, $today);
        $this->assertSame(2, $report->invoiceCount);
        $this->assertSame(0, bccomp('30', $report->total, 2));
        $this->assertStringContainsString('30.00', ReportsPage::toCsv($report));
        $journal = ReportsPage::build('analytic', $today, $today);
        $this->assertCount(2, $journal[0]['invoices']);
    }

    public function testDeadlineWatchRaisesANoticeBeforeFortyEightHours_BR_O12(): void
    {
        global $wpdb;
        $order = self::order('bacs', [[self::product('10.00'), 1]]);
        $order->payment_complete();
        $doc = self::docs($order)[0];
        $t = Install::tables();
        $wpdb->update($t['invoices'], ['issued_at' => gmdate('Y-m-d H:i:s', time() - 40 * 3600)], ['id' => $doc->id]);
        Queue::runDeadlineWatch();
        $this->assertArrayHasKey('deadline', self::notices());
        $this->assertStringContainsString((string) $doc->invoiceNumber, self::notices()['deadline']['message']);
    }

    public function testZeroRatedClassUsesTheMappedExemption_BR_V5(): void
    {
        Settings::update(['exemption_map' => ['zero-rate' => 'VAT_CL17']]);
        $order = self::order('bacs', [[self::product('10.00', 'zero-rate'), 1]]);
        $order->payment_complete();
        $notes = array_map(static fn ($n) => $n->content, wc_get_order_notes(['order_id' => $order->get_id()]));
        $docs = self::docs($order);
        $this->assertCount(1, $docs, implode(' / ', $notes));
        $this->assertStringContainsString('EX="VAT_CL17"', $docs[0]->body);
    }

    public function testCertificatePasswordIsStoredEncrypted(): void
    {
        $sealed = \MonteFiskal\WooCommerce\Secrets::encrypt('<your certificate password>');
        $this->assertStringStartsWith('v1:', $sealed);
        $this->assertStringNotContainsString('certificate password', $sealed);
        $this->assertSame('<your certificate password>', \MonteFiskal\WooCommerce\Secrets::decrypt($sealed));
    }

    public function testRequestsAreRegisteredWithTheirOperation(): void
    {
        $order = self::order('stripe', [[self::product('5.00'), 1]]);
        $order->payment_complete();
        self::drainQueue();
        $deposits = array_filter(ClientFactory::store()->pending(10), static fn ($s) => $s->operation === FiscalOperation::RegisterCashDeposit);
        $this->assertSame([], $deposits, 'the deposit is fiscalized, not pending');
    }

    public function testAdminScreensRenderWithoutErrors(): void
    {
        wp_set_current_user(1);
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        set_current_screen('dashboard');
        $order = self::order('stripe', [[self::product('9.00'), 1]]);
        $order->payment_complete();
        self::drainQueue();
        $out = [];
        foreach (array_keys(\MonteFiskal\WooCommerce\Admin\SettingsPage::tabs()) as $tab) {
            $_GET['tab'] = $tab;
            ob_start();
            \MonteFiskal\WooCommerce\Admin\SettingsPage::render();
            $out[$tab] = (string) ob_get_clean();
        }
        foreach (array_keys(\MonteFiskal\WooCommerce\Admin\SetupWizard::steps()) as $step) {
            $_GET['step'] = $step;
            ob_start();
            \MonteFiskal\WooCommerce\Admin\SetupWizard::render();
            $out['wizard-' . $step] = (string) ob_get_clean();
        }
        foreach (array_keys(ReportsPage::kinds()) as $kind) {
            $_REQUEST['kind'] = $kind;
            ob_start();
            ReportsPage::render();
            $out['report-' . $kind] = (string) ob_get_clean();
        }
        unset($_GET['tab'], $_GET['step'], $_REQUEST['kind']);
        ob_start();
        \MonteFiskal\WooCommerce\Admin\StatusPage::render();
        $out['status'] = (string) ob_get_clean();
        ob_start();
        \MonteFiskal\WooCommerce\Admin\OrderPanel::render(wc_get_order($order->get_id()));
        $out['panel'] = (string) ob_get_clean();
        ob_start();
        \MonteFiskal\WooCommerce\Admin\Notices::render();
        $out['notices'] = (string) ob_get_clean();
        foreach ($out as $name => $html) {
            $this->assertStringContainsString('montefiskal', strtolower($html), $name);
        }
        $this->assertStringContainsString('stripe', $out['payments']);
        $this->assertStringContainsString('VAT_CL17', $out['taxes']);
        $this->assertStringContainsString('JIKR', $out['panel']);
        $this->assertStringContainsString('SEP', $out['notices']);
        $this->assertStringContainsString(\MonteFiskal\WooCommerce\Admin\StatusPage::ERASE_PHRASE, $out['status']);
        wp_set_current_user(0);
    }
}
