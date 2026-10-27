<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Auth;

use Innesto\Roots\Clock;
use Innesto\Roots\Store\Store;

/**
 * Sign-in throttling for one area, in the runtime Store. Per IP: 5 free
 * failures, then a wait of 1 s doubling per failure up to 15 minutes. Past
 * 100 failures in the area within an hour, IPs that failed in that hour are
 * refused too, so a clean device still signs in. Roots never waits itself
 * and never locks the account. The full IP lives only in this short-lived
 * state: an IP's record goes once its last failure is an hour old.
 */
final class Throttle
{
    private const FREE = 5;

    private const MAX_WAIT = 900;

    private const WINDOW = 3600;

    private const CAP = 100;

    public function __construct(private readonly Store $runtime, private readonly Clock $clock, private readonly string $area)
    {
    }

    /** The seconds $ip must wait before its next attempt, or null when it may try now. */
    public function retryAfter(string $ip): ?int
    {
        $now = $this->clock->now()->getTimestamp();
        $record = $this->ipRecord($ip, $now);
        if ($record === null) {
            return null;
        }
        if ($record['until'] > $now) {
            return $record['until'] - $now;
        }
        $recent = $this->recentFailures($now);

        return count($recent) >= self::CAP ? max(1, min($recent) + self::WINDOW - $now) : null;
    }

    public function fail(string $ip): void
    {
        $this->runtime->transaction(function () use ($ip): void {
            $now = $this->clock->now()->getTimestamp();
            $failures = ($this->ipRecord($ip, $now)['failures'] ?? 0) + 1;
            $wait = $failures < self::FREE ? 0 : min(2 ** min($failures - self::FREE, 10), self::MAX_WAIT);
            $this->runtime->put('throttle', ['id' => $this->ipId($ip), 'last' => $now, 'failures' => $failures, 'until' => $now + $wait]);
            $recent = [...$this->recentFailures($now), $now];
            // The newest CAP failures are enough to tell whether the cap is hit.
            $this->runtime->put('throttle', ['id' => "$this->area global", 'last' => $now, 'failures' => array_slice($recent, -self::CAP)]);
            foreach ($this->runtime->list('throttle', [['last', '<=', $now - self::WINDOW]]) as ['id' => $id]) {
                is_string($id) && $this->runtime->delete('throttle', $id);
            }
        });
    }

    public function succeed(string $ip): void
    {
        $this->runtime->delete('throttle', $this->ipId($ip));
    }

    /** @return array{failures: int, until: int}|null the IP's record while its last failure is under an hour old */
    private function ipRecord(string $ip, int $now): ?array
    {
        $record = $this->runtime->get('throttle', $this->ipId($ip));
        if ($record === null || !is_int($record['last']) || $record['last'] <= $now - self::WINDOW) {
            return null;
        }

        return ['failures' => is_int($record['failures']) ? $record['failures'] : 0, 'until' => is_int($record['until']) ? $record['until'] : 0];
    }

    /** @return list<int> the area's failure times within the last hour */
    private function recentFailures(int $now): array
    {
        $failures = $this->runtime->get('throttle', "$this->area global")['failures'] ?? [];

        return array_values(array_filter(is_array($failures) ? $failures : [], fn (mixed $time): bool => is_int($time) && $time > $now - self::WINDOW));
    }

    private function ipId(string $ip): string
    {
        return "$this->area ip $ip";
    }
}
