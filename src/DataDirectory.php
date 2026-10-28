<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use RuntimeException;

/**
 * Where Roots keeps its data. At setup it walks up from `<roots>` to a
 * writable directory outside the webroot and creates `innesto-data-<random>/`
 * there, else `<roots>/data-<random>/` denied to HTTP; the choice is recorded
 * in `<roots>/data-path.php`, which the Developer may edit to move it.
 */
final class DataDirectory
{
    private const DENY_ALL = "Require all denied\n";

    /** @param string|null $upload the data-path.php to upload over FTP when `<roots>` is not writable */
    private function __construct(public readonly string $path, public readonly bool $insideWebroot, public readonly ?string $upload)
    {
    }

    /** @param string $top the highest directory the walk may reach */
    public static function locate(string $roots, string $documentRoot, string $top = '/'): self
    {
        $webroot = self::canonical($documentRoot);
        $record = "$roots/data-path.php";
        if (is_file($record)) {
            $path = require $record;
            if (!is_string($path) || !str_starts_with($path, '/')) {
                throw new RuntimeException("$record must return an absolute path");
            }

            $path = self::create($path, $webroot);

            return new self($path, self::isInside($path, $webroot), null);
        }

        $suffix = bin2hex(random_bytes(8));
        $path = self::outsideWebroot(self::canonical($roots), $webroot, self::canonical($top), $suffix) ?? "$roots/data-$suffix";
        $contents = self::record($path);
        if (!is_writable($roots)) {
            if (self::isInside($path, $roots)) {
                throw new RuntimeException('No writable directory for the Data directory, outside the webroot or in <roots>');
            }

            return new self($path, self::isInside($path, $webroot), $contents);
        }
        $path = self::create($path, $webroot);
        file_put_contents($record, $contents, LOCK_EX);
        // Within opcache.revalidate_freq, OPcache would still serve a replaced
        // record's old path to the next request. With restrict_api set, the
        // call would only raise a warning.
        function_exists('opcache_invalidate') && ini_get('opcache.restrict_api') === '' && opcache_invalidate($record, true);

        return new self($path, self::isInside($path, $webroot), null);
    }

    private static function outsideWebroot(string $roots, string $webroot, string $top, string $suffix): ?string
    {
        for ($dir = dirname($roots); ; $dir = dirname($dir)) {
            if (!self::isInside($dir, $webroot) && is_dir($dir) && is_writable($dir)) {
                return ($dir === '/' ? '' : $dir) . "/innesto-data-$suffix";
            }
            if ($dir === $top || $dir === dirname($dir)) {
                return null;
            }
        }
    }

    /** @return string the Data directory's canonical path, so a symlink or `..` cannot hide that it is inside the webroot */
    private static function create(string $path, string $webroot): string
    {
        if (!is_dir($path) && !mkdir($path, 0o755) && !is_dir($path)) {
            throw new RuntimeException("Cannot create the Data directory $path");
        }
        $path = self::canonical($path);
        if (!is_writable($path)) {
            throw new RuntimeException("The Data directory $path is not writable");
        }
        if (self::isInside($path, $webroot) && !is_file("$path/.htaccess")) {
            file_put_contents("$path/.htaccess", self::DENY_ALL);
        }

        return $path;
    }

    private static function record(string $path): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\ndefined('INNESTO_ROOTS') || exit;\n\n"
            . "// The Data directory of this Innesto install; move it by editing this path.\n"
            . 'return ' . var_export($path, true) . ";\n";
    }

    private static function isInside(string $path, string $dir): bool
    {
        return $path === $dir || str_starts_with($path, rtrim($dir, '/') . '/');
    }

    private static function canonical(string $path): string
    {
        return realpath($path) ?: rtrim($path, '/');
    }
}
