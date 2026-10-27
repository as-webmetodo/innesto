<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/** @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t @var string|null $error @var array<string, string|int> $errorParams @var string $csrf */
?>
<h1><?= $e($t('dev.home.title')) ?></h1>
<p><?= $e($t('dev.home.signed_in')) ?></p>
<form method="post">
<input type="hidden" name="action" value="signout">
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<button><?= $e($t('dev.signout')) ?></button>
</form>
<h2><?= $e($t('dev.password.change.title')) ?></h2>
<?php if ($error !== null) : ?>
<p role="alert"><?= $e($t($error, $errorParams)) ?></p>
<?php endif ?>
<form method="post">
<input type="hidden" name="action" value="password">
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<label><?= $e($t('dev.password.current')) ?> <input type="password" name="current" required autocomplete="current-password"></label>
<label><?= $e($t('dev.password.new')) ?> <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
<button><?= $e($t('dev.password.change.submit')) ?></button>
</form>
