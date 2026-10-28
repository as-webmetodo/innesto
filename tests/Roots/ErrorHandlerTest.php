<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\ErrorHandler;
use Innesto\Roots\FaultFence;
use Innesto\Roots\RootsLog;
use Innesto\Roots\Store\FileStore;
use Innesto\Tests\FixedClock;

/** @return array{handler: ErrorHandler, fence: FaultFence, dir: string} a handler attached to a fresh log and fence */
function attachedHandler(): array
{
    $dir = sys_get_temp_dir() . '/innesto-errors-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $clock = new FixedClock(new DateTimeImmutable('2026-10-27T10:00:00Z'));
    $log = new RootsLog($dir, $clock, 'req');
    $fence = new FaultFence(FileStore::open("$dir/runtime.php", ['schema' => 1, 'collections' => ['faults' => ['fields' => []]]]), $log, $clock);
    $handler = new ErrorHandler();
    $handler->attach($log, $fence);

    return ['handler' => $handler, 'fence' => $fence, 'dir' => $dir];
}

test('a PHP warning goes to the Roots log, tagged with the Module whose code was running', function (): void {
    ['handler' => $handler, 'fence' => $fence, 'dir' => $dir] = attachedHandler();

    // Whatever the host's own error_reporting.
    $reporting = error_reporting(E_ALL);
    try {
        $handled = $handler->handleError(E_WARNING, 'Undefined variable $x', '/roots/src/App.php', 12);
        $fence->call('acme.probe', fn () => $handler->handleError(E_USER_WARNING, 'Careful', '/m.php', 3), null);
    } finally {
        error_reporting($reporting);
    }
    $lines = logLines("$dir/roots-log.php");

    expect($handled)->toBeTrue()
        ->and(array_column($lines, 'event'))->toBe(['php.warning', 'php.warning'])
        ->and(array_column($lines, 'source'))->toBe(['roots', 'acme.probe'])
        ->and($lines[0]['message'] ?? null)->toBe('Undefined variable $x')
        ->and($lines[0]['context'] ?? null)->toBe(['level' => E_WARNING, 'file' => '/roots/src/App.php', 'line' => 12])
        ->and($fence->fault('acme.probe'))->toBeNull();
});

test('a silenced warning is not logged', function (): void {
    ['handler' => $handler, 'dir' => $dir] = attachedHandler();
    $reporting = error_reporting(0);
    try {
        $handler->handleError(E_WARNING, 'Silenced');
    } finally {
        error_reporting($reporting);
    }

    expect(is_file("$dir/roots-log.php"))->toBeFalse();
});

test('a fatal inside Module code is logged and counted against the Module', function (): void {
    ['handler' => $handler, 'fence' => $fence, 'dir' => $dir] = attachedHandler();

    $fence->call('acme.probe', fn () => $handler->shutdown(['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => '/m.php', 'line' => 9]), null);
    $handler->shutdown(['type' => E_WARNING, 'message' => 'Not fatal', 'file' => '/m.php', 'line' => 1]);
    $handler->shutdown(null);
    $lines = logLines("$dir/roots-log.php");

    expect(array_column($lines, 'event'))->toBe(['php.fatal'])
        ->and($lines[0]['source'] ?? null)->toBe('acme.probe')
        ->and($fence->fault('acme.probe'))->toBe(['count' => 1, 'message' => 'Allowed memory size exhausted', 'time' => '2026-10-27T10:00:00Z']);
});

test('a fatal or an uncaught error in Roots is logged as Roots\'', function (): void {
    ['handler' => $handler, 'dir' => $dir] = attachedHandler();

    $handler->shutdown(['type' => E_COMPILE_ERROR, 'message' => 'Cannot redeclare', 'file' => '/r.php', 'line' => 2]);
    $handler->uncaught(new RuntimeException('Disk full'));
    $lines = logLines("$dir/roots-log.php");

    expect(array_column($lines, 'event'))->toBe(['php.fatal', 'php.uncaught'])
        ->and(array_column($lines, 'source'))->toBe(['roots', 'roots'])
        ->and($lines[1]['message'] ?? null)->toBe('RuntimeException: Disk full');
});

test('before the Data directory is located, errors go to error_log()', function (): void {
    $file = sys_get_temp_dir() . '/innesto-error-log-' . bin2hex(random_bytes(6));
    $previous = ini_set('error_log', $file);
    try {
        (new ErrorHandler())->uncaught(new RuntimeException('No Data directory'));
    } finally {
        ini_set('error_log', (string) $previous);
    }

    expect((string) file_get_contents($file))->toContain('Innesto Roots: php.uncaught: RuntimeException: No Data directory');
});
