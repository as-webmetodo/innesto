<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Store;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * A Store kept in one SQLite file: one table per collection holding each
 * document as JSON beside an indexed column per declared field. Uses the
 * rollback journal (never WAL) and only SQLite 3.27 features.
 *
 * @phpstan-import-type StoreSchema from Store
 */
final class SqliteStore implements Store
{
    private const BUSY_TIMEOUT_MS = 5000;

    private bool $inTransaction = false;

    private bool $wrote = false;

    /** @param Schema $schema the collections and fields declared when the Store was created */
    private function __construct(private readonly PDO $db, private readonly Schema $schema)
    {
    }

    /**
     * Opens the Store at $path, creating it with $schema if needed. An
     * existing Store keeps the version and declaration it was created with.
     *
     * @param StoreSchema $schema
     */
    public static function open(string $path, array $schema): self
    {
        $db = new PDO("sqlite:$path", options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $db->exec('PRAGMA journal_mode = DELETE');
        // Schema checks the names create() puts into SQL.
        $store = new self($db, new Schema($schema));
        $meta = $db->query("SELECT 1 FROM sqlite_master WHERE name = 'meta'");
        if ($meta === false || $meta->fetchColumn() === false) {
            $store->create($schema);
        }
        /** @var array<string, array{fields: array<string, 'int'|'string'>}> $declared */
        $declared = self::decode($store->meta('declared'));

        return new self($db, new Schema(['schema' => (int) $store->meta('schema'), 'collections' => $declared]));
    }

    public function get(string $collection, string $id): ?array
    {
        $this->schema->fields($collection);
        $statement = $this->db->prepare('SELECT doc FROM ' . self::table($collection) . ' WHERE id = ?');
        $statement->execute([$id]);
        $doc = $statement->fetchColumn();

        return is_string($doc) ? self::decode($doc) : null;
    }

    public function put(string $collection, array $document): void
    {
        $id = $this->schema->checkDocument($collection, $document);
        $fields = array_keys($this->schema->fields($collection));
        $columns = implode('', array_map(static fn (string $field): string => ', ' . self::column($field), $fields));
        $placeholders = str_repeat(', ?', count($fields));
        $values = [$id, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)];
        foreach ($fields as $field) {
            $values[] = $document[$field];
        }
        $this->write('INSERT OR REPLACE INTO ' . self::table($collection) . " (id, doc$columns) VALUES (?, ?$placeholders)", $values);
    }

    public function delete(string $collection, string $id): void
    {
        $this->schema->fields($collection);
        $this->write('DELETE FROM ' . self::table($collection) . ' WHERE id = ?', [$id]);
    }

    public function list(string $collection, array $where = [], ?string $orderBy = null, bool $descending = false, ?int $limit = null): array
    {
        $this->schema->checkQuery($collection, $where, $orderBy, $limit);
        $sql = 'SELECT doc FROM ' . self::table($collection);
        $values = [];
        foreach ($where as $i => [$field, $op, $value]) {
            $sql .= ($i === 0 ? ' WHERE ' : ' AND ') . self::column($field) . " $op ?";
            $values[] = $value;
        }
        $sql .= ' ORDER BY ' . ($orderBy === null ? '' : self::column($orderBy) . ($descending ? ' DESC, ' : ', ')) . 'id';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        $statement = $this->db->prepare($sql);
        self::bind($statement, $values);
        $statement->execute();

        return array_values(array_map(self::decode(...), $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function transaction(callable $work): mixed
    {
        if ($this->inTransaction) {
            return $work();
        }
        // IMMEDIATE takes the write lock up front, so busy_timeout covers waiting for it.
        $this->db->exec('BEGIN IMMEDIATE');
        $this->inTransaction = true;
        try {
            $result = $work();
            if ($this->wrote) {
                $this->db->exec("UPDATE meta SET value = value + 1 WHERE key = 'revision'");
            }
            $this->db->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        } finally {
            $this->inTransaction = false;
            $this->wrote = false;
        }
    }

    public function schemaVersion(): int
    {
        return (int) $this->meta('schema');
    }

    public function revision(): int
    {
        return (int) $this->meta('revision');
    }

    /** @param StoreSchema $schema */
    private function create(array $schema): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            // Another request may have created it while this one waited for the lock.
            // No column type: values keep their own (an integer, or the declaration as JSON).
            $this->db->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value NOT NULL)');
            $statement = $this->db->prepare("INSERT OR IGNORE INTO meta (key, value) VALUES ('schema', ?), ('declared', ?), ('revision', 0)");
            $statement->bindValue(1, $schema['schema'], PDO::PARAM_INT);
            $statement->bindValue(2, json_encode($schema['collections'], JSON_THROW_ON_ERROR));
            $statement->execute();
            foreach ($schema['collections'] as $collection => $declaration) {
                $columns = '';
                foreach ($declaration['fields'] as $field => $type) {
                    $columns .= ', ' . self::column($field) . ($type === 'int' ? ' INTEGER' : ' TEXT') . ' NOT NULL';
                }
                $table = self::table($collection);
                $this->db->exec("CREATE TABLE IF NOT EXISTS $table (id TEXT PRIMARY KEY, doc TEXT NOT NULL$columns)");
                foreach (array_keys($declaration['fields']) as $field) {
                    $this->db->exec('CREATE INDEX IF NOT EXISTS "i_' . $collection . '.' . $field . "\" ON $table (" . self::column($field) . ')');
                }
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    private function rollBack(): void
    {
        try {
            $this->db->exec('ROLLBACK');
        } catch (PDOException) {
            // SQLite already rolled back on its own (disk full, busy commit); the caller rethrows the cause.
        }
    }

    /** @param list<mixed> $values */
    private function write(string $sql, array $values): void
    {
        $this->transaction(function () use ($sql, $values): void {
            $statement = $this->db->prepare($sql);
            self::bind($statement, $values);
            $statement->execute();
            $this->wrote = true;
        });
    }

    /** @param list<mixed> $values */
    private static function bind(\PDOStatement $statement, array $values): void
    {
        foreach ($values as $i => $value) {
            $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function meta(string $key): int|string
    {
        $statement = $this->db->prepare('SELECT value FROM meta WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return is_int($value) || is_string($value) ? $value : throw new RuntimeException("Store meta $key is missing");
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $doc): array
    {
        /** @var array<string, mixed> */
        return json_decode(is_string($doc) ? $doc : '', true, flags: JSON_THROW_ON_ERROR);
    }

    // Collection and field names are checked against [a-z][a-z0-9_]* by Schema.
    private static function table(string $collection): string
    {
        return "\"c_$collection\"";
    }

    private static function column(string $field): string
    {
        return "\"f_$field\"";
    }
}
