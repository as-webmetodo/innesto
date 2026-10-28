<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Store\FileStore;
use Innesto\Roots\Store\SqliteStore;
use Innesto\Roots\Store\Store;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;

/**
 * Roots' two Stores: content (credentials, the Shared config, the Roots
 * settings and each Module's state; its revision feeds the ETag) and runtime
 * (sessions, throttling, Module faults and the Self-check; it never does), and one Store per
 * Module on the same backend. Roots' Stores start on guarded files; when the
 * Self-check allows it, the first setup has the next request move them to
 * SQLite, and the backend then stays fixed.
 *
 * @phpstan-import-type StoreSchema from Store
 */
final class RootsStores
{
    private const CONTENT = ['schema' => 1, 'collections' => ['credentials' => ['fields' => []], 'config' => ['fields' => []], 'modules' => ['fields' => []]]];

    private const RUNTIME = ['schema' => 1, 'collections' => [
        'sessions' => ['fields' => ['area' => 'string', 'expires' => 'int']],
        'throttle' => ['fields' => ['last' => 'int']],
        'faults' => ['fields' => []],
        'health' => ['fields' => []],
    ]];

    /** Left by the first setup when Roots' Stores are to move to SQLite. */
    private const TO_SQLITE = 'roots-to-sqlite.php';

    private function __construct(public readonly Store $content, public readonly Store $runtime, private readonly string $dir, public readonly bool $sqlite)
    {
    }

    /**
     * Module $id's Store, created with $schema if it does not exist yet; an
     * existing one keeps the schema it was created with.
     *
     * @param StoreSchema $schema
     */
    public function module(string $id, array $schema): Store
    {
        return $this->sqlite ? SqliteStore::open($this->moduleFile($id), $schema) : FileStore::open($this->moduleFile($id), $schema);
    }

    public function moduleExists(string $id): bool
    {
        return is_file($this->moduleFile($id));
    }

    /** Removes Module $id's whole Store. */
    public function deleteModule(string $id): void
    {
        foreach ([$this->moduleFile($id), $this->moduleFile($id) . '.lock'] as $file) {
            is_file($file) && unlink($file);
        }
    }

    private function moduleFile(string $id): string
    {
        // Only a Package id is safe in a file name.
        preg_match(Manifest::ID, $id) === 1 || throw new InvalidArgumentException("Not a Package id: $id");

        return "$this->dir/module-$id." . ($this->sqlite ? 'sqlite' : 'php');
    }

    public static function open(DataDirectory $data): self
    {
        $base = "$data->path/roots-content";
        is_file("$data->path/" . self::TO_SQLITE) && self::moveToSqlite($data->path);

        return is_file("$base.sqlite")
            ? new self(SqliteStore::open("$base.sqlite", self::CONTENT), SqliteStore::open("$data->path/roots-runtime.sqlite", self::RUNTIME), $data->path, true)
            : new self(FileStore::open("$base.php", self::CONTENT), FileStore::open("$data->path/roots-runtime.php", self::RUNTIME), $data->path, false);
    }

    /**
     * Has the next open() move Roots' Stores from guarded files to SQLite.
     * The move waits for a new request because this one's objects (sessions,
     * the fault fence, the shutdown hook) keep writing to the file Stores.
     */
    public function moveToSqliteOnNextOpen(): void
    {
        $this->sqlite && throw new LogicException('Roots\' Stores are already on SQLite');
        file_put_contents("$this->dir/" . self::TO_SQLITE, "<?php exit; ?>\n");
    }

    /**
     * Copies Roots' two Stores into SQLite, then removes the files and the
     * marker, all under a lock on the marker, so concurrent requests move them
     * once. The content Store moves last: its SQLite file is what open() goes
     * by, so a move cut short is redone from the files. The revisions restart
     * at the move; no ETag exists before the first setup, so none repeats.
     */
    private static function moveToSqlite(string $dir): void
    {
        $marker = fopen("$dir/" . self::TO_SQLITE, 'r');
        if ($marker === false) {
            return;
        }
        try {
            flock($marker, LOCK_EX);
            // Another request may have moved them while this one waited.
            clearstatcache(true, "$dir/" . self::TO_SQLITE);
            if (!is_file("$dir/" . self::TO_SQLITE)) {
                return;
            }
            if (!is_file("$dir/roots-content.sqlite")) {
                foreach (['roots-runtime' => self::RUNTIME, 'roots-content' => self::CONTENT] as $name => $schema) {
                    $from = FileStore::open("$dir/$name.php", $schema);
                    $temp = "$dir/$name.sqlite.new";
                    is_file($temp) && unlink($temp);
                    $to = SqliteStore::open($temp, $schema);
                    $to->transaction(static function () use ($schema, $from, $to): void {
                        foreach (array_keys($schema['collections']) as $collection) {
                            foreach ($from->list($collection) as $document) {
                                $to->put($collection, $document);
                            }
                        }
                    });
                    rename($temp, "$dir/$name.sqlite");
                }
            }
            foreach (['roots-runtime', 'roots-content'] as $name) {
                foreach (["$dir/$name.php", "$dir/$name.php.lock"] as $file) {
                    is_file($file) && unlink($file);
                }
            }
            unlink("$dir/" . self::TO_SQLITE);
        } finally {
            flock($marker, LOCK_UN);
            fclose($marker);
        }
    }

    /** The SQLite version PDO links, or null without `pdo_sqlite`. */
    public static function sqliteVersion(): ?string
    {
        if (!extension_loaded('pdo_sqlite')) {
            return null;
        }
        try {
            $statement = (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()');
            $version = $statement === false ? false : $statement->fetchColumn();
        } catch (Throwable) {
            return null;
        }

        return is_string($version) ? $version : null;
    }
}
