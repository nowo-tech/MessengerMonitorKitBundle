import { type Page } from '@playwright/test';

/** Hides the Symfony Web Debug Toolbar so it never appears in a screenshot (the shell frames every page). */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
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
