<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

/**
 * Writes the given files into a fresh temporary directory and returns it.
 *
 * @param array<string, string> $files contents keyed by relative path
 */
function writeTree(array $files): string
{
    $root = sys_get_temp_dir() . '/innesto-headers-' . bin2hex(random_bytes(6));
    foreach ($files as $path => $contents) {
        is_dir(dirname("$root/$path")) || mkdir(dirname("$root/$path"), 0o777, true);
        file_put_contents("$root/$path", $contents);
    }

    return $root;
}

test('a stylesheet without the SPDX identifier is reported', function (): void {
    $root = writeTree(['assets/shell.css' => "body { margin: 0; }\n"]);

    expect(sourceHeaderViolations($root, ['assets/shell.css']))
        ->toBe(['assets/shell.css: missing SPDX-License-Identifier: MPL-2.0']);
});

test('a PHP file without strict types is reported', function (): void {
    $root = writeTree(['src/Kernel.php' => "<?php\n\n// SPDX-License-Identifier: MPL-2.0\n\nnamespace Innesto\\Roots;\n"]);

    expect(sourceHeaderViolations($root, ['src/Kernel.php']))
        ->toBe(['src/Kernel.php: missing declare(strict_types=1)']);
});

test('strict types mentioned only in a comment do not count', function (): void {
    $template = "<?php\n\n// SPDX-License-Identifier: MPL-2.0\n// TODO: declare(strict_types=1);\n?>\n<p>Open</p>\n";
    $root = writeTree(['templates/week.php' => $template]);

    expect(sourceHeaderViolations($root, ['templates/week.php']))
        ->toBe(['templates/week.php: missing declare(strict_types=1)']);
});

test('an SPDX identifier below the header does not count', function (): void {
    $script = str_repeat("// notes\n", 5) . "// SPDX-License-Identifier: MPL-2.0\n";
    $root = writeTree(['assets/innesto.js' => $script]);

    expect(sourceHeaderViolations($root, ['assets/innesto.js']))
        ->toBe(['assets/innesto.js: missing SPDX-License-Identifier: MPL-2.0']);
});

test('compliant sources and non-source files pass', function (): void {
    $root = writeTree([
        'README.md' => "# Innesto\n",
        'compose.yaml' => "services: {}\n",
        'index.php' => "<?php\n\n// SPDX-License-Identifier: MPL-2.0\n\ndeclare(strict_types=1);\n",
        'assets/in-hours.mjs' => "// SPDX-License-Identifier: MPL-2.0\n",
    ]);

    expect(sourceHeaderViolations($root, ['README.md', 'compose.yaml', 'index.php', 'assets/in-hours.mjs']))
        ->toBe([]);
});

test('every source file in the repository carries its headers', function (): void {
    $root = dirname(__DIR__);
    $listing = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -z --cached --others --exclude-standard');
    expect($listing)->toBeString();

    // Tracked files deleted from the working tree are still listed until staged.
    $paths = array_values(array_filter(explode("\0", (string) $listing), fn (string $path): bool => is_file("$root/$path")));

    expect(sourceHeaderViolations($root, $paths))->toBe([]);
});
