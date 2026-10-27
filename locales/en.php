<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

// Roots' catalogue for English; it must hold every key.
return [
    'plural' => static fn (int $n): string => $n === 1 ? 'one' : 'other',
    'date.weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
    'date.months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    'date.day_month' => '{month} {d}',
    'date.today' => 'today',
    'date.tomorrow' => 'tomorrow',
    'time.pattern' => '{h}:{mm} {a}',
    'time.am' => 'AM',
    'time.pm' => 'PM',
];
