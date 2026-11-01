import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { rows, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Messenger monitor screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1100 } });

  test('dashboard — last 24 hours, queue depth, workers and recent failures', async ({ page }) => {
    await page.goto('/admin/messenger');
    await expect(page.getByTestId('mmk-dashboard')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'dashboard.png'), fullPage: true });
  });

  test('failed-message — payload redacted, retry to the original transport', async ({ page }) => {
    await page.goto('/admin/messenger/transports/failed');
    await rows(page, 'mmk-transport').filter({ hasText: 'SendInvoiceEmail' }).getByRole('link', { name: 'View' }).click();
    await expect(page.getByTestId('mmk-payload')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'failed-message.png'), fullPage: true });
  });
});
