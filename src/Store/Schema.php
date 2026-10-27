<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Store;

use InvalidArgumentException;

/**
 * A Store's declared collections and fields, and the checks both backends
 * apply before touching their storage.
 *
 * @phpstan-import-type StoreSchema from Store
 * @phpstan-import-type Document from Store
 * @phpstan-import-type Filter from Store
 * @internal
 */
final class Schema
{
    private const NAME = '/^[a-z][a-z0-9_]*$/';

    /** @param StoreSchema $schema */
    public function __construct(public readonly array $schema)
    {
        foreach ($schema['collections'] as $collection => $declaration) {
            foreach ([$collection, ...array_keys($declaration['fields'])] as $name) {
                if (preg_match(self::NAME, $name) !== 1) {
                    throw new InvalidArgumentException("Invalid collection or field name: $name");
                }
            }
        }
    }

    /** @return array<string, 'int'|'string'> */
    public function fields(string $collection): array
    {
        return $this->schema['collections'][$collection]['fields']
            ?? throw new InvalidArgumentException("Unknown collection: $collection");
    }

    /**
     * Rejects a document without a string id or with a declared field that is
     * null, missing or of the wrong type, and returns its id.
     *
     * @param Document $document
     */
    public function checkDocument(string $collection, array $document): string
    {
        $id = $document['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException("A document in $collection needs a non-empty string id");
        }
        foreach ($this->fields($collection) as $field => $type) {
            if (!self::hasType($document[$field] ?? null, $type)) {
                throw new InvalidArgumentException("Declared field $collection.$field must be a non-null $type");
            }
        }

        return $id;
    }

    /** @param list<Filter> $where */
    public function checkQuery(string $collection, array $where, ?string $orderBy, ?int $limit): void
    {
        $fields = $this->fields($collection);
        foreach ($where as [$field, $op, $value]) {
            if (!isset($fields[$field]) || !self::hasType($value, $fields[$field])) {
                throw new InvalidArgumentException("Filter on $collection.$field needs a declared field and a value of its type");
            }
            if (!in_array($op, ['=', '<', '<=', '>', '>='], true)) {
                throw new InvalidArgumentException("Unknown filter operator: $op");
            }
        }
        if ($orderBy !== null && !isset($fields[$orderBy])) {
            throw new InvalidArgumentException("Ordering needs a declared field: $collection.$orderBy");
        }
        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException('A limit cannot be negative');
        }
    }

    private static function hasType(mixed $value, string $type): bool
    {
        return $type === 'int' ? is_int($value) : is_string($value);
    }
}
