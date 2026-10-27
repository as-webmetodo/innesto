<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/** @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t @var string $contents */
?>
<h1><?= $e($t('dev.upload.title')) ?></h1>
<p><?= $e($t('dev.upload.text')) ?></p>
<pre><code><?= $e($contents) ?></code></pre>
