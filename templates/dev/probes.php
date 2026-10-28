<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

/**
 * The Self-check's HTTP probes, run by the Developer's browser against Roots'
 * own origin. They fill the hidden `probes` field of the form with id
 * `selfcheck` and keep its button disabled until they are done; without
 * JavaScript the field stays empty and Roots refuses the post.
 *
 * @var array{txt: string, php: string, htaccess: string, now: int, marker: string} $probes
 */
?>
<script>
(function () {
  var probes = <?= json_encode($probes, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>;
  var skew = Math.round((Date.now() - probes.now) / 1000);
  var form = document.getElementById('selfcheck');
  var button = form.querySelector('button');
  button.disabled = true;
  // A body holding the marker; anything else (refused, missing, executed PHP) reads as ''.
  function body(url) {
    return fetch(url, { cache: 'no-store', credentials: 'omit', redirect: 'error' }).then(function (response) {
      return response.ok ? response.text() : '';
    }).catch(function () {
      return '';
    });
  }
  Promise.all([body(probes.txt), body(probes.php), body(probes.htaccess)]).then(function (bodies) {
    form.elements.probes.value = JSON.stringify({
      data_txt: bodies[0].indexOf(probes.marker) !== -1,
      data_php: bodies[1].indexOf(probes.marker) !== -1,
      htaccess: bodies[2].indexOf(probes.marker) === -1,
      skew: skew
    });
    button.disabled = false;
  });
})();
</script>
