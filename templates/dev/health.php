<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/**
 * @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t @var string $csrf
 * @var string|null $error @var bool $mailSent @var bool $sqlite @var bool $mail
 * @var array{server: array{time: string, findings: list<array{id: string, grade: string, params: array<string, string|int>}>}|null, browser: array{time: string, findings: list<array{id: string, grade: string, params: array<string, string|int>}>}|null, backend: string|null} $latest
 * @var array<string, array{count: int, message: string, time: string}> $faults
 * @var list<array<string, mixed>> $signins @var int $signinFailures @var list<array<string, mixed>> $tail
 * @var array{txt: string, php: string, htaccess: string, now: int, marker: string} $probes
 */
$field = static fn (array $entry, string $name): string => is_scalar($entry[$name] ?? null) ? (string) $entry[$name] : '';
$ip = static fn (array $entry): string => is_array($entry['context'] ?? null) && is_string($entry['context']['ip'] ?? null) ? $entry['context']['ip'] : '';
?>
<h1><?= $e($t('dev.health.title')) ?></h1>
<p><a href="./"><?= $e($t('dev.home.title')) ?></a></p>
<?php if ($error !== null) : ?>
<p role="alert"><?= $e($t($error)) ?></p>
<?php endif ?>
<h2><?= $e($t('selfcheck.title')) ?></h2>
<p><?= $e($t($latest['backend'] !== null ? "selfcheck.backend.{$latest['backend']}" : ($sqlite ? 'selfcheck.backend.sqlite' : 'dev.health.backend.files'))) ?></p>
<?php foreach (['server', 'browser'] as $kind) : ?>
<?php if ($latest[$kind] !== null) : ?>
<h3><?= $e($t("dev.health.$kind", ['time' => $latest[$kind]['time']])) ?></h3>
<?php $findings = $latest[$kind]['findings'] ?>
<?php require __DIR__ . '/findings.php' ?>
<?php endif ?>
<?php endforeach ?>
<form method="post" id="selfcheck">
<input type="hidden" name="action" value="selfcheck">
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<input type="hidden" name="probes" value="">
<button><?= $e($t('dev.health.run')) ?></button>
</form>
<?php require __DIR__ . '/probes.php' ?>
<h2><?= $e($t('dev.health.mail')) ?></h2>
<p><?= $e($t($mail ? 'selfcheck.mail.ok' : 'selfcheck.mail.warn')) ?></p>
<?php if ($mailSent) : ?>
<p role="status"><?= $e($t('dev.health.mail.sent')) ?></p>
<?php endif ?>
<?php if ($mail) : ?>
<form method="post">
<input type="hidden" name="action" value="test_mail">
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<label><?= $e($t('dev.health.mail.to')) ?> <input type="email" name="to" required autocomplete="email"></label>
<button><?= $e($t('dev.health.mail.send')) ?></button>
</form>
<?php endif ?>
<h2><?= $e($t('dev.health.errors')) ?></h2>
<?php if ($faults === []) : ?>
<p><?= $e($t('dev.health.errors.none')) ?></p>
<?php else : ?>
<ul>
<?php foreach ($faults as $id => $fault) : ?>
<li><?= $e($id) ?>: <?= $e($t('dev.modules.fault', ['count' => $fault['count'], 'time' => $fault['time'], 'message' => $fault['message']])) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<h2><?= $e($t('dev.health.signins')) ?></h2>
<p><?= $e($t('dev.health.signins.count', ['count' => $signinFailures])) ?></p>
<?php if ($signins !== []) : ?>
<ul>
<?php foreach ($signins as $entry) : ?>
<li><?= $e($field($entry, 'time')) ?> <?= $e($field($entry, 'event')) ?> <?= $e($ip($entry)) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<h2><?= $e($t('dev.health.log')) ?></h2>
<p><a href="?screen=health&amp;download=log" download><?= $e($t('dev.health.log.download')) ?></a></p>
<ol reversed>
<?php foreach ($tail as $entry) : ?>
<li><?= $e($field($entry, 'time')) ?> <?= $e($field($entry, 'level')) ?> <?= $e($field($entry, 'source')) ?> <?= $e($field($entry, 'event')) ?>: <?= $e($field($entry, 'message')) ?></li>
<?php endforeach ?>
</ol>
