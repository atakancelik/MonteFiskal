<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Queue;

use DateTimeImmutable;
use DateTimeZone;
use MonteFiskal\Model\CashDeposit;
use MonteFiskal\Model\CashDepositOperation;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Numbering\FiscalTime;
use MonteFiskal\Outbox\StoredRequest;
use MonteFiskal\ResendOptions;
use MonteFiskal\Transport\FiscalOperation;
use MonteFiskal\WooCommerce\Admin\Notices;
use MonteFiskal\WooCommerce\ClientFactory;
use MonteFiskal\WooCommerce\Orders\Invoicing;
use MonteFiskal\WooCommerce\Settings;

defined('ABSPATH') || exit;

/**
 * Action Scheduler jobs (architecture section 4, "Queue"):
 * montefiskal/send per request with exponential back-off, recurring montefiskal/resend_pending,
 * montefiskal/daily_cash_deposit (BR-D5) and montefiskal/deadline_watch (BR-O12).
 */
final class Queue
{
    public const GROUP = 'montefiskal';
    public const SEND = 'montefiskal/send';
    public const RESEND = 'montefiskal/resend_pending';
    public const DEPOSIT = 'montefiskal/daily_cash_deposit';
    public const DEADLINE = 'montefiskal/deadline_watch';

    public static function register(): void
    {
        add_action(self::SEND, [self::class, 'runSend']);
        add_action(self::RESEND, [self::class, 'runResend']);
        add_action(self::DEPOSIT, [self::class, 'runDailyDeposit']);
        add_action(self::DEADLINE, [self::class, 'runDeadlineWatch']);
        add_action('action_scheduler_init', [self::class, 'scheduleRecurring']);
    }

