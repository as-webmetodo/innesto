<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

const PROTECTED_TREES = ['src', 'templates', 'locales', 'modules', 'overrides'];

test('every protected tree denies HTTP through .htaccess', function (string $tree): void {
    $htaccess = dirname(__DIR__) . "/$tree/.htaccess";

    expect(is_file($htaccess))->toBeTrue()
        ->and(file_get_contents($htaccess))->toContain('Require all denied');
})->with(PROTECTED_TREES);

test('every PHP file that would output in a protected tree starts with the bootstrap guard', function (): void {
    $root = dirname(__DIR__);
    $listing = (string) shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -z --cached --others --exclude-standard -- templates locales modules overrides');
    // Class files only declare symbols; tests are stripped from the release.
    $outputting = array_filter(explode("\0", $listing), fn (string $path): bool => str_ends_with($path, '.php')
        && !preg_match('~^modules/[^/]+/(src|tests)/~', $path) && is_file("$root/$path"));

    expect($outputting)->not->toBeEmpty();
    foreach ($outputting as $path) {
        expect(preg_match("~^<\\?php\\s+(//[^\\n]*\\s+)*declare\\(strict_types=1\\);\\s+defined\\('INNESTO_ROOTS'\\) \\|\\| exit;~", (string) file_get_contents("$root/$path")))
            ->toBe(1, "$path does not start with the bootstrap guard");
    }
});
