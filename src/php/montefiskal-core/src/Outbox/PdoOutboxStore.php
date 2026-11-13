<?php

declare(strict_types=1);

namespace MonteFiskal\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Durable outbox on PDO (MySQL/MariaDB, PostgreSQL or SQLite). Each request is kept as JSON with a few indexed columns.
 * Ordinals are allocated inside a transaction that locks the sequence row, so that two processes never get the same
 * number and a draft that fails to build consumes none (BR-N5). Fiscal records are never deleted here (BR-X1).
 */
final class PdoOutboxStore implements OutboxStore
{
    private readonly string $driver;
    private readonly string $requests;
    private readonly string $sequences;

    public function __construct(private readonly PDO $pdo, string $tablePrefix = 'montefiskal_')
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $tablePrefix)) {
            throw new \InvalidArgumentException('The table prefix may contain letters, digits and underscores only.');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new \InvalidArgumentException("PDO driver '{$this->driver}' is not supported; use mysql, pgsql or sqlite.");
        }
        $this->requests = $tablePrefix . 'requests';
        $this->sequences = $tablePrefix . 'sequences';
    }

    /** Creates the two tables when they do not exist. Run once at installation or from a migration. */
    public function createSchema(): void
    {
        $text = $this->driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $engine = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->sequences} (
            sequence_key VARCHAR(100) NOT NULL PRIMARY KEY,
            last_ordinal BIGINT NOT NULL
        ){$engine}");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->requests} (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            iic VARCHAR(32) NULL,
            state VARCHAR(16) NOT NULL,
            issued_at VARCHAR(26) NOT NULL,
            version INT NOT NULL,
            payload {$text} NOT NULL
        ){$engine}");
        foreach (['iic', 'state', 'issued_at'] as $column) {
            $index = "{$this->requests}_{$column}";
            $this->pdo->exec($this->driver === 'mysql'
                ? $this->mysqlIndex($index, $column)
                : "CREATE INDEX IF NOT EXISTS {$index} ON {$this->requests} ({$column})");
        }
    }

    public function issue(string $sequenceKey, callable $build): StoredRequest
    {
        $this->begin();
        try {
            $this->pdo->prepare(match ($this->driver) {
                'mysql' => "INSERT IGNORE INTO {$this->sequences} (sequence_key, last_ordinal) VALUES (?, 0)",
                'pgsql' => "INSERT INTO {$this->sequences} (sequence_key, last_ordinal) VALUES (?, 0) ON CONFLICT (sequence_key) DO NOTHING",
                default => "INSERT OR IGNORE INTO {$this->sequences} (sequence_key, last_ordinal) VALUES (?, 0)",
            })->execute([$sequenceKey]);
            $lock = $this->driver === 'sqlite' ? '' : ' FOR UPDATE';
            $select = $this->pdo->prepare("SELECT last_ordinal FROM {$this->sequences} WHERE sequence_key = ?{$lock}");
            $select->execute([$sequenceKey]);
            $next = (int) $select->fetchColumn() + 1;
            /** @var StoredRequest $request */
            $request = $build($next);
            $request = $request->with(sequenceKey: $sequenceKey, ordinal: $next);
            $this->insert($request);
            $this->pdo->prepare("UPDATE {$this->sequences} SET last_ordinal = ? WHERE sequence_key = ?")->execute([$next, $sequenceKey]);
            $this->pdo->exec('COMMIT');
            return $request;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
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
        $next = $request->with(version: $request->version + 1);
        $statement = $this->pdo->prepare(
            "UPDATE {$this->requests} SET iic = ?, state = ?, issued_at = ?, version = ?, payload = ? WHERE id = ? AND version = ?"
        );
        $statement->execute([
            $next->iic === null ? null : strtoupper($next->iic), $next->state->value, self::utc($next->issuedAt), $next->version,
            self::encode($next), $request->id, $request->version,
        ]);
        if ($statement->rowCount() === 0) {
            throw new \RuntimeException('The request was changed by someone else.');
        }
    }

    public function get(string $id): ?StoredRequest
    {
        return $this->one("SELECT payload FROM {$this->requests} WHERE id = ?", [$id]);
    }

    public function findByIic(string $iic): ?StoredRequest
    {
        return $this->one("SELECT payload FROM {$this->requests} WHERE iic = ?", [strtoupper($iic)]);
    }

    public function pending(int $max): array
    {
        return $this->many(
            "SELECT payload FROM {$this->requests} WHERE state IN ('Issued', 'Pending', 'Overdue') ORDER BY issued_at ASC LIMIT " . max(0, $max),
            []
        );
    }

    public function list(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->many(
            "SELECT payload FROM {$this->requests} WHERE issued_at >= ? AND issued_at <= ? ORDER BY issued_at ASC",
            [self::utc($from), self::utc($to)]
        );
    }

    private function begin(): void
    {
        // SQLite: take the write lock at the start, so that two processes cannot read the same last ordinal.
        $this->pdo->exec($this->driver === 'sqlite' ? 'BEGIN IMMEDIATE' : ($this->driver === 'mysql' ? 'START TRANSACTION' : 'BEGIN'));
    }

    private function insert(StoredRequest $request): void
    {
        $this->pdo->prepare("INSERT INTO {$this->requests} (id, iic, state, issued_at, version, payload) VALUES (?, ?, ?, ?, ?, ?)")->execute([
            $request->id, $request->iic === null ? null : strtoupper($request->iic), $request->state->value, self::utc($request->issuedAt),
            $request->version, self::encode($request),
        ]);
    }

    /** @param list<mixed> $args */
    private function one(string $sql, array $args): ?StoredRequest
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($args);
        $payload = $statement->fetchColumn();
        return $payload === false ? null : self::decode((string) $payload);
    }

    /**
     * @param list<mixed> $args
     * @return list<StoredRequest>
     */
    private function many(string $sql, array $args): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($args);
        return array_values(array_map(static fn (mixed $p): StoredRequest => self::decode((string) $p), $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function mysqlIndex(string $index, string $column): string
    {
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $exists->execute([$this->requests, $index]);
        return (int) $exists->fetchColumn() > 0 ? 'DO 0' : "CREATE INDEX {$index} ON {$this->requests} ({$column})";
    }

    private static function encode(StoredRequest $request): string
    {
        return json_encode($request->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function decode(string $payload): StoredRequest
    {
        return StoredRequest::fromArray((array) json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
    }

    private static function utc(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
