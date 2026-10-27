<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Store;

/**
 * A collection-of-documents Store, served identically by every backend.
 *
 * A document is a JSON-serialisable array whose string `id` is unique in its
 * collection. Each collection declares the fields it filters or orders on;
 * a declared field is never null or missing. A filter is [field, operator,
 * value], with operator one of = < <= > >=.
 *
 * @phpstan-type StoreSchema array{schema: int, collections: array<string, array{fields: array<string, 'int'|'string'>}>}
 * @phpstan-type Document array<string, mixed>
 * @phpstan-type Filter array{0: string, 1: string, 2: int|string}
 */
interface Store
{
    /** @return Document|null */
    public function get(string $collection, string $id): ?array;

    /**
     * Creates or replaces the document with the same id.
     *
     * @param Document $document
     */
    public function put(string $collection, array $document): void;

    public function delete(string $collection, string $id): void;

    /**
     * Lists documents matching every filter, ordered by a declared field and
     * then by id (or by id alone).
     *
     * @param list<Filter> $where
     * @return list<Document>
     */
    public function list(string $collection, array $where = [], ?string $orderBy = null, bool $descending = false, ?int $limit = null): array;

    /**
     * Runs $work atomically: every write commits together, or none does if it
     * throws. A transaction started inside another joins the outer one.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed;

    /** The schema version this Store was created with. */
    public function schemaVersion(): int;

    /** A counter bumped by every committed transaction that wrote. */
    public function revision(): int;
}
