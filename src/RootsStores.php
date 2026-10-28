<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Store\FileStore;
use Innesto\Roots\Store\SqliteStore;
use Innesto\Roots\Store\Store;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Roots' two Stores: content (credentials, the Shared config, the Roots
 * settings and each Module's state; its revision feeds the ETag) and runtime
 * (sessions, throttling and Module faults; it never does), and one Store per
 * Module on the same backend. The backend is chosen when Roots' Stores are
 * created and stays fixed: SQLite only with SQLite 3.27+ and a Data directory
 * outside the webroot, guarded files otherwise. #30's HTTP
 * probe will let a proven-unreachable in-webroot directory use SQLite too.
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
    ]];

    private function __construct(public readonly Store $content, public readonly Store $runtime, private readonly string $dir, private readonly bool $sqlite)
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
        $sqlite = match (true) {
            is_file("$base.sqlite") => true,
            is_file("$base.php") => false,
            default => !$data->insideWebroot && self::sqliteAvailable(),
        };

        return $sqlite
            ? new self(SqliteStore::open("$base.sqlite", self::CONTENT), SqliteStore::open("$data->path/roots-runtime.sqlite", self::RUNTIME), $data->path, true)
            : new self(FileStore::open("$base.php", self::CONTENT), FileStore::open("$data->path/roots-runtime.php", self::RUNTIME), $data->path, false);
    }

    private static function sqliteAvailable(): bool
    {
        if (!extension_loaded('pdo_sqlite')) {
            return false;
        }
        try {
            $statement = (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()');
            $version = $statement === false ? false : $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }

        return is_string($version) && version_compare($version, '3.27', '>=');
    }
}
