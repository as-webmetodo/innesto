<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Auth\Sessions;
use Innesto\Roots\Store\FileStore;
use Innesto\Tests\FixedClock;

test('starting a session deletes the expired ones', function (): void {
    $runtime = FileStore::open(sys_get_temp_dir() . '/innesto-sessions-' . bin2hex(random_bytes(6)) . '.php', ['schema' => 1, 'collections' => [
        'sessions' => ['fields' => ['area' => 'string', 'expires' => 'int']],
    ]]);
    $clock = new FixedClock(new DateTimeImmutable('2026-10-27T10:00:00Z'));
    $sessions = new Sessions($runtime, $clock, 'dev', 12 * 3600);
    $expired = $sessions->start();
    $clock->advance(12 * 3600);

    $live = $sessions->start();

    expect($runtime->list('sessions'))->toHaveCount(1)
        ->and($sessions->find($expired))->toBeNull()
        ->and($sessions->find($live))->not->toBeNull();
});
