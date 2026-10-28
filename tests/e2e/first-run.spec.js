// SPDX-License-Identifier: MPL-2.0

import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

const service = process.env.INNESTO_WEB ?? 'web';
const password = 'correct horse battery';

/** Runs a shell command in the web container, as the Developer would act over FTP. */
function inContainer(command) {
  return execFileSync('docker', ['compose', 'exec', '-T', service, 'sh', '-c', command], { encoding: 'utf8' });
}

test.beforeEach(() => {
  // Back to a fresh upload: no recorded Data directory.
  inContainer('rm -rf /var/www/html/innesto/data-path.php /var/www/html/innesto/data-* /var/innesto-data-*');
});

test.use({ timezoneId: 'Europe/Rome' });

test('first run: the Setup code read over FTP and a new password sign the Developer in', async ({ page }) => {
  await page.goto('./');
  await expect(page).toHaveURL(/\/innesto\/dev\/$/);
  await expect(page.getByRole('heading', { name: 'First run' })).toBeVisible();

  const setupCodeFile = inContainer(`cat "$(php -r "define('INNESTO_ROOTS', true); echo require '/var/www/html/innesto/data-path.php';")/setup-code.php"`);
  const code = /Setup code: (\S+)/.exec(setupCodeFile)[1];

  await page.getByLabel('Setup code').fill(code);
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('New Developer password').fill(password);
  await page.getByRole('button', { name: 'Set the password and sign in' }).click();

  // Until it is confirmed, the Developer area opens on the Shared config, with the browser's timezone.
  await expect(page.getByRole('heading', { name: 'Shared config and Roots settings' })).toBeVisible();
  await expect(page.getByLabel('Install timezone')).toHaveValue('Europe/Rome');
  await page.getByLabel('Site URL', { exact: true }).fill('https://www.example.com/');
  await page.getByLabel('Name', { exact: true }).fill('Forno Rossi');
  await page.getByRole('button', { name: 'Confirm the Shared config' }).click();
  // The first confirm ends setup on the Self-check's results.
  await expect(page.getByRole('heading', { name: 'Health' })).toBeVisible();
  await page.getByRole('link', { name: 'Developer area' }).click();
  await expect(page.getByRole('heading', { name: 'Developer area' })).toBeVisible();

  await page.getByRole('link', { name: 'Shared config and Roots settings' }).click();
  await expect(page.getByLabel('Install timezone')).toHaveValue('Europe/Rome');
  await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Forno Rossi');

  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

  await page.getByLabel('Developer password').fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Developer area' })).toBeVisible();
});

test('protected files are denied or print nothing over HTTP', async ({ request }) => {
  await request.get('dev/');
  for (const path of ['locales/en.php', 'templates/dev/layout.php', 'src/App.php', 'data-path.php', 'modules/in.hours/module.php']) {
    const response = await request.get(path);
    // Apache's own 403 where .htaccess is honoured, else the bootstrap guard's empty output.
    if (response.status() !== 403) {
      expect(await response.text(), path).toBe('');
    }
  }
});
