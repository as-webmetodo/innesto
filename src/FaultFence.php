<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use DateTimeZone;
use Innesto\Roots\Store\Store;
use Throwable;

/**
 * Wraps every call into Module code: a Throwable is logged with its context
 * and counted in the runtime Store with the last error, and the call gets
 * its fallback. The Module stays Active. While a call runs, the fence knows
 * which Module is in it, so PHP warnings and fatals are told apart.
 *
 * @phpstan-type Fault array{count: int, message: string, time: string}
 */
final class FaultFence
{
    private ?string $current = null;

    public function __construct(private readonly Store $runtime, private readonly RootsLog $log, private readonly Clock $clock)
    {
    }

    /**
     * @template T
     * @param callable(): T $work
     * @param T $fallback
     * @return T
     */
    public function call(string $id, callable $work, mixed $fallback): mixed
    {
        $outer = $this->current;
        $this->current = $id;
        try {
            return $work();
        } catch (Throwable $error) {
            $this->record($id, 'module.fault', $error::class . ': ' . $error->getMessage(), ['file' => $error->getFile(), 'line' => $error->getLine()]);

            return $fallback;
        } finally {
            $this->current = $outer;
        }
    }

    /** The Module whose code is running, if any. */
    public function current(): ?string
    {
        return $this->current;
    }

    /**
     * Logs an error of Module $id and counts it.
     *
     * @param array<string, mixed> $context
     */
    public function record(string $id, string $event, string $message, array $context = []): void
    {
        $this->log->write('error', $id, $event, $message, $context);
        $this->runtime->transaction(function () use ($id, $message): void {
            $count = $this->runtime->get('faults', $id)['count'] ?? 0;
            $this->runtime->put('faults', [
                'id' => $id,
                'count' => (is_int($count) ? $count : 0) + 1,
                'message' => $message,
                'time' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ]);
        });
    }

    /** @return Fault|null Module $id's error count and last error */
    public function fault(string $id): ?array
    {
        $fault = $this->runtime->get('faults', $id);
        if ($fault === null) {
            return null;
        }

        return is_int($fault['count'] ?? null) && is_string($fault['message'] ?? null) && is_string($fault['time'] ?? null)
            ? ['count' => $fault['count'], 'message' => $fault['message'], 'time' => $fault['time']]
            : null;
    }
}
