<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/**
 * The Developer area refused for plain HTTP. The browser-run HTTPS probe
 * explains the one case Roots cannot see: the browser is on HTTPS, but a
 * proxy reports it to PHP only through X-Forwarded-*, which Roots never reads.
 *
 * @var callable(string): string $e @var callable(string, array<string, string|int>=): string $t
 */
?>
<h1><?= $e($t('dev.title')) ?></h1>
<p><?= $e($t('dev.error.https')) ?></p>
<p id="proxied" hidden><?= $e($t('dev.error.https_proxied')) ?></p>
<script>
if (location.protocol === 'https:') {
  document.getElementById('proxied').hidden = false;
}
</script>
