<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/** @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t @var string $title @var string $text */
?>
<h1><?= $e($t($title)) ?></h1>
<p role="alert"><?= $e($t($text)) ?></p>
