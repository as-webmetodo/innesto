<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

// Innesto Orario's Manifest: Roots reads it without running any of its code.
return [
    'id' => 'in.hours',
    'name' => 'Innesto Orario',
    'version' => '1.0.0',
    'requires' => ['roots_api' => 1, 'php' => '8.3', 'capabilities' => [], 'optional' => []],
    'namespace' => 'Innesto\\Hours',
    'entry' => 'Innesto\\Hours\\Module',
    'views' => [
        'week' => ['v' => 1, 'options' => ['schedule' => ['type' => 'string', 'required' => true]]],
        'status' => ['v' => 1, 'options' => ['schedule' => ['type' => 'string', 'required' => true]]],
        'jsonld' => ['v' => 1, 'options' => ['schedule' => ['type' => 'string', 'required' => true]]],
    ],
    'screens' => [
        'schedules' => 'developer', 'schedule' => 'developer',
        'home' => 'client', 'regular' => 'client', 'exception' => 'client',
    ],
    'tiles' => true,
    'store' => [
        'schema' => 1,
        'collections' => [
            'schedules' => ['fields' => ['position' => 'int']],
            'exceptions' => ['fields' => ['schedule' => 'string']],
        ],
    ],
];
