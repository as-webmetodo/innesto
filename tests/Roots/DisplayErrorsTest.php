<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

test('Roots turns off display_errors for its own requests', function (): void {
    // A separate PHP process, started with display_errors on as a careless host would.
    $script = 'require ' . var_export(dirname(__DIR__, 2) . '/src/bootstrap.php', true) . ";\n"
        . "Innesto\\Roots\\App::run('index');\n"
        . "echo \"\\n\", ini_get('display_errors');\n";
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($script), $output, $status);

    expect($status)->toBe(0)
        ->and(end($output))->toBe('0');
});
