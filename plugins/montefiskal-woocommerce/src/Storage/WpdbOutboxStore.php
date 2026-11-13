<?php

declare(strict_types=1);

namespace MonteFiskal\WooCommerce\Storage;

use DateTimeImmutable;
use DateTimeZone;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Outbox\Attempt;
use MonteFiskal\Outbox\OutboxStore;
use MonteFiskal\Outbox\StoredRequest;
use MonteFiskal\Transport\FiscalOperation;
use MonteFiskal\WooCommerce\Install;

defined('ABSPATH') || exit;

/**
 * Durable outbox in the site's own tables. Ordinals are allocated under SELECT ... FOR UPDATE inside a transaction,
 * so that two requests never get the same number and a rejected draft consumes none (BR-N5).
 */
final class WpdbOutboxStore implements OutboxStore
{
    private ?int $contextOrder = null;
    private ?string $contextRole = null;

    /** The order and role that the next issued or added request belongs to. */
    public function setContext(?int $orderId, ?string $role): void
    {
        $this->contextOrder = $orderId;
        $this->contextRole = $role;
    }

    public function issue(string $sequenceKey, callable $build): StoredRequest
    {
        global $wpdb;
        $t = Install::tables();
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$t['sequences']} (sequence_key, last_ordinal) VALUES (%s, 0)", $sequenceKey));
            $last = (int) $wpdb->get_var($wpdb->prepare("SELECT last_ordinal FROM {$t['sequences']} WHERE sequence_key = %s FOR UPDATE", $sequenceKey));
            $next = $last + 1;
            /** @var StoredRequest $request */
            $request = $build($next);
            $request = $request->with(sequenceKey: $sequenceKey, ordinal: $next);
            $this->insert($request);
            $wpdb->query($wpdb->prepare("UPDATE {$t['sequences']} SET last_ordinal = %d WHERE sequence_key = %s", $next, $sequenceKey));
            $wpdb->query('COMMIT');
            return $request;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public function add(StoredRequest $request): StoredRequest
    {
        $this->insert($request);
        return $request;
    }

    public function update(StoredRequest $request): void
    {
        global $wpdb;
        $t = Install::tables();
        $next = $request->with(version: $request->version + 1);
        $row = $this->row($next);
        unset($row['id'], $row['created_at'], $row['order_id'], $row['order_role']);
        $changed = $wpdb->update($t['invoices'], $row, ['id' => $request->id, 'version' => $request->version]);
        if ($changed === false) {
            throw new \RuntimeException('MonteFiskal: the fiscal record could not be saved: ' . $wpdb->last_error);
        }
        if ($changed === 0) {
            throw new \RuntimeException('The request was changed by someone else.');
        }
        $this->saveAttempts($next);
        $this->saveDeposit($next);
    }

    public function get(string $id): ?StoredRequest
    {
        global $wpdb;
        $t = Install::tables();
        $payload = $wpdb->get_var($wpdb->prepare("SELECT payload FROM {$t['invoices']} WHERE id = %s", $id));
        return $payload === null ? null : self::decode((string) $payload);
    }

    public function findByIic(string $iic): ?StoredRequest
    {
        global $wpdb;
        $t = Install::tables();
        $payload = $wpdb->get_var($wpdb->prepare("SELECT payload FROM {$t['invoices']} WHERE iic = %s LIMIT 1", strtoupper($iic)));
        return $payload === null ? null : self::decode((string) $payload);
    }

