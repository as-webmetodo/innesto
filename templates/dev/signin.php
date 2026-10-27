<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/** @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t @var string|null $error @var array<string, string|int> $errorParams */
?>
<h1><?= $e($t('dev.signin.title')) ?></h1>
<?php if ($error !== null) : ?>
<p role="alert"><?= $e($t($error, $errorParams)) ?></p>
<?php endif ?>
<form method="post">
<input type="hidden" name="action" value="signin">
<label><?= $e($t('dev.password')) ?> <input type="password" name="password" required autocomplete="current-password"></label>
<button><?= $e($t('dev.signin.submit')) ?></button>
</form>
