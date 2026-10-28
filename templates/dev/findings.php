<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/**
 * A list of Self-check findings, each with its grade and, unless OK, a fix
 * hint; included by the templates that show them.
 *
 * @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t
 * @var list<array{id: string, grade: string, params: array<string, string|int>}> $findings
 */
?>
<ul>
<?php foreach ($findings as $finding) : ?>
<li data-grade="<?= $e($finding['grade']) ?>"><strong><?= $e($t('selfcheck.grade.' . $finding['grade'])) ?>:</strong> <?= $e($t("selfcheck.{$finding['id']}.{$finding['grade']}", $finding['params'])) ?><?php if ($finding['grade'] !== 'ok') : ?> <?= $e($t("selfcheck.{$finding['id']}.fix")) ?><?php endif ?></li>
<?php endforeach ?>
</ul>
