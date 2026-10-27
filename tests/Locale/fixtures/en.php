<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

return [
    'plural' => static fn (int $n): string => $n === 1 ? 'one' : 'other',
    'greeting' => 'Hello, {name}',
    'only_en' => 'English only',
    'apples' => ['one' => '{count} apple', 'other' => '{count} apples'],
    'fewish' => ['one' => '{count} thing', 'other' => '{count} things'],
];