    public static function send(string $id, int $delaySeconds = 0): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        if (as_has_scheduled_action(self::SEND, ['id' => $id], self::GROUP)) {
            return;
        }
        if ($delaySeconds <= 0) {
            as_enqueue_async_action(self::SEND, ['id' => $id], self::GROUP, true);
        } else {
            as_schedule_single_action(time() + $delaySeconds, self::SEND, ['id' => $id], self::GROUP, true);
        }
    }

    public static function scheduleRecurring(): void
    {
        if (!function_exists('as_schedule_recurring_action')) {
            return;
        }
        if (!as_has_scheduled_action(self::RESEND, [], self::GROUP)) {
            as_schedule_recurring_action(time() + 300, 15 * MINUTE_IN_SECONDS, self::RESEND, [], self::GROUP, true);
        }
        if (!as_has_scheduled_action(self::DEADLINE, [], self::GROUP)) {
            as_schedule_recurring_action(time() + 600, HOUR_IN_SECONDS, self::DEADLINE, [], self::GROUP, true);
        }
        $time = (string) Settings::get('deposit_time');
        $scheduled = as_has_scheduled_action(self::DEPOSIT, [], self::GROUP);
        if ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) && !$scheduled && function_exists('as_schedule_cron_action')) {
            [$h, $m] = array_map('intval', explode(':', $time));
            as_schedule_cron_action(time(), sprintf('%d %d * * *', $m, $h), self::DEPOSIT, [], self::GROUP, true);
        } elseif ($time === '' && $scheduled) {
            as_unschedule_all_actions(self::DEPOSIT, [], self::GROUP);
        }
    }

    public static function unscheduleAll(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            foreach ([self::RESEND, self::DEADLINE, self::DEPOSIT] as $hook) {
                as_unschedule_all_actions($hook, [], self::GROUP);
            }
        }
    }

    /** One attempt; the next one is scheduled from the back-off the core computed (BR-O7, BR-T6). */
    public static function runSend(string $id): void
    {
        $client = ClientFactory::client();
        $result = $client->send($id);
        $stored = ClientFactory::store()->get($id);
        if ($stored === null) {
            return;
        }
        Invoicing::syncOrder($stored);
        if ($result->state === InvoiceState::Fiscalized || $result->state === InvoiceState::Rejected) {
            if ($result->state === InvoiceState::Rejected) {
                Notices::add('rejected_' . $id, sprintf(
                    /* translators: 1: invoice number, 2: error code */
                    __('MonteFiskal: %1$s was rejected (code %2$s) and needs attention.', 'montefiskal-woocommerce'),
                    (string) ($stored->invoiceNumber ?? $id),
                    (string) ($result->error?->code ?? '')
                ));
            }
            return;
        }
        // Waiting for the original (BR-C3) or the day's INITIAL deposit (BR-D5), or a retryable failure.
        $delay = $stored->nextAttemptAt !== null ? max(30, $stored->nextAttemptAt->getTimestamp() - time()) : 60;
        self::send($id, $delay);
    }

    public static function runResend(): void
    {
        $summary = ClientFactory::client()->resendPending(new ResendOptions(maxItems: 50, budgetSeconds: 120));
        if ($summary->attempted > 0) {
            foreach (ClientFactory::store()->pending(200) as $s) {
                Invoicing::syncOrder($s);
            }
        }
    }

    /**
     * BR-D5: the day's INITIAL deposit, by default 0.00. The core also registers it lazily before the first cash
     * invoice of the day; this job registers it at a fixed time when one is configured.
     */
    public static function runDailyDeposit(): void
    {
        if (!Settings::isConfigured() || !(bool) Settings::get('auto_initial_deposit')) {
            return;
        }
        $profile = Settings::profile();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (self::initialRegisteredToday($now)) {
            return;
        }
        ClientFactory::client()->registerCashDeposit(new CashDeposit($profile->issuerTin, $profile->tcr, CashDepositOperation::Initial, '0', $now));
    }

    public static function initialRegisteredToday(DateTimeImmutable $now): bool
    {
        $profile = Settings::profile();
        $day = FiscalTime::localDate($now, $profile->timeZone);
        $start = new DateTimeImmutable($day . ' 00:00:00', FiscalTime::zone($profile->timeZone));
        foreach (ClientFactory::store()->list($start, $start->modify('+1 day -1 second')) as $r) {
            if ($r->operation === FiscalOperation::RegisterCashDeposit && $r->tcr === $profile->tcr && str_contains($r->body, 'Operation="INITIAL"') && $r->state === InvoiceState::Fiscalized) {
                return true;
            }
        }
        return false;
    }

    /** BR-O12: raise a notice and e-mail the site admin before the 48 hours pass. */
    public static function runDeadlineWatch(): void
    {
        $hours = max(1, (int) Settings::get('deadline_warning_hours'));
        $late = ClientFactory::store()->openIssuedBefore(new DateTimeImmutable('-' . $hours . ' hours', new DateTimeZone('UTC')));
        if ($late === []) {
            Notices::remove('deadline');
            return;
        }
        $numbers = implode(', ', array_map(static fn (StoredRequest $s): string => (string) ($s->invoiceNumber ?? $s->id), array_slice($late, 0, 10)));
        $message = sprintf(
            /* translators: 1: count, 2: hours, 3: list of invoice numbers */
            _n(
                '%1$d invoice has not been fiscalized for more than %2$d hours; the deadline is 48 hours (BR-O12): %3$s',
                '%1$d invoices have not been fiscalized for more than %2$d hours; the deadline is 48 hours (BR-O12): %3$s',
                count($late),
                'montefiskal-woocommerce'
            ),
            count($late),
            $hours,
            $numbers
        );
        Notices::add('deadline', $message, 'error');
        $key = 'montefiskal_deadline_mail_' . md5($numbers);
        if (get_transient($key) === false) {
            wp_mail((string) get_option('admin_email'), __('MonteFiskal: invoices close to the fiscalization deadline', 'montefiskal-woocommerce'), $message);
            set_transient($key, 1, 12 * HOUR_IN_SECONDS);
        }
    }
}
