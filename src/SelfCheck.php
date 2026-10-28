<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use DateTimeZone;
use Innesto\Roots\Http\Request;

/**
 * Roots' examination of its host, graded Fail, Warn or OK. The cheap server
 * probes run on every Developer-area load; the HTTP probes run in the
 * Developer's browser against Roots' own origin and are posted back. Each
 * run is stored in the runtime Store with its time, and a Fail is logged
 * when it appears. The first setup also picks the storage backend here.
 *
 * @phpstan-type Grade 'ok'|'warn'|'fail'
 * @phpstan-type Finding array{id: string, grade: Grade, params: array<string, string|int>}
 * @phpstan-type Run array{time: string, findings: list<Finding>}
 * @phpstan-type ServerFacts array{php: string, sqlite: string|null, mail: bool, max_execution_time: string, memory_limit: string, upload_max_filesize: string, post_max_size: string, data_dir: 'outside'|'webroot'|'other_webroot', https: bool, loopback: bool, profile_complete: bool, site_url_default: bool}
 * @phpstan-type BrowserResults array{data_txt: bool, data_php: bool, htaccess: bool, skew: int}
 */
final class SelfCheck
{
    /** What the probe files hold, so a soft-404 page is never taken for one. */
    public const MARKER = 'innesto-probe';

    private const SKEW = 120;

    private const MB = 1024 * 1024;

    public function __construct(
        private readonly RootsStores $stores,
        private readonly DataDirectory $data,
        private readonly RootsLog $log,
        private readonly Clock $clock,
        private readonly Request $request,
    ) {
    }

    /**
     * Grades the server-side facts against §3.11's thresholds.
     *
     * @param ServerFacts $facts
     * @return list<Finding>
     */
    public static function serverFindings(array $facts): array
    {
        $sqlite = $facts['sqlite'] !== null && version_compare($facts['sqlite'], '3.27', '>=');
        $seconds = (int) $facts['max_execution_time'];
        $memory = self::bytes($facts['memory_limit']);
        $upload = min(self::bytes($facts['upload_max_filesize']), self::bytes($facts['post_max_size']));

        return [
            self::finding('php', version_compare($facts['php'], '8.3', '<') ? 'fail' : 'ok', ['version' => $facts['php']]),
            match (true) {
                $facts['https'] => self::finding('https', 'ok'),
                $facts['loopback'] => self::finding('https_localhost', 'ok'),
                default => self::finding('https', 'fail'),
            },
            self::finding('sqlite', $sqlite ? 'ok' : 'warn', ['version' => $facts['sqlite'] ?? '']),
            self::finding('mail', $facts['mail'] ? 'ok' : 'warn'),
            match ($facts['data_dir']) {
                'outside' => self::finding('data_dir', 'ok'),
                'webroot' => self::finding('data_dir', 'warn'),
                'other_webroot' => self::finding('data_dir_other', 'warn'),
            },
            self::finding('max_execution_time', $seconds !== 0 && $seconds < 30 ? 'warn' : 'ok', ['value' => $facts['max_execution_time']]),
            self::finding('memory_limit', $memory !== -1 && $memory < 64 * self::MB ? 'warn' : 'ok', ['value' => $facts['memory_limit']]),
            self::finding('upload_size', $upload < 2 * self::MB ? 'warn' : 'ok', ['upload' => $facts['upload_max_filesize'], 'post' => $facts['post_max_size']]),
            self::finding('profile', $facts['profile_complete'] ? 'ok' : 'warn'),
            self::finding('site_url', $facts['site_url_default'] ? 'warn' : 'ok'),
        ];
    }

    /**
     * Grades the browser's HTTP probes. After install, a reachable Data
     * directory on SQLite exposes the whole database: that is a Fail.
     *
     * @param BrowserResults $results
     * @return list<Finding>
     */
    public static function browserFindings(array $results, bool $sqlite): array
    {
        return [
            self::finding('data_php', $results['data_php'] ? 'fail' : 'ok'),
            self::finding('data_txt', $results['data_txt'] ? ($sqlite ? 'fail' : 'warn') : 'ok'),
            self::finding('htaccess', $results['htaccess'] ? 'ok' : 'warn'),
            self::finding('clock', abs($results['skew']) > self::SKEW ? 'warn' : 'ok', ['seconds' => $results['skew']]),
        ];
    }

    /**
     * The browser's posted probe results, or null unless they are exactly
     * the expected JSON object.
     *
     * @return BrowserResults|null
     */
    public static function parse(string $json): ?array
    {
        $results = json_decode($json, true);
        if (!is_array($results) || array_keys($results) !== ['data_txt', 'data_php', 'htaccess', 'skew']) {
            return null;
        }

        return is_bool($results['data_txt']) && is_bool($results['data_php']) && is_bool($results['htaccess']) && is_int($results['skew'])
            ? ['data_txt' => $results['data_txt'], 'data_php' => $results['data_php'], 'htaccess' => $results['htaccess'], 'skew' => $results['skew']]
            : null;
    }

    /**
     * Why the first setup puts Roots' Stores where it does: SQLite needs a
     * usable SQLite and a Data directory proven unreachable over HTTP.
     *
     * @param BrowserResults $results
     */
    public function backendReason(array $results, bool $files): string
    {
        $version = RootsStores::sqliteVersion();

        return match (true) {
            $version === null || version_compare($version, '3.27', '<') => 'no_sqlite',
            $this->data->insideOtherWebroot => 'other_webroot',
            $results['data_txt'] => 'reachable',
            $files => 'chosen',
            default => 'sqlite',
        };
    }

