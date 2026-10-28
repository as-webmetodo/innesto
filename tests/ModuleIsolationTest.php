<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Manifest;

/** The whole of Roots a Module's code may use: its API, never Roots' internals. */
const ROOTS_MODULE_API = [
    'Innesto\Roots\Module',
    'Innesto\Roots\Context',
    'Innesto\Roots\ScreenRequest',
    'Innesto\Roots\ScreenResult',
    'Innesto\Roots\Actor',
    'Innesto\Roots\Clock',
    'Innesto\Roots\Mail',
    'Innesto\Roots\SharedConfig',
    'Innesto\Roots\Store\Store',
    'Innesto\Roots\Locale\Catalogue',
    'Innesto\Roots\Locale\Formatter',
];

/** @return array<string, array{string, string}> each shipped Module's source directory and namespace, by Package id */
function shippedModules(): array
{
    $modules = [];
    foreach (glob(dirname(__DIR__) . '/modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $modules[basename($dir)] = ["$dir/src", Manifest::read($dir)->namespace];
    }

    return $modules;
}

foreach (shippedModules() as $id => [, $namespace]) {
    arch("$id uses only its own namespace, Roots' Module API and PHP", function () use ($namespace): void {
        expect($namespace)->toOnlyUse([$namespace, ...ROOTS_MODULE_API]);
    });
}

test('Module code never reads superglobals', function (string $src): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        assert($file instanceof SplFileInfo);
        $variables = array_filter(token_get_all((string) file_get_contents($file->getPathname())), static fn (mixed $token): bool => is_array($token) && $token[0] === T_VARIABLE);
        $superglobals = array_intersect(array_column($variables, 1), ['$GLOBALS', '$_SERVER', '$_GET', '$_POST', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV']);

        expect($superglobals)->toBe([], $file->getPathname() . ' reads a superglobal');
    }
})->with(array_map(static fn (array $module): array => [$module[0]], shippedModules()));
