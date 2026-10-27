<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\DataDirectory;

/** @return array{base: string, webroot: string, roots: string} a host account with `public_html/innesto/` */
function hostAccount(): array
{
    $base = sys_get_temp_dir() . '/innesto-host-' . bin2hex(random_bytes(6));
    mkdir("$base/public_html/innesto", 0o777, true);

    return ['base' => $base, 'webroot' => "$base/public_html", 'roots' => "$base/public_html/innesto"];
}

test('a writable directory outside the webroot gets innesto-data-<random>', function (): void {
    ['base' => $base, 'webroot' => $webroot, 'roots' => $roots] = hostAccount();

    $data = DataDirectory::locate($roots, $webroot, $base);

    expect($data->path)->toMatch('~^' . preg_quote($base, '~') . '/innesto-data-[0-9a-f]{16}$~')
        ->and(is_dir($data->path))->toBeTrue()
        ->and($data->insideWebroot)->toBeFalse()
        ->and($data->upload)->toBeNull()
        ->and(require "$roots/data-path.php")->toBe($data->path);
});

test('side-by-side installs get different Data directories', function (): void {
    ['base' => $base, 'webroot' => $webroot] = hostAccount();
    mkdir("$webroot/other");

    expect(DataDirectory::locate("$webroot/other", $webroot, $base)->path)
        ->not->toBe(DataDirectory::locate("$webroot/innesto", $webroot, $base)->path);
});

test('without a writable directory outside the webroot, <roots>/data-<random> is denied to HTTP', function (): void {
    ['base' => $base, 'webroot' => $webroot, 'roots' => $roots] = hostAccount();
    chmod($base, 0o555);

    $data = DataDirectory::locate($roots, $webroot, $base);
    chmod($base, 0o755);

    expect($data->path)->toMatch('~^' . preg_quote($roots, '~') . '/data-[0-9a-f]{16}$~')
        ->and($data->insideWebroot)->toBeTrue()
        ->and(file_get_contents("$data->path/.htaccess"))->toContain('Require all denied');
});

test('the recorded Data directory is used on later requests', function (): void {
    ['base' => $base, 'webroot' => $webroot, 'roots' => $roots] = hostAccount();
    $first = DataDirectory::locate($roots, $webroot, $base);

    expect(DataDirectory::locate($roots, $webroot, $base)->path)->toBe($first->path);
});

test('when <roots> is not writable, the data-path.php to upload is shown and nothing is created', function (): void {
    ['base' => $base, 'webroot' => $webroot, 'roots' => $roots] = hostAccount();
    chmod($roots, 0o555);

    $data = DataDirectory::locate($roots, $webroot, $base);

    expect($data->upload)->toContain("defined('INNESTO_ROOTS') || exit;")
        ->and($data->upload)->toContain(var_export($data->path, true))
        ->and(is_dir($data->path))->toBeFalse();

    // The Developer uploads it over FTP; the next request creates the directory.
    chmod($roots, 0o755);
    file_put_contents("$roots/data-path.php", (string) $data->upload);
    $uploaded = DataDirectory::locate($roots, $webroot, $base);

    expect($uploaded->path)->toBe($data->path)
        ->and($uploaded->upload)->toBeNull()
        ->and(is_dir($uploaded->path))->toBeTrue();
});

test('without any writable place, no data-path.php is offered', function (): void {
    ['base' => $base, 'webroot' => $webroot, 'roots' => $roots] = hostAccount();
    chmod($base, 0o555);
    chmod($roots, 0o555);

    try {
        expect(fn () => DataDirectory::locate($roots, $webroot, $base))->toThrow(RuntimeException::class);
    } finally {
        chmod($roots, 0o755);
        chmod($base, 0o755);
    }
});

test('a recorded Data directory PHP cannot write to is refused', function (): void {
    ['base' => $base, 'webroot' => $webroot, 'roots' => $roots] = hostAccount();
    mkdir("$base/readonly", 0o555);
    file_put_contents("$roots/data-path.php", "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export("$base/readonly", true) . ";\n");

    expect(fn () => DataDirectory::locate($roots, $webroot, $base))->toThrow(RuntimeException::class);
});
