<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

return [
    'plural' => static fn (int $n): string => $n === 1 ? 'one' : 'other',
    'greeting' => 'Ciao, {name}',
    'only_it' => 'Solo italiano',
    'apples' => ['one' => '{count} mela', 'other' => '{count} mele'],
];
