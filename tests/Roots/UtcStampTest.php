<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\UtcStamp;
use Innesto\Tests\FixedClock;

test('a stamp is the clock\'s time in UTC, to the second', function (): void {
    expect(UtcStamp::now(new FixedClock(new DateTimeImmutable('2026-10-27T12:30:45.5+02:00'))))->toBe('2026-10-27T10:30:45Z');
});
