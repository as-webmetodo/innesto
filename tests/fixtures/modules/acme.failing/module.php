<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

// A fake Module whose onActivate writes, then throws.
return [
    'id' => 'acme.failing',
    'name' => 'Acme Failing',
    'version' => '1.0.0',
    'requires' => ['roots_api' => 1, 'php' => '8.3', 'capabilities' => [], 'optional' => []],
    'namespace' => 'Acme\\Failing',
    'entry' => 'Acme\\Failing\\Module',
    'views' => [],
    'screens' => [],
    'tiles' => false,
    'store' => ['schema' => 1, 'collections' => ['notes' => ['fields' => []]]],
];
