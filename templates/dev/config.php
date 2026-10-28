<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/**
 * @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t @var string $csrf
 * @var list<string> $errors @var array<string, mixed> $shared @var array{max_age: string, extra_origins: string} $settings
 * @var bool $confirmed @var bool $incompleteProfile @var bool $defaultSiteUrl @var string $siteOrigin
 * @var list<string> $types @var list<string> $locales @var list<string> $timezones
 */
$value = static fn (string $field): string => is_string($shared[$field] ?? null) ? $shared[$field] : '';
$enabled = is_array($shared['enabled_locales'] ?? null) ? $shared['enabled_locales'] : [];
$text = static fn (string $field, string $autocomplete = 'off', string $type = 'text'): string => '<label>' . $e($t("dev.config.$field"))
    . ' <input type="' . $type . '" id="' . $field . '" name="' . $field . '" value="' . $e($value($field)) . '" autocomplete="' . $autocomplete . '"></label>';
/** @param list<string> $options */
$select = static fn (string $field, array $options): string => '<label>' . $e($t("dev.config.$field")) . ' <select id="' . $field . '" name="' . $field . "\">\n"
    . implode('', array_map(static fn (string $option): string => '<option value="' . $e($option) . '"' . ($option === $value($field) ? ' selected' : '') . '>' . $e($option) . "</option>\n", $options))
    . '</select></label>';
?>
<h1><?= $e($t('dev.config.title')) ?></h1>
<form method="post">
<input type="hidden" name="action" value="signout">
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<button><?= $e($t('dev.signout')) ?></button>
</form>
<?php if ($errors !== []) : ?>
<div role="alert">
<p><?= $e($t('dev.config.error')) ?></p>
<ul>
<?php foreach ($errors as $field) : ?>
<li><a href="#<?= $e($field) ?>"><?= $e($t("dev.config.$field")) ?></a></li>
<?php endforeach ?>
</ul>
</div>
<?php endif ?>
<?php if (!$confirmed) : ?>
<p><?= $e($t('dev.config.confirm')) ?></p>
<?php endif ?>
<?php if ($incompleteProfile) : ?>
<p><?= $e($t('dev.config.warn.profile')) ?></p>
<?php endif ?>
<?php if ($defaultSiteUrl) : ?>
<p><?= $e($t('dev.config.warn.site_url')) ?></p>
<?php endif ?>
<form method="post">
<input type="hidden" name="action" value="shared">
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<h2><?= $e($t('dev.config.shared')) ?></h2>
<label><?= $e($t('dev.config.site_url')) ?> <input type="url" id="site_url" name="site_url" value="<?= $e($value('site_url')) ?>" required></label>
<?= $select('timezone', $timezones) ?>
<fieldset>
<legend><?= $e($t('dev.config.enabled_locales')) ?></legend>
<?php foreach ($locales as $locale) : ?>
<label><input type="checkbox" name="locale_<?= $e($locale) ?>"<?= in_array($locale, $enabled, true) ? ' checked' : '' ?>> <?= $e($locale) ?></label>
<?php endforeach ?>
</fieldset>
<?= $select('default_locale', $locales) ?>
<h3><?= $e($t('dev.config.profile')) ?></h3>
<?= $text('name', 'organization') ?>
<?= $select('type', $types) ?>
<?= $text('street_address', 'street-address') ?>
<?= $text('postal_code', 'postal-code') ?>
<?= $text('locality', 'address-level2') ?>
<?= $text('region', 'address-level1') ?>
<?= $text('country', 'country') ?>
<?= $text('phone', 'tel') ?>
<?= $text('url', 'url', 'url') ?>
<?= $text('image', 'off', 'url') ?>
<button><?= $e($t($confirmed ? 'dev.config.save' : 'dev.config.confirm.submit')) ?></button>
</form>
<?php if (!$confirmed && $errors === []) : ?>
<script>
// Pre-fills the Install timezone from the browser, only before the first save.
(function () {
  var select = document.getElementById('timezone');
  var zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  for (var i = 0; i < select.options.length; i++) {
    if (select.options[i].value === zone) {
      select.value = zone;
    }
  }
})();
</script>
<?php endif ?>
<form method="post">
<input type="hidden" name="action" value="settings">
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<h2><?= $e($t('dev.config.settings')) ?></h2>
<label><?= $e($t('dev.config.max_age')) ?> <input type="number" id="max_age" name="max_age" min="1" max="60" value="<?= $e($settings['max_age']) ?>" required></label>
<label><?= $e($t('dev.config.extra_origins')) ?> <textarea id="extra_origins" name="extra_origins"><?= $e($settings['extra_origins']) ?></textarea></label>
<p><?= $e($t('dev.config.site_origin', ['origin' => $siteOrigin])) ?></p>
<button><?= $e($t('dev.config.save')) ?></button>
</form>
