import { test, expect } from '@playwright/test';
import { confirmAction, rows } from './helpers';

/** REQ-DEMO-013 — happy path of the messenger monitor demo (the Makefile re-seeds before the run). */
test.describe('Messenger monitor demo', () => {
  test('home reads the queue depth service', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('demo-depth')).toBeVisible();
    await expect(page.getByTestId('demo-failed')).toHaveText('3');
  });

  test('dashboard shows the last 24 hours, the queues and the recent failures', async ({ page }) => {
    await page.goto('/admin/messenger');
    await expect(page.getByTestId('mmk-dashboard')).toBeVisible();
    await expect(page.locator('#mmk-card-transports')).toContainText('failed');
    await expect(page.locator('#mmk-card-failures')).toContainText('SyncInventory');
  });

  test('history filters by status', async ({ page }) => {
    await page.goto('/admin/messenger/history?status=failed');
    await expect(rows(page, 'mmk-history')).toHaveCount(3);
    await page.goto('/admin/messenger/history?status=success');
    await expect(rows(page, 'mmk-history')).toHaveCount(8);
  });

  test('a failed message shows its payload redacted', async ({ page }) => {
    await page.goto('/admin/messenger/transports/failed');
    await rows(page, 'mmk-transport').filter({ hasText: 'SendInvoiceEmail' }).getByRole('link', { name: 'View' }).click();
    const payload = page.getByTestId('mmk-payload');
    await expect(payload).toContainText('INV-2026-0105');
    await expect(payload).toContainText('[redacted]');
    await expect(page.locator('body')).not.toContainText('sofia.costa@example.invalid');
  });

  test('retry sends a failed message back to its original transport', async ({ page }) => {
    await page.goto('/admin/messenger/transports/failed');
    await expect(rows(page, 'mmk-transport')).toHaveCount(3);
    const row = rows(page, 'mmk-transport').filter({ hasText: 'SyncInventory' }).first();
    await confirmAction(page, row.getByTestId('mmk-retry'));
    await expect(page.locator('body')).toContainText('was sent back to "async"');
    await expect(rows(page, 'mmk-transport')).toHaveCount(2);
  });

  test('settings switch the optional recording without a redeploy', async ({ page }) => {
    await page.goto('/admin/messenger/settings');
    const form = page.locator('form[name="nowo_mmk_settings"]');
    await form.getByLabel('Record high-volume messages').check();
    await form.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('body')).toContainText('Settings saved.');
    await expect(page.locator('form[name="nowo_mmk_settings"]').getByLabel('Record high-volume messages')).toBeChecked();
  });
});
