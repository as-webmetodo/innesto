// SPDX-License-Identifier: MPL-2.0

import { playwright } from '@vitest/browser-playwright';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['tests/browser/**/*.test.js', 'modules/*/tests/browser/**/*.test.js'],
    browser: {
      enabled: true,
      headless: true,
      provider: playwright(),
      instances: [{ browser: 'chromium' }, { browser: 'webkit' }],
    },
  },
});
