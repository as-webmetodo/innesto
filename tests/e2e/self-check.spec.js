// SPDX-License-Identifier: MPL-2.0

import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

const service = process.env.INNESTO_WEB ?? 'web';
const password = 'correct horse battery';

/** Runs a shell command in the web container, as the Developer would act over FTP. */
function inContainer(command) {
  return execFileSync('docker', ['compose', 'exec', '-T', service, 'sh', '-c', command], { encoding: 'utf8' });
}

/** What each container's host must make the browser-run Self-check find (spec §7.3, §7.5, §7.7). */
const expected = {
  web: { storage: 'Roots stores its data in SQLite.', grades: { 'cannot be reached over HTTP': 'ok', 'honours .htaccess': 'ok', 'SQLite 3': 'ok' } },
  'web-noext': { storage: 'Roots stores its data in guarded files: no SQLite 3.27 or later.', grades: { 'No SQLite 3.27': 'warn', 'cannot be reached over HTTP': 'ok' } },
  'web-no-htaccess': {
    storage: 'Roots stores its data in guarded files: the Data directory can be reached over HTTP.',
    grades: { 'Files in the Data directory can be reached': 'warn', 'ignores .htaccess': 'warn', 'shows nothing over HTTP': 'ok', 'inside the webroot': 'warn' },
  },
}[service];

test.beforeEach(() => {
  inContainer('rm -rf /var/www/html/innesto/data-path.php /var/www/html/innesto/data-* /var/innesto-data-*');
});

test('setup runs the HTTP probes from the browser, picks the backend and says why; the Health Screen shows it all', async ({ page }) => {
  await page.goto('dev/');
  const setupCodeFile = inContainer(`cat "$(php -r "define('INNESTO_ROOTS', true); echo require '/var/www/html/innesto/data-path.php';")/setup-code.php"`);
  await page.getByLabel('Setup code').fill(/Setup code: (\S+)/.exec(setupCodeFile)[1]);
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('New Developer password').fill(password);
  // Enabled once the probes have run.
  await page.getByRole('button', { name: 'Set the password and sign in' }).click();
  await page.getByLabel('Site URL', { exact: true }).fill('https://www.example.com/');
  await page.getByLabel('Name', { exact: true }).fill('Forno Rossi');
  await page.getByLabel('Street address').fill('Via Roma 1');
  await page.getByLabel('City', { exact: true }).fill('Roma');
  await page.getByRole('button', { name: 'Confirm the Shared config' }).click();

  // The first confirm ends setup on the Health Screen.
  await expect(page).toHaveURL(/\?screen=health$/);
  await expect(page.getByText(expected.storage)).toBeVisible();
  for (const [text, grade] of Object.entries(expected.grades)) {
    await expect(page.locator(`li[data-grade="${grade}"]`, { hasText: text }).first()).toBeVisible();
  }
  await expect(page.locator('li[data-grade="fail"]')).toHaveCount(0);

  // The full check runs again from the Health Screen, with the same verdict.
  await page.getByRole('button', { name: 'Run the Self-check again' }).click();
  await expect(page).toHaveURL(/\?screen=health$/);
  await expect(page.locator('li[data-grade="fail"]')).toHaveCount(0);

  const download = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Download the whole Roots log' }).click();
  expect((await download).suggestedFilename()).toBe('roots-log.jsonl');
});

test('a guarded PHP file that shows its contents is a Fail and setup refuses to finish', async ({ page }) => {
  await page.goto('dev/');
  const data = inContainer(`php -r "define('INNESTO_ROOTS', true); echo require '/var/www/html/innesto/data-path.php';"`);
  // A host that stops running PHP in the Data directory would leak every guarded file.
  await page.route('**/probe.php', (route) => route.fulfill({ status: 200, contentType: 'text/plain', body: "<?php exit; ?>\ninnesto-probe\n" }));
  const setupCodeFile = inContainer(`cat "${data}/setup-code.php"`);
  await page.getByLabel('Setup code').fill(/Setup code: (\S+)/.exec(setupCodeFile)[1]);
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('New Developer password').fill(password);
  await page.getByRole('button', { name: 'Set the password and sign in' }).click();

  await expect(page.getByText('The Self-check found a Fail')).toBeVisible();
  await expect(page.locator('li[data-grade="fail"]', { hasText: 'shows its contents over HTTP' })).toBeVisible();
  // Still the Self-check step, for another try once it is fixed.
  await expect(page.getByLabel('New Developer password')).toBeVisible();
});
