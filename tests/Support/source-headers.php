<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

/**
 * Lists the source files among $paths that lack a required header.
 *
 * @param list<string> $paths relative to $root
 * @return list<string> one "path: problem" entry per violation
 */
function sourceHeaderViolations(string $root, array $paths): array
{
    $violations = [];
    foreach ($paths as $path) {
        if (!in_array(pathinfo($path, PATHINFO_EXTENSION), ['php', 'css', 'js', 'mjs'], true)) {
            continue;
        }
        $contents = (string) file_get_contents("$root/$path");
        $header = implode("\n", array_slice(explode("\n", $contents), 0, 5));
        if (!str_contains($header, 'SPDX-License-Identifier: MPL-2.0')) {
            $violations[] = "$path: missing SPDX-License-Identifier: MPL-2.0";
        }
        // The declaration must be the first statement: only whitespace and comments may precede it.
        $declaresStrictTypes = preg_match('~^<\?php(?>\s+|//[^\n]*|/\*.*?\*/)*declare\(strict_types=1\);~s', $contents) === 1;
        if (str_ends_with($path, '.php') && !$declaresStrictTypes) {
            $violations[] = "$path: missing declare(strict_types=1)";
        }
    }

    return $violations;
}
