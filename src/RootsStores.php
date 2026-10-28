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
 * Module on the same backend. Roots' Stores start on guarded files; the first
 * setup moves them to SQLite when the Self-check allows it, and the backend
 * then stays fixed.
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

        return is_file("$base.sqlite")
            ? new self(SqliteStore::open("$base.sqlite", self::CONTENT), SqliteStore::open("$data->path/roots-runtime.sqlite", self::RUNTIME), $data->path, true)
            : new self(FileStore::open("$base.php", self::CONTENT), FileStore::open("$data->path/roots-runtime.php", self::RUNTIME), $data->path, false);
    }

    /**
     * Copies Roots' two Stores from guarded files into SQLite and removes the
     * files. Only the first setup does this, before any Module has a Store,
     * and as the last write of its request. The content Store moves last: its
     * SQLite file is what open() goes by.
     */
    public function moveToSqlite(): void
    {
        $this->sqlite && throw new LogicException('Roots\' Stores are already on SQLite');
        foreach ([['roots-runtime', self::RUNTIME, $this->runtime], ['roots-content', self::CONTENT, $this->content]] as [$name, $schema, $from]) {
            $temp = "$this->dir/$name.sqlite.new";
            is_file($temp) && unlink($temp);
            $to = SqliteStore::open($temp, $schema);
            $to->transaction(static function () use ($schema, $from, $to): void {
                foreach (array_keys($schema['collections']) as $collection) {
                    foreach ($from->list($collection) as $document) {
                        $to->put($collection, $document);
                    }
                }
            });
            rename($temp, "$this->dir/$name.sqlite");
        }
        foreach (['roots-runtime', 'roots-content'] as $name) {
            foreach (["$this->dir/$name.php", "$this->dir/$name.php.lock"] as $file) {
                is_file($file) && unlink($file);
            }
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