    public function pending(int $max): array
    {
        global $wpdb;
        $t = Install::tables();
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT payload FROM {$t['invoices']} WHERE state IN ('Issued','Pending','Overdue') ORDER BY issued_at ASC LIMIT %d",
            $max
        ));
        return array_map(self::decode(...), array_map('strval', $rows));
    }

    public function list(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        global $wpdb;
        $t = Install::tables();
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT payload FROM {$t['invoices']} WHERE issued_at BETWEEN %s AND %s ORDER BY issued_at ASC",
            self::utc($from),
            self::utc($to)
        ));
        return array_map(self::decode(...), array_map('strval', $rows));
    }

    /** @return list<StoredRequest> Requests that belong to an order, oldest first. */
    public function forOrder(int $orderId, ?string $role = null): array
    {
        global $wpdb;
        $t = Install::tables();
        $sql = $role === null
            ? $wpdb->prepare("SELECT payload FROM {$t['invoices']} WHERE order_id = %d ORDER BY issued_at ASC, ordinal ASC", $orderId)
            : $wpdb->prepare("SELECT payload FROM {$t['invoices']} WHERE order_id = %d AND order_role = %s ORDER BY issued_at ASC, ordinal ASC", $orderId, $role);
        return array_map(self::decode(...), array_map('strval', $wpdb->get_col($sql)));
    }

    /** @return array{order_id: int|null, order_role: string|null}|null */
    public function link(string $id): ?array
    {
        global $wpdb;
        $t = Install::tables();
        $row = $wpdb->get_row($wpdb->prepare("SELECT order_id, order_role FROM {$t['invoices']} WHERE id = %s", $id), ARRAY_A);
        return $row === null ? null : ['order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null, 'order_role' => $row['order_role']];
    }

    /** @return array{pending: int, overdue: int, rejected: int, oldest: string|null} */
    public function counts(): array
    {
        global $wpdb;
        $t = Install::tables();
        $rows = $wpdb->get_results("SELECT state, COUNT(*) AS n FROM {$t['invoices']} GROUP BY state", ARRAY_A);
        $by = [];
        foreach ($rows as $r) {
            $by[$r['state']] = (int) $r['n'];
        }
        $oldest = $wpdb->get_var("SELECT MIN(issued_at) FROM {$t['invoices']} WHERE state IN ('Issued','Pending','Overdue')");
        return [
            'pending' => ($by['Issued'] ?? 0) + ($by['Pending'] ?? 0),
            'overdue' => $by['Overdue'] ?? 0,
            'rejected' => $by['Rejected'] ?? 0,
            'oldest' => $oldest !== null ? (string) $oldest : null,
        ];
    }

    /** @return list<StoredRequest> Open invoices issued before the given time. */
    public function openIssuedBefore(DateTimeImmutable $before): array
    {
        global $wpdb;
        $t = Install::tables();
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT payload FROM {$t['invoices']} WHERE state IN ('Issued','Pending','Overdue') AND operation = %s AND issued_at < %s ORDER BY issued_at ASC",
            FiscalOperation::RegisterInvoice->value,
            self::utc($before)
        ));
        return array_map(self::decode(...), array_map('strval', $rows));
    }

    public function lastError(): ?string
    {
        global $wpdb;
        $t = Install::tables();
        $row = $wpdb->get_row("SELECT attempted_at, error_code, problem FROM {$t['attempts']} WHERE outcome <> 'success' ORDER BY id DESC LIMIT 1", ARRAY_A);
        if ($row === null) {
            return null;
        }
        return trim($row['attempted_at'] . ' UTC: ' . ($row['error_code'] !== null ? 'code ' . $row['error_code'] . ' ' : '') . (string) $row['problem']);
    }

    private function insert(StoredRequest $request): void
    {
        global $wpdb;
        $t = Install::tables();
        $row = $this->row($request);
        $row['order_id'] = $this->contextOrder;
        $row['order_role'] = $this->contextRole;
        if ($wpdb->insert($t['invoices'], $row) === false) {
            throw new \RuntimeException('MonteFiskal: the fiscal record could not be saved: ' . $wpdb->last_error);
        }
        $this->saveAttempts($request);
        $this->saveDeposit($request);
    }

    /** @return array<string, mixed> */
    private function row(StoredRequest $r): array
    {
        $now = self::utc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        return [
            'id' => $r->id,
            'operation' => $r->operation->value,
            'state' => $r->state->value,
            'kind' => $r->kind?->value,
            'type_of_inv' => $r->typeOfInv?->value,
            'tcr' => $r->tcr,
            'sequence_key' => $r->sequenceKey,
            'ordinal' => $r->ordinal,
            'invoice_number' => $r->invoiceNumber,
            'iic' => $r->iic !== null ? strtoupper($r->iic) : null,
            'result' => $r->result,
            'total' => $r->total,
            'depends_on_iic' => $r->dependsOnIic !== null ? strtoupper($r->dependsOnIic) : null,
            'issued_at' => self::utc($r->issuedAt),
            'next_attempt_at' => $r->nextAttemptAt !== null ? self::utc($r->nextAttemptAt) : null,
            'version' => $r->version,
            'payload' => wp_json_encode($r->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function saveAttempts(StoredRequest $r): void
    {
        global $wpdb;
        $t = Install::tables();
        foreach ($r->attempts as $a) {
            /** @var Attempt $a */
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$t['attempts']} (request_id, attempted_at, message_uuid, outcome, error_code, problem, response_uuid) VALUES (%s, %s, %s, %s, %s, %s, %s)",
                $r->id,
                self::utc($a->at),
                $a->headerUuid,
                $a->outcome->value,
                $a->errorCode,
                $a->problem,
                $a->responseUuid
            ));
        }
    }

    private function saveDeposit(StoredRequest $r): void
    {
        if ($r->operation !== FiscalOperation::RegisterCashDeposit) {
            return;
        }
        global $wpdb;
        $t = Install::tables();
        $doc = new \DOMDocument();
        $doc->loadXML($r->body, LIBXML_NONET);
        $e = $doc->documentElement;
        if ($e === null) {
            return;
        }
        $wpdb->replace($t['deposits'], [
            'request_id' => $r->id,
            'tcr' => $r->tcr,
            'deposit_date' => substr($e->getAttribute('ChangeDateTime'), 0, 10),
            'operation' => $e->getAttribute('Operation'),
            'amount' => $e->getAttribute('CashAmt'),
            'state' => $r->state->value,
            'fcdc' => $r->state === InvoiceState::Fiscalized ? $r->result : null,
        ]);
    }

    private static function decode(string $payload): StoredRequest
    {
        return StoredRequest::fromArray(json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
    }

    private static function utc(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