    /**
     * Re-runs the server probes and stores them.
     *
     * @return list<Finding>
     */
    public function runServer(): array
    {
        $config = SharedConfig::fromStore($this->stores->content, $this->request->origin());
        $findings = self::serverFindings([
            'php' => PHP_VERSION,
            'sqlite' => RootsStores::sqliteVersion(),
            'mail' => Mail::available(),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'data_dir' => match (true) {
                $this->data->insideWebroot => 'webroot',
                $this->data->insideOtherWebroot => 'other_webroot',
                default => 'outside',
            },
            'https' => $this->request->https,
            'loopback' => $this->request->loopback(),
            'profile_complete' => !$config->incompleteBusinessProfile(),
            'site_url_default' => $config->siteUrlIsDefault(),
        ]);
        $this->store('server', $findings);

        return $findings;
    }

    /**
     * Grades and stores the browser's probe results.
     *
     * @param BrowserResults $results
     * @return list<Finding>
     */
    public function runBrowser(array $results): array
    {
        $findings = self::browserFindings($results, $this->stores->sqlite);
        $this->store('browser', $findings);

        return $findings;
    }

    /**
     * At the end of the first setup: records why the backend is what it is
     * and moves Roots' Stores to SQLite when that is allowed. It must be the
     * request's last write.
     *
     * @param BrowserResults $results
     */
    public function settleBackend(array $results, bool $files): void
    {
        $reason = $this->backendReason($results, $files);
        $this->stores->runtime->put('health', ['id' => 'backend', 'reason' => $reason]);
        if ($reason === 'sqlite' && !$this->stores->sqlite) {
            $this->stores->moveToSqlite();
        }
    }

    /**
     * The probe URLs, relative to Roots' own, and the server's time for the
     * clock-skew probe. The Data directory's probe files are written here.
     *
     * @return array{txt: string, php: string, htaccess: string, now: int, marker: string}
     */
    public function probes(): array
    {
        $dir = $this->data->path;
        is_file("$dir/probe.txt") || file_put_contents("$dir/probe.txt", self::MARKER . "\n");
        is_file("$dir/probe.php") || file_put_contents("$dir/probe.php", "<?php exit; ?>\n" . self::MARKER . "\n");
        $base = $this->request->basePath . ($this->data->fromRoots === '' ? '' : $this->data->fromRoots . '/');

        return [
            'txt' => $base . 'probe.txt',
            'php' => $base . 'probe.php',
            'htaccess' => $this->request->basePath . 'templates/probe.txt',
            'now' => $this->clock->now()->getTimestamp() * 1000,
            'marker' => self::MARKER,
        ];
    }

    /** @return array{server: Run|null, browser: Run|null, backend: string|null} the latest runs and the backend's reason */
    public function latest(): array
    {
        $reason = $this->stores->runtime->get('health', 'backend')['reason'] ?? null;

        return [
            'server' => $this->run('server'),
            'browser' => $this->run('browser'),
            'backend' => is_string($reason) ? $reason : null,
        ];
    }

    /** Whether Roots' Stores are on SQLite. */
    public function sqlite(): bool
    {
        return $this->stores->sqlite;
    }

    /** @param list<Finding> $findings */
    public static function failed(array $findings): bool
    {
        return in_array('fail', array_column($findings, 'grade'), true);
    }

    /** @param list<Finding> $findings */
    private function store(string $kind, array $findings): void
    {
        $before = $this->run($kind);
        $failing = $before === null ? [] : array_column(array_filter($before['findings'], static fn (array $finding): bool => $finding['grade'] === 'fail'), 'id');
        foreach ($findings as $finding) {
            if ($finding['grade'] === 'fail' && !in_array($finding['id'], $failing, true)) {
                $this->log->write('error', 'roots', 'selfcheck.fail', "Self-check Fail: {$finding['id']}", ['finding' => $finding['id']]);
            }
        }
        $this->stores->runtime->put('health', [
            'id' => $kind,
            'time' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'findings' => $findings,
        ]);
    }

    /** @return Run|null */
    private function run(string $kind): ?array
    {
        $run = $this->stores->runtime->get('health', $kind);
        if (!is_string($run['time'] ?? null) || !is_array($run['findings'] ?? null)) {
            return null;
        }
        $findings = [];
        foreach ($run['findings'] as $finding) {
            if (is_array($finding) && is_string($finding['id'] ?? null) && in_array($finding['grade'] ?? null, ['ok', 'warn', 'fail'], true) && is_array($finding['params'] ?? null)) {
                /** @var array<string, string|int> $params */
                $params = array_filter($finding['params'], static fn (mixed $value): bool => is_string($value) || is_int($value));
                $findings[] = self::finding($finding['id'], $finding['grade'], $params);
            }
        }

        return ['time' => $run['time'], 'findings' => $findings];
    }

    /**
     * @param Grade $grade
     * @param array<string, string|int> $params
     * @return Finding
     */
    private static function finding(string $id, string $grade, array $params = []): array
    {
        return ['id' => $id, 'grade' => $grade, 'params' => $params];
    }

    /** An ini size such as `128M` in bytes; -1 stays -1. */
    private static function bytes(string $value): int
    {
        $number = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $number * 1024 * self::MB,
            'M' => $number * self::MB,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
