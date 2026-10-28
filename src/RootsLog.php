<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use DateTimeZone;
use Innesto\Roots\Store\Schema;
use RuntimeException;

/**
 * The Roots log: one guarded file of JSON lines in the Data directory, kept in
 * two generations. Appends and rotation happen under one lock file; an `ip`
 * in the context is always truncated (/24 for IPv4, /48 for IPv6).
 */
final class RootsLog
{
    private const GUARD = "<?php exit; ?>\n";

    private const ROTATE_AT = 1_000_000;

    public function __construct(private readonly string $dir, private readonly Clock $clock, private readonly string $requestId)
    {
    }

    /** @param array<string, mixed> $context */
    public function write(string $level, string $source, string $event, string $message, array $context = []): void
    {
        if (is_string($context['ip'] ?? null)) {
            $context['ip'] = self::truncateIp($context['ip']);
        }
        $line = json_encode([
            'time' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'request' => $this->requestId,
            'level' => $level,
            'source' => $source,
            'event' => $event,
            'message' => $message,
            'context' => (object) $context,
        ], Schema::JSON_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

        $lock = fopen("$this->dir/roots-log.lock", 'c') ?: throw new RuntimeException('Cannot open the Roots log lock');
        try {
            flock($lock, LOCK_EX);
            $file = "$this->dir/roots-log.php";
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) > self::ROTATE_AT) {
                rename($file, "$this->dir/roots-log.1.php");
            }
            clearstatcache(true, $file);
            $header = !is_file($file) || filesize($file) === 0 ? self::GUARD : '';
            file_put_contents($file, $header . $line, FILE_APPEND);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function truncateIp(string $ip): string
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return '';
        }
        $kept = strlen($packed) === 4 ? 3 : 6;

        return (string) inet_ntop(substr($packed, 0, $kept) . str_repeat("\0", strlen($packed) - $kept));
    }
}
