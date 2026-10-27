<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

return [
    'plural' => static fn (int $n): string => $n === 1 ? 'one' : 'other',
    'date.weekdays' => ['lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica'],
    'date.months' => ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
    'date.day_month' => '{d} {month}',
    'date.today' => 'oggi',
    'date.tomorrow' => 'domani',
    'time.pattern' => '{HH}:{mm}',
];
