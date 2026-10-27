<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Store;

use RuntimeException;
use Throwable;

/**
 * A Store kept in one guarded file (`<?php exit; ?>` followed by JSON) with one
 * lock file beside it. A transaction locks, works on an in-memory copy and
 * commits it with a single atomic rename.
 *
 * @phpstan-import-type StoreSchema from Store
 * @phpstan-import-type Document from Store
 * @phpstan-type Contents array{schema: int, revision: int, collections: array<string, array<string, Document>>}
 */
final class FileStore implements Store
{
    private const GUARD = "<?php exit; ?>\n";

    /** @var Contents|null the in-memory copy while a transaction runs */
    private ?array $working = null;

    private bool $wrote = false;

    private function __construct(private readonly string $path, private readonly Schema $schema)
    {
    }

    /**
     * Opens the Store at $path, creating it with $schema's version if needed.
     *
     * @param StoreSchema $schema
     */
    public static function open(string $path, array $schema): self
    {
        $store = new self($path, new Schema($schema));
        if (!is_file($path)) {
            $lock = $store->lock();
            try {
                is_file($path) || $store->write(['schema' => $schema['schema'], 'revision' => 0, 'collections' => []]);
            } finally {
                self::unlock($lock);
            }
        }

        return $store;
    }

    public function get(string $collection, string $id): ?array
    {
        $this->schema->fields($collection);

        return $this->contents()['collections'][$collection][$id] ?? null;
    }

    public function put(string $collection, array $document): void
    {
        $id = $this->schema->checkDocument($collection, $document);
        $this->transaction(function () use ($collection, $id, $document): void {
            assert($this->working !== null);
            $this->working['collections'][$collection][$id] = $document;
            $this->wrote = true;
        });
    }

    public function delete(string $collection, string $id): void
    {
        $this->schema->fields($collection);
        $this->transaction(function () use ($collection, $id): void {
            assert($this->working !== null);
            unset($this->working['collections'][$collection][$id]);
            $this->wrote = true;
        });
    }

    public function list(string $collection, array $where = [], ?string $orderBy = null, bool $descending = false, ?int $limit = null): array
    {
        $this->schema->checkQuery($collection, $where, $orderBy, $limit);
        $matches = array_filter(
            $this->contents()['collections'][$collection] ?? [],
            static function (array $document) use ($where): bool {
                foreach ($where as [$field, $op, $value]) {
                    $order = self::compare($document[$field], $value);
                    $keep = match ($op) {
                        '=' => $order === 0,
                        '<' => $order < 0,
                        '<=' => $order <= 0,
                        '>' => $order > 0,
                        default => $order >= 0, // '>=', the last operator Schema allows
                    };
                    if (!$keep) {
                        return false;
                    }
                }

                return true;
            },
        );
        // Strings compare bytewise, as SQLite's default collation does.
        usort($matches, static function (array $a, array $b) use ($orderBy, $descending): int {
            $order = $orderBy === null ? 0 : ($descending ? -1 : 1) * self::compare($a[$orderBy], $b[$orderBy]);

            return $order !== 0 ? $order : self::compare($a['id'], $b['id']);
        });

        return array_slice($matches, 0, $limit);
    }

    public function transaction(callable $work): mixed
    {
        if ($this->working !== null) {
            return $work();
        }
        $lock = $this->lock();
        try {
            $this->working = $this->read();
            $result = $work();
            if ($this->wrote) {
                $this->working['revision']++;
                $this->write($this->working);
            }

            return $result;
        } finally {
            $this->working = null;
            $this->wrote = false;
            self::unlock($lock);
        }
    }

    public function schemaVersion(): int
    {
        return $this->contents()['schema'];
    }

    public function revision(): int
    {
        return $this->contents()['revision'];
    }

    /** @return resource */
    private function lock()
    {
        $lock = fopen("$this->path.lock", 'c') ?: throw new RuntimeException("Cannot open the lock file of $this->path");
        flock($lock, LOCK_EX) || throw new RuntimeException("Cannot lock $this->path");

        return $lock;
    }

    /** @param resource $lock */
    private static function unlock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /** @return Contents */
    private function contents(): array
    {
        return $this->working ?? $this->read();
    }

    /** @return Contents */
    private function read(): array
    {
        $raw = file_get_contents($this->path);
        if ($raw === false || !str_starts_with($raw, self::GUARD)) {
            throw new RuntimeException("Unreadable or unguarded Store file: $this->path");
        }
        /** @var Contents */
        return json_decode(substr($raw, strlen(self::GUARD)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param Contents $contents */
    private function write(array $contents): void
    {
        // Escaping < and > keeps any "<?php" in a document from reopening PHP mode.
        $json = json_encode($contents, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        // A guarded .php name, so a temp file left by a crash leaks nothing over HTTP.
        $temp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp.php';
        try {
            $handle = fopen($temp, 'x');
            $written = $handle !== false && fwrite($handle, self::GUARD . $json) !== false && fflush($handle) && fsync($handle);
            $handle !== false && fclose($handle);
            if (!$written || !rename($temp, $this->path)) {
                throw new RuntimeException("Cannot write the Store file $this->path");
            }
        } catch (Throwable $e) {
            is_file($temp) && unlink($temp);
            throw $e;
        }
    }

    private static function compare(mixed $a, mixed $b): int
    {
        return is_string($a) && is_string($b) ? strcmp($a, $b) <=> 0 : $a <=> $b;
    }
}
