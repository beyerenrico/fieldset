import { expect, type Page } from './test';

/** The app's 503 page: the classic builder render waited 20 s behind another one (ClassicBuilderSlot, ADR 0083). */
async function busy(page: Page): Promise<boolean> {
  return page.getByRole('heading', { name: '503', exact: true }).isVisible();
}

/**
 * Waits until a classic builder page has rendered. One classic render runs at a time per instance, so with parallel
 * workers a page can wait out its 20 s behind another worker's renders and answer 503 with Retry-After: 5 (CI run
 * 39796441261). Like a user, the helper reloads after five seconds.
 */
export async function expectClassicBuilderPage(page: Page): Promise<void> {
  await page.waitForLoadState();
  await expect(async () => {
    if (await busy(page)) {
      await page.waitForTimeout(5_000);
      await page.reload();
    }
    expect(await busy(page)).toBe(false);
  }).toPass({ timeout: 90_000, intervals: [0] });
}

export async function gotoClassicBuilder(page: Page, url: string): Promise<void> {
  await page.goto(url);
  await expectClassicBuilderPage(page);
}
