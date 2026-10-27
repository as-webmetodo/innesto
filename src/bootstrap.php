<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

// Roots' own PSR-4 autoloader: the server has no Composer.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Innesto\\Roots\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Innesto\\Roots\\'))) . '.php';
        is_file($file) && require $file;
    }
});
