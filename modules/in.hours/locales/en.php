<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

// Innesto Orario's catalogue for English; it must hold every key.
return [
    'open.today' => 'Open · closes {time}',
    'open.day' => 'Open · closes {day} at {time}',
    'open.date' => 'Open · closes on {date} at {time}',
    'open.until_further_notice' => 'Open until further notice',
    'closed.today' => 'Closed · opens at {time}',
    'closed.day' => 'Closed · reopens {day} at {time}',
    'closed.date' => 'Closed · reopens on {date} at {time}',
    'closed.until_further_notice' => 'Closed until further notice',
];
