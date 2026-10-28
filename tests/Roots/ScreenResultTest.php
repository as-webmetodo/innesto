<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\ScreenResult;

test('a Screen page answers a success or error status, never a redirect', function (int $status, bool $accepted): void {
    $page = fn (): ScreenResult => ScreenResult::page('', $status);

    $accepted ? expect($page()->status)->toBe($status) : expect($page)->toThrow(InvalidArgumentException::class);
})->with([
    'OK' => [200, true],
    'not found' => [404, true],
    'unprocessable' => [422, true],
    'server error' => [500, true],
    'redirect' => [302, false],
    'see other' => [303, false],
    'informational' => [100, false],
    'out of range' => [600, false],
]);
