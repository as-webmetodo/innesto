<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/** @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t @var string|null $error @var array<string, string|int> $errorParams @var bool $canSignIn
 * @var list<array{id: string, grade: string, params: array<string, string|int>}> $findings @var array{txt: string, php: string, htaccess: string, now: int, marker: string} $probes */
?>
<h1><?= $e($t('dev.setup.title')) ?></h1>
<?php if ($error !== null) : ?>
<p role="alert"><?= $e($t($error, $errorParams)) ?></p>
<?php endif ?>
<?php if ($findings !== []) : ?>
<h2><?= $e($t('selfcheck.title')) ?></h2>
<?php require __DIR__ . '/findings.php' ?>
<?php endif ?>
<?php if ($canSignIn) : ?>
<p><?= $e($t('dev.setup.reset')) ?></p>
<?php endif ?>
<p><?= $e($t('dev.setup.text')) ?></p>
<form method="post" id="selfcheck">
<input type="hidden" name="action" value="setup">
<input type="hidden" name="probes" value="">
<label><?= $e($t('dev.setup.code')) ?> <input name="code" required autocomplete="off" autocapitalize="characters"></label>
<label><?= $e($t('dev.password.new')) ?> <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
<label><input type="checkbox" name="files"> <?= $e($t('dev.setup.files')) ?></label>
<button><?= $e($t('dev.setup.submit')) ?></button>
</form>
<?php require __DIR__ . '/probes.php' ?>
<?php if ($canSignIn) : ?>
<h2><?= $e($t('dev.signin.title')) ?></h2>
<form method="post">
<input type="hidden" name="action" value="signin">
<label><?= $e($t('dev.password')) ?> <input type="password" name="password" required autocomplete="current-password"></label>
<button><?= $e($t('dev.signin.submit')) ?></button>
</form>
<?php endif ?>
