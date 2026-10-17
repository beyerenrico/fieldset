import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { adminState } from './support/auth';
import {
  createBlankCanvas,
  dragTo,
  editSelected,
  expectSaved,
  nodeByKey,
  pageCard,
  pageList,
  palette,
  publishDraft,
  readDefinition,
  root,
  section72,
  selected,
  skeletonOf,
  type SkeletonElement,
} from './support/builder-v2';

// M7 Builder v2 (ADR 0037): the §7.2 example built by drag (mouse steps) and by keyboard only, a
// v1 ↔ v2 round trip, and axe checks of the canvas page.
const evidence = (name: string) => `docs/agent/milestones/evidence/M7/${name}.png`;

test.use({ storageState: adminState('designer') });

async function namePage(page: Page, index: number, title: string, key: string): Promise<void> {
  await pageCard(page, index).locator(':scope > .bv2-card__head').click();
  await editSelected(page, { title, key });
}

async function dropFromPalette(page: Page, type: string, list: ReturnType<typeof pageList>): Promise<void> {
  await dragTo(page, palette(page, type), list, 'bottom');
  await expect(selected(page)).toHaveAttribute('data-builder-type', type);
}

test('designer builds the §7.2 example by drag and drop', async ({ page }) => {
  test.setTimeout(240_000);
  await createBlankCanvas(page, 'Servicebericht Canvas Drag');
  const [job, work] = section72();

  await namePage(page, 0, job!.title, job!.key);
  for (const element of job!.elements) {
    await dropFromPalette(page, element.type, pageList(page, 0));
    await editSelected(page, element);
  }

  await page.locator('[data-builder-add-page]').click();
  await namePage(page, 1, work!.title, work!.key);
  const [repeater, ...rest] = work!.elements as [SkeletonElement, ...SkeletonElement[]];
  await dropFromPalette(page, 'repeater', pageList(page, 1));
  await editSelected(page, repeater);
  const rows = nodeByKey(page, repeater.key).locator(':scope > .bv2-card__body > [data-builder-list]');
  for (const child of repeater.elements ?? []) {
    await dropFromPalette(page, child.type, rows);
    await editSelected(page, child);
  }
  for (const element of rest) {
    await dropFromPalette(page, element.type, pageList(page, 1));
    await editSelected(page, element);
  }

  // Reorder by drag: move "Service date" above "Customer", then back.
  await dragTo(
    page,
    nodeByKey(page, 'service_date').locator('[data-builder-handle]').first(),
    nodeByKey(page, 'customer_name'),
    'top',
  );
  await expect(pageList(page, 0).locator(':scope > [data-builder-node]').first()).toHaveAttribute(
    'data-builder-key',
    'service_date',
  );
  await page.keyboard.press('Control+z');
  await expect(pageList(page, 0).locator(':scope > [data-builder-node]').first()).toHaveAttribute(
    'data-builder-key',
    'customer_name',
  );
  // The Move menu is the single-pointer alternative to dragging (WCAG 2.5.7).
  await nodeByKey(page, 'service_date').locator(':scope > .bv2-card__head > [data-builder-menu]').click();
  await page.locator('[role="menu"] [data-builder-action="move-up"]').click();
  await expect(pageList(page, 0).locator(':scope > [data-builder-node]').first()).toHaveAttribute(
    'data-builder-key',
    'service_date',
  );
  await page.locator('[data-builder-toolbar="undo"]').click();
  await expect(pageList(page, 0).locator(':scope > [data-builder-node]').first()).toHaveAttribute(
    'data-builder-key',
    'customer_name',
  );

  await expectSaved(page);
  expect(skeletonOf(await readDefinition(page))).toEqual(section72());
  await page.screenshot({ path: evidence('30-canvas-drag'), fullPage: true });

  // The draft is stored: a reload shows the same structure, which the designer then publishes.
  await page.reload();
  await expect(root(page)).toHaveAttribute('data-builder-ready', '');
  expect(skeletonOf(await readDefinition(page))).toEqual(section72());
  await publishDraft(page, 'Servicebericht per Drag and Drop', 'mouse');
});

async function insertByKeyboard(page: Page, type: string): Promise<void> {
  await palette(page, type).focus();
  await page.keyboard.press('Enter');
  await expect(selected(page)).toHaveAttribute('data-builder-type', type);
}

