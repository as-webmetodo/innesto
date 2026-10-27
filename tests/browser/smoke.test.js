// SPDX-License-Identifier: MPL-2.0

import { expect, test } from 'vitest';

test('custom elements upgrade in a real browser', () => {
  customElements.define('in-smoke', class extends HTMLElement {
    connectedCallback() {
      this.textContent = 'grafted';
    }
  });

  document.body.innerHTML = '<in-smoke></in-smoke>';

  expect(document.querySelector('in-smoke')?.textContent).toBe('grafted');
});
