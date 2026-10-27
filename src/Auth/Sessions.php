<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Auth;

use Innesto\Roots\Clock;
use Innesto\Roots\Store\Store;

/**
 * Roots-owned sessions of one area: the cookie holds a random token, the
 * runtime Store only its hash, with a sliding idle expiry written at most
 * every 5 minutes and a CSRF synchronizer token.
 */
final class Sessions
{
    private const REFRESH = 300;

    public function __construct(private readonly Store $runtime, private readonly Clock $clock, private readonly string $area, private readonly int $idle)
    {
    }

    /** Deletes expired sessions, then starts one and returns its token. */
    public function start(): string
    {
        $now = $this->clock->now()->getTimestamp();
        $token = bin2hex(random_bytes(32));
        $this->runtime->transaction(function () use ($now, $token): void {
            foreach ($this->runtime->list('sessions', [['expires', '<=', $now]]) as ['id' => $id]) {
                is_string($id) && $this->runtime->delete('sessions', $id);
            }
            $this->runtime->put('sessions', ['id' => self::hash($token), 'area' => $this->area, 'expires' => $now + $this->idle, 'csrf' => bin2hex(random_bytes(32))]);
        });

        return $token;
    }

    /**
     * The live session for $token, renewing its expiry when the last renewal
     * is over 5 minutes old.
     *
     * @return array{csrf: string}|null
     */
    public function find(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $session = $this->runtime->get('sessions', self::hash($token));
        $now = $this->clock->now()->getTimestamp();
        if ($session === null || $session['area'] !== $this->area || !is_int($session['expires']) || $session['expires'] <= $now || !is_string($session['csrf'])) {
            return null;
        }
        if ($now - ($session['expires'] - $this->idle) >= self::REFRESH) {
            $this->runtime->put('sessions', ['expires' => $now + $this->idle] + $session);
        }

        return ['csrf' => $session['csrf']];
    }

    public function end(string $token): void
    {
        $this->runtime->delete('sessions', self::hash($token));
    }

    public function endAll(): void
    {
        $this->runtime->transaction(function (): void {
            foreach ($this->runtime->list('sessions', [['area', '=', $this->area]]) as ['id' => $id]) {
                is_string($id) && $this->runtime->delete('sessions', $id);
            }
        });
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
