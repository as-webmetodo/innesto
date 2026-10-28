<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/**
 * @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t @var string $csrf @var string $locale
 * @var array<string, mixed> $settings @var string $actor @var string $business @var bool $mail @var array<string, string> $query @var string $today
 */
?>
<h1><?= $e($t('title')) ?></h1>
<dl>
<dt>greeting</dt><dd id="greeting"><?= $e(is_string($settings['greeting'] ?? null) ? $settings['greeting'] : '') ?></dd>
<dt>actor</dt><dd id="actor"><?= $e($actor) ?></dd>
<dt>locale</dt><dd id="locale"><?= $e($locale) ?></dd>
<dt>business</dt><dd id="business"><?= $e($business) ?></dd>
<dt>mail</dt><dd id="mail"><?= $mail ? 'yes' : 'no' ?></dd>
<dt>query</dt><dd id="query"><?= $e(http_build_query($query)) ?></dd>
<dt>today</dt><dd id="today"><?= $e($today) ?></dd>
</dl>
<form method="post">
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<input name="greeting">
<button>Save</button>
</form>
