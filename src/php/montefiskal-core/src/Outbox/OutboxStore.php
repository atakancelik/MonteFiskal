<?php

declare(strict_types=1);

namespace MonteFiskal\Outbox;

use DateTimeImmutable;

/**
 * Durable storage of requests. issue() allocates the next ordinal of a sequence and stores the request in one
 * transaction, before any network call (BR-N5): no gaps, no duplicates.
 */
interface OutboxStore
{
    /**
     * Allocates the next ordinal of $sequenceKey, builds the request with it and stores both atomically.
     * If $build throws, the ordinal is not consumed.
     *
     * @param callable(int): StoredRequest $build
     */
    public function issue(string $sequenceKey, callable $build): StoredRequest;

    /** Stores a request that has no ordinal (TCR registration, cash deposit). */
    public function add(StoredRequest $request): StoredRequest;

    /** Saves a changed request; throws when $request->version is not the stored version. */
    public function update(StoredRequest $request): void;

    public function get(string $id): ?StoredRequest;

    public function findByIic(string $iic): ?StoredRequest;

    /** @return list<StoredRequest> Requests in state Issued, Pending or Overdue, oldest first. */
    public function pending(int $max): array;

    /** @return list<StoredRequest> All requests issued in the interval, oldest first. */
    public function list(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
