// SPDX-License-Identifier: MPL-2.0

import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

const service = process.env.INNESTO_WEB ?? 'web';
const password = 'correct horse battery';

/** Runs a shell command in the web container, as the Developer would act over FTP. */
function inContainer(command) {
  return execFileSync('docker', ['compose', 'exec', '-T', service, 'sh', '-c', command], { encoding: 'utf8' });
}

test.beforeEach(async ({ page }) => {
  // A fresh upload, walked through the first run and the Shared config.
  inContainer('rm -rf /var/www/html/innesto/data-path.php /var/www/html/innesto/data-* /var/innesto-data-*');
  await page.goto('dev/');
  const setupCodeFile = inContainer(`cat "$(php -r "define('INNESTO_ROOTS', true); echo require '/var/www/html/innesto/data-path.php';")/setup-code.php"`);
  await page.getByLabel('Setup code').fill(/Setup code: (\S+)/.exec(setupCodeFile)[1]);
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('New Developer password').fill(password);
  await page.getByRole('button', { name: 'Set the password and sign in' }).click();
  await page.getByLabel('Site URL', { exact: true }).fill('https://www.example.com/');
  await page.getByRole('button', { name: 'Confirm the Shared config' }).click();
  await page.getByRole('link', { name: 'Developer area' }).click();
  await expect(page.getByRole('heading', { name: 'Developer area' })).toBeVisible();
});

test('Innesto Orario goes from Uploaded to Active, Inactive and back to Uploaded', async ({ page }) => {
  await page.getByRole('link', { name: 'Modules' }).click();
  const orario = page.locator('#module-in\\.hours');
  await expect(orario.getByRole('heading', { name: 'Innesto Orario' })).toBeVisible();
  await expect(orario.getByText('Uploaded')).toBeVisible();

  await orario.getByRole('button', { name: 'Activate' }).click();
  await expect(orario.getByText('Active', { exact: true })).toBeVisible();
  await expect(orario.getByRole('link', { name: 'schedules' })).toBeVisible();

  await orario.getByRole('button', { name: 'Deactivate' }).click();
  await expect(orario.getByText('Inactive: its data is kept')).toBeVisible();

  await orario.getByLabel('Yes, delete all its data').check();
  await orario.getByRole('button', { name: 'Delete data' }).click();
  await expect(orario.getByText('Uploaded')).toBeVisible();
});
