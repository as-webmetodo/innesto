<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

return [
    // A three-form rule, to prove forms found in en are chosen by en's rule.
    'plural' => static fn (int $n): string => $n === 1 ? 'one' : ($n % 10 >= 2 && $n % 10 <= 4 ? 'few' : 'many'),
    'apples' => ['one' => '{count} jabłko', 'few' => '{count} jabłka', 'many' => '{count} jabłek'],
];
