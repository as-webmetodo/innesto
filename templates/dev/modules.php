<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/**
 * @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t @var string $csrf
 * @var array{0: string, 1: array<string, string|int>}|null $error
 * @var list<array{id: string, name: string, state: Innesto\Roots\ModuleState, halt: array{0: string, 1: array<string, string|int>}|null, fault: array{count: int, message: string, time: string}|null, uncovered: list<string>, screens: list<string>}> $modules
 */
$action = static fn (string $action, string $id, string $label, string $extra = ''): string => '<form method="post">'
    . '<input type="hidden" name="action" value="' . $action . '">'
    . '<input type="hidden" name="module" value="' . $e($id) . '">'
    . '<input type="hidden" name="csrf" value="' . $e($csrf) . '">'
    . $extra . '<button>' . $e($t($label)) . "</button></form>\n";
?>
<h1><?= $e($t('dev.modules.title')) ?></h1>
<p><a href="./"><?= $e($t('dev.home.title')) ?></a></p>
<?php if ($error !== null) : ?>
<p role="alert"><?= $e($t($error[0], $error[1])) ?></p>
<?php endif ?>
<?php if ($modules === []) : ?>
<p><?= $e($t('dev.modules.none')) ?></p>
<?php endif ?>
<?php foreach ($modules as $module) : ?>
<section id="module-<?= $e($module['id']) ?>">
<h2><?= $e($module['name']) ?> <small><?= $e($module['id']) ?></small></h2>
<p><?= $e($t('dev.modules.state.' . $module['state']->value)) ?></p>
<?php if ($module['halt'] !== null) : ?>
<p><?= $e($t('dev.modules.halted')) ?> <?= $e($t($module['halt'][0], $module['halt'][1])) ?></p>
<?php endif ?>
<?php if ($module['uncovered'] !== []) : ?>
<p><?= $e($t('dev.modules.uncovered', ['locales' => implode(', ', $module['uncovered'])])) ?></p>
<?php endif ?>
<?php if ($module['fault'] !== null) : ?>
<p><?= $e($t('dev.modules.fault', ['count' => $module['fault']['count'], 'time' => $module['fault']['time'], 'message' => $module['fault']['message']])) ?></p>
<?php endif ?>
<?php if ($module['screens'] !== []) : ?>
<ul>
<?php foreach ($module['screens'] as $screen) : ?>
<li><a href="?<?= $e(http_build_query(['module' => $module['id'], 'screen' => $screen])) ?>"><?= $e($screen) ?></a></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php if ($module['state'] !== Innesto\Roots\ModuleState::Active && $module['halt'] === null) : ?>
<?= $action('activate', $module['id'], 'dev.modules.activate') ?>
<?php endif ?>
<?php if ($module['state'] === Innesto\Roots\ModuleState::Active) : ?>
<?= $action('deactivate', $module['id'], 'dev.modules.deactivate') ?>
<?php endif ?>
<?php if ($module['state'] === Innesto\Roots\ModuleState::Inactive) : ?>
<?= $action('delete_data', $module['id'], 'dev.modules.delete', '<label><input type="checkbox" name="confirm" required> ' . $e($t('dev.modules.delete.confirm')) . '</label>') ?>
<?php endif ?>
</section>
<?php endforeach ?>
