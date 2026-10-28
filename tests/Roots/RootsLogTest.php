<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\RootsLog;
use Innesto\Tests\FixedClock;

function logDir(): string
{
    $dir = sys_get_temp_dir() . '/innesto-log-' . bin2hex(random_bytes(6));
    mkdir($dir);

    return $dir;
}

test('a line holds UTC time, request id, level, source, event, message and context', function (): void {
    $dir = logDir();
    $log = new RootsLog($dir, new FixedClock(new DateTimeImmutable('2026-10-27T12:30:00+02:00')), 'req1');

    $log->write('warning', 'roots', 'signin.failed', 'Wrong Developer password', ['area' => 'dev']);

    expect(logLines("$dir/roots-log.php"))->toBe([[
        'time' => '2026-10-27T10:30:00Z',
        'request' => 'req1',
        'level' => 'warning',
        'source' => 'roots',
        'event' => 'signin.failed',
        'message' => 'Wrong Developer password',
        'context' => ['area' => 'dev'],
    ]]);
});

test('IPs are truncated to /24 and /48', function (string $ip, string $truncated): void {
    $dir = logDir();
    $log = new RootsLog($dir, new FixedClock(new DateTimeImmutable('2026-10-27T10:00:00Z')), 'req1');

    $log->write('info', 'roots', 'throttled', 'Too many failures', ['ip' => $ip]);

    expect(logLines("$dir/roots-log.php")[0]['context'])->toBe(['ip' => $truncated]);
})->with([
    ['203.0.113.7', '203.0.113.0'],
    ['2001:db8:abcd:12::1', '2001:db8:abcd::'],
]);

test('past about 1 MB the log is renamed over its one previous generation', function (): void {
    $dir = logDir();
    $log = new RootsLog($dir, new FixedClock(new DateTimeImmutable('2026-10-27T10:00:00Z')), 'req1');
    file_put_contents("$dir/roots-log.1.php", "<?php exit; ?>\nold generation\n");

    $log->write('info', 'roots', 'big', str_repeat('x', 1_000_001));
    $log->write('info', 'roots', 'after', 'First line of the new generation');

    expect(array_column(logLines("$dir/roots-log.1.php"), 'event'))->toBe(['big'])
        ->and(array_column(logLines("$dir/roots-log.php"), 'event'))->toBe(['after']);
});

test('the log keeps the time of its latest error, guarded, for the home badge', function (): void {
    $dir = logDir();
    $clock = new FixedClock(new DateTimeImmutable('2026-10-27T10:00:00Z'));
    $log = new RootsLog($dir, $clock, 'req1');
    $log->write('error', 'acme.failing', 'module.fault', 'RuntimeException: boom');
    $clock->advance(60);

    $log->write('warning', 'roots', 'signin.failed', 'Wrong Developer password');
    $log->write('info', 'roots', 'setup.completed', 'Setup code used');

    expect($log->lastError())->toBe('2026-10-27T10:00:00Z')
        ->and(file_get_contents("$dir/roots-log-error.php"))->toStartWith("<?php exit; ?>\n");
});

test('a log without errors has no latest error', function (): void {
    $log = new RootsLog(logDir(), new FixedClock(new DateTimeImmutable('2026-10-27T10:00:00Z')), 'req1');

    $log->write('info', 'roots', 'setup.completed', 'Setup code used');

    expect($log->lastError())->toBeNull();
});
