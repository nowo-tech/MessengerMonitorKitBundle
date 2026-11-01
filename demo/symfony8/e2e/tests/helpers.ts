import { type Page } from '@playwright/test';

/** Wait until the Symfony Web Debug Toolbar has finished loading (optional outside dev). */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.waitForSelector('.sf-toolbar', { state: 'visible', timeout: 20000 }).catch(() => undefined);
  const mini = page.locator('.sf-minitoolbar a').first();
  if (await mini.isVisible().catch(() => false)) {
    await mini.click();
  }
  await page
    .waitForFunction(
      () => {
        const bar = document.querySelector('.sf-toolbar') as HTMLElement | null;
        if (!bar || bar.offsetParent === null) {
          return true;
        }
        return !!document.querySelector('.sf-toolbar-block');
      },
      { timeout: 25000, polling: 250 },
    )
    .catch(() => undefined);
  await new Promise((r) => setTimeout(r, 400));
}

/** Rows of a monitor list page (`data-testid` of the page section). */
export function rows(page: Page, testId: string) {
  return page.locator(`[data-testid="${testId}"] tbody tr`);
}

/** Opens the UiKit confirm dialog of an action button and confirms it. */
export async function confirmAction(page: Page, button: ReturnType<Page['locator']>): Promise<void> {
  await button.click();
  const dialog = page.locator('dialog[open]');
  await dialog.locator('.nowo-ui-confirm__submit').click();
}
