// SPDX-License-Identifier: MPL-2.0

import { expect, test } from '@playwright/test';

test('the container serves the Roots entry point', async ({ page }) => {
  const response = await page.goto('./');

  expect(response?.status()).toBe(200);
  await expect(page.locator('body')).toHaveText('Innesto Roots');
});
