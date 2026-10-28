<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

// A fake Module that shows what its Context holds and how Roots routes its Screens.
return [
    'id' => 'acme.probe',
    'name' => 'Acme Probe',
    'version' => '1.0.0',
    'requires' => ['roots_api' => 1, 'php' => '8.3', 'capabilities' => [], 'optional' => ['mail']],
    'namespace' => 'Acme\\Probe',
    'entry' => 'Acme\\Probe\\Module',
    'views' => [],
    'screens' => ['echo' => 'developer', 'boom' => 'developer', 'empty' => 'developer', 'stray' => 'developer', 'phone' => 'client'],
    'tiles' => false,
    'store' => ['schema' => 1, 'collections' => ['notes' => ['fields' => []]]],
];
