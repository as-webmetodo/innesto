<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Manifest;

test('a Module\'s custom element is its Package id with a hyphen for the dot', function (string $dir, string $element): void {
    expect(Manifest::read($dir)->element())->toBe($element);
})->with([
    'official' => [dirname(__DIR__, 2) . '/modules/in.hours', 'in-hours'],
    'vendor' => [dirname(__DIR__) . '/fixtures/modules/acme.probe', 'acme-probe'],
]);
