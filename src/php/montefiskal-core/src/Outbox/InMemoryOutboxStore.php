<?php

declare(strict_types=1);

namespace MonteFiskal\Outbox;

use DateTimeImmutable;

/** In-memory store for tests and samples. Not durable. */
final class InMemoryOutboxStore implements OutboxStore
{
    /** @var array<string, StoredRequest> */
    private array $items = [];
    /** @var array<string, int> */
    private array $sequences = [];

    public function issue(string $sequenceKey, callable $build): StoredRequest
    {
        $next = ($this->sequences[$sequenceKey] ?? 0) + 1;
        $request = $build($next)->with(sequenceKey: $sequenceKey, ordinal: $next);
        $this->items[$request->id] = $request;
        $this->sequences[$sequenceKey] = $next;
        return $request;
    }

    public function add(StoredRequest $request): StoredRequest
    {
        $this->items[$request->id] = $request;
        return $request;
    }

    public function update(StoredRequest $request): void
    {
        $existing = $this->items[$request->id] ?? null;
        if ($existing !== null && $existing->version !== $request->version) {
            throw new \RuntimeException('The request was changed by someone else.');
        }
        $this->items[$request->id] = $request->with(version: $request->version + 1);
    }

    public function get(string $id): ?StoredRequest
    {
        return $this->items[$id] ?? null;
    }

    public function findByIic(string $iic): ?StoredRequest
    {
        foreach ($this->items as $r) {
            if ($r->iic !== null && strcasecmp($r->iic, $iic) === 0) {
                return $r;
            }
        }
        return null;
    }

    public function pending(int $max): array
    {
        $open = array_values(array_filter($this->items, static fn (StoredRequest $r): bool => $r->isOpen()));
        usort($open, static fn (StoredRequest $a, StoredRequest $b): int => $a->issuedAt <=> $b->issuedAt);
        return array_slice($open, 0, $max);
    }

    public function list(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $in = array_values(array_filter($this->items, static fn (StoredRequest $r): bool => $r->issuedAt >= $from && $r->issuedAt <= $to));
        usort($in, static fn (StoredRequest $a, StoredRequest $b): int => $a->issuedAt <=> $b->issuedAt);
        return $in;
    }
}
