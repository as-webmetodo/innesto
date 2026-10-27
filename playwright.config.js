// SPDX-License-Identifier: MPL-2.0

import { defineConfig, devices } from '@playwright/test';

// The compose service to test against: web (default), web-noext or web-no-htaccess.
const service = process.env.INNESTO_WEB ?? 'web';

export default defineConfig({
  testDir: '.',
  testMatch: ['tests/e2e/**/*.spec.js', 'modules/*/tests/e2e/**/*.spec.js'],
  forbidOnly: !!process.env.CI,
  use: {
    baseURL: 'http://localhost:8080/innesto/',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
  webServer: {
    command: `docker compose up --build ${service}`,
    url: 'http://localhost:8080/innesto/',
    timeout: 300_000,
    gracefulShutdown: { signal: 'SIGTERM', timeout: 10_000 },
  },
});
