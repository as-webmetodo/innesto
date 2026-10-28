<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Store\FileStore;
use Innesto\Roots\Store\SqliteStore;
use Innesto\Roots\Store\Store;
use PDO;
use Throwable;

/**
 * Roots' two Stores: content (credentials, the Shared config and the Roots
 * settings; its revision feeds the ETag) and runtime (sessions and
 * throttling; it never does). The backend is chosen when they are created
 * and stays fixed: SQLite only with SQLite 3.27+ and a Data directory
 * outside the webroot, guarded files otherwise. #30's HTTP
 * probe will let a proven-unreachable in-webroot directory use SQLite too.
 */
final class RootsStores
{
    private const CONTENT = ['schema' => 1, 'collections' => ['credentials' => ['fields' => []], 'config' => ['fields' => []]]];

    private const RUNTIME = ['schema' => 1, 'collections' => [
        'sessions' => ['fields' => ['area' => 'string', 'expires' => 'int']],
        'throttle' => ['fields' => ['last' => 'int']],
    ]];

    private function __construct(public readonly Store $content, public readonly Store $runtime)
    {
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
            ? new self(SqliteStore::open("$base.sqlite", self::CONTENT), SqliteStore::open("$data->path/roots-runtime.sqlite", self::RUNTIME))
            : new self(FileStore::open("$base.php", self::CONTENT), FileStore::open("$data->path/roots-runtime.php", self::RUNTIME));
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
