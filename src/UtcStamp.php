<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use DateTimeZone;

/**
 * The one format of the times Roots stores and logs. The home badge compares
 * them as strings, so every writer must use it.
 */
final class UtcStamp
{
    public static function now(Clock $clock): string
    {
        return $clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
