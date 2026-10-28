<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/**
 * @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t
 * @var string $module @var string $id @var list<string> $screens @var string $current @var string|null $content
 */
?>
<nav>
<a href="./"><?= $e($t('dev.home.title')) ?></a>
<a href="?screen=modules"><?= $e($t('dev.modules.title')) ?></a>
<?= $e($module) ?>:
<?php foreach ($screens as $screen) : ?>
<a href="?<?= $e(http_build_query(['module' => $id, 'screen' => $screen])) ?>"<?= $screen === $current ? ' aria-current="page"' : '' ?>><?= $e($screen) ?></a>
<?php endforeach ?>
</nav>
<?php if ($content === null) : ?>
<p role="alert"><?= $e($t('dev.modules.error.screen')) ?></p>
<?php else : ?>
<?= $content ?>
<?php endif ?>
