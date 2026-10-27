<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use DateTimeImmutable;

/** Where Roots reads the time, so tests can fix it. */
interface Clock
{
    public function now(): DateTimeImmutable;
}
