<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Auth;

/**
 * The one-time Setup code Roots leaves in a guarded file in the Data
 * directory, for the Developer to read over FTP.
 */
final class SetupCode
{
    /** Upper-case letters and digits without the look-alikes I, O, 0 and 1. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private readonly string $file;

    public function __construct(string $dataDir)
    {
        $this->file = "$dataDir/setup-code.php";
    }

    public function pending(): bool
    {
        return is_file($this->file);
    }

    /** Writes a fresh code, replacing any pending one. */
    public function issue(): void
    {
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $group = '';
            for ($i = 0; $i < 4; $i++) {
                $group .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $group;
        }
        file_put_contents($this->file, "<?php exit; ?>\nSetup code: " . implode('-', $groups) . "\n", LOCK_EX);
    }

    /** Compares ignoring case, spaces and dashes. */
    public function matches(string $entered): bool
    {
        $contents = is_file($this->file) ? (string) file_get_contents($this->file) : '';
        if (preg_match('/Setup code: (\S+)/', $contents, $match) !== 1) {
            return false;
        }

        return hash_equals(self::normalise($match[1]), self::normalise($entered));
    }

    public function consume(): void
    {
        is_file($this->file) && unlink($this->file);
    }

    private static function normalise(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }
}