/** Picks the selected card up by its handle, applies the keys, drops it with Space. */
async function moveSelected(page: Page, keys: readonly string[]): Promise<void> {
  await selected(page).locator(':scope > .bv2-card__head > [data-builder-handle]').focus();
  await page.keyboard.press('Space');
  for (const key of keys) {
    await page.keyboard.press(key);
  }
  await page.keyboard.press('Space');
  await expect(page.locator('[data-builder-live]')).toContainText(/abgelegt|dropped/i);
}

test('designer builds the §7.2 example with the keyboard only', async ({ page }) => {
  test.setTimeout(240_000);
  await createBlankCanvas(page, 'Servicebericht Canvas Tastatur');
  const [job, work] = section72();

  await pageCard(page, 0).focus();
  await page.keyboard.press('Enter');
  await editSelected(page, { title: job!.title, key: job!.key });
  for (const element of job!.elements) {
    await insertByKeyboard(page, element.type);
    await editSelected(page, element);
  }

  await page.locator('[data-builder-add-page]').focus();
  await page.keyboard.press('Enter');
  await editSelected(page, { title: work!.title, key: work!.key });
  const [repeater, ...rest] = work!.elements as [SkeletonElement, ...SkeletonElement[]];
  await insertByKeyboard(page, 'repeater');
  await editSelected(page, repeater);
  for (const child of repeater.elements ?? []) {
    // Inserted after the selection (next to the repeater), then moved into it with →.
    await insertByKeyboard(page, child.type);
    await moveSelected(page, ['ArrowRight']);
    await editSelected(page, child);
  }
  // The next inserts land after the last row element; ← moves each one out of the repeater.
  for (const element of rest) {
    await insertByKeyboard(page, element.type);
    if ((await selected(page).locator('xpath=ancestor::*[@data-builder-type="repeater"]').count()) > 0) {
      await moveSelected(page, ['ArrowLeft']);
    }
    await editSelected(page, element);
  }

  // Keyboard move across pages and back, then the Move menu, opened and used with the keyboard.
  await nodeByKey(page, 'defect').focus();
  await page.keyboard.press('Enter');
  await expect(selected(page)).toHaveAttribute('data-builder-key', 'defect');
  await moveSelected(page, ['PageDown']);
  await expect(pageList(page, 1).locator(':scope > [data-builder-node]').first()).toHaveAttribute(
    'data-builder-key',
    'defect',
  );
  await moveSelected(page, ['PageUp']);
  await nodeByKey(page, 'service_date').locator(':scope > .bv2-card__head > [data-builder-menu]').focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('[role="menu"] [data-builder-action="move-up"]')).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(pageList(page, 0).locator(':scope > [data-builder-node]').first()).toHaveAttribute(
    'data-builder-key',
    'service_date',
  );
  await page.keyboard.press('Control+z');

  await expectSaved(page);
  expect(skeletonOf(await readDefinition(page))).toEqual(section72());
  await publishDraft(page, 'Servicebericht per Tastatur', 'keyboard');
  // The inspector reloads the selected element: its key is published now and locked.
  await expect(
    page.locator('[data-builder-inspector]').getByRole('textbox', { name: /^(schlüssel|key)/i }),
  ).toBeDisabled();
  await page.screenshot({
    path: evidence('31-canvas-keyboard'),
    fullPage: true,
  });
});

test('a change survives the v1 ↔ v2 round trip', async ({ page }) => {
  test.setTimeout(120_000);
  await createBlankCanvas(page, 'Canvas Rundreise');
  await dropFromPalette(page, 'text', pageList(page, 0));
  await editSelected(page, { label: 'Rundreise', key: 'round_trip' });
  await page.locator('[data-builder-toolbar="classic"]').click();
  await expect(page).toHaveURL(/\/forms\/\d+\/edit/, { timeout: 30_000 });
  await expect(page.getByText('Rundreise').first()).toBeVisible();
  await page.goto(page.url().replace(/\/edit(\?.*)?$/, '/canvas'));
  await expect(root(page)).toHaveAttribute('data-builder-ready', '');
  await expect(nodeByKey(page, 'round_trip')).toBeVisible();
});

test('the canvas page has no axe violations', async ({ page }) => {
  test.setTimeout(120_000);
  await createBlankCanvas(page, 'Canvas Barrierefreiheit');
  await dropFromPalette(page, 'section', pageList(page, 0));
  await dropFromPalette(
    page,
    'text',
    nodeByKey(page, 'section').locator(':scope > .bv2-card__body > [data-builder-list]'),
  );
  await expectSaved(page);
  const results = await new AxeBuilder({ page })
    .include('[data-builder-root]')
    .include('[data-builder-inspector]')
    .exclude('iframe[data-builder-preview]')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(results.violations).toEqual([]);
});
