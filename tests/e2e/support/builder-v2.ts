import { expect, type Locator, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/** Builder v2 (ADR 0037) helpers: everything is addressed through the `data-builder-*` attributes. */

export interface SkeletonElement {
  readonly type: string;
  readonly key: string;
  readonly label: string;
  readonly elements?: readonly SkeletonElement[];
}

export interface SkeletonPage {
  readonly key: string;
  readonly title: string;
  readonly elements: readonly SkeletonElement[];
}

export function section72(): readonly SkeletonPage[] {
  const fixture = JSON.parse(readFileSync('tests/e2e/fixtures/section-7-2.json', 'utf8')) as {
    pages: SkeletonPage[];
  };
  return fixture.pages;
}

export const root = (page: Page): Locator => page.locator('[data-builder-root]');
export const palette = (page: Page, type: string): Locator =>
  page.locator(`[data-builder-palette-item="${type}"]`);
export const pageCard = (page: Page, index: number): Locator =>
  page.locator('[data-builder-page]').nth(index);
export const pageList = (page: Page, index: number): Locator =>
  pageCard(page, index).locator(':scope > .bv2-card__body > [data-builder-list]');
export const nodeByKey = (page: Page, key: string): Locator =>
  page.locator(`[data-builder-node][data-builder-key="${key}"]`).first();
export const selected = (page: Page): Locator =>
  page.locator('[role="treeitem"][aria-selected="true"]').first();

/** Creates a blank form (one empty page) and opens it in the canvas builder. */
export async function createBlankCanvas(page: Page, name: string): Promise<void> {
  await page.goto('/admin/demo/forms');
  await page.getByRole('button', { name: /neues leeres formular|new blank form/i }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('textbox').first().fill(name);
  await dialog
    .getByRole('button', { name: /submit|absenden|erstellen|create/i })
    .first()
    .click();
  await expect(page).toHaveURL(/\/forms\/\d+\/edit/, { timeout: 30_000 });
  await page.goto(page.url().replace(/\/edit(\?.*)?$/, '/canvas'));
  await expect(root(page)).toHaveAttribute('data-builder-ready', '', {
    timeout: 15_000,
  });
}

export async function expectSaved(page: Page): Promise<void> {
  await expect(root(page)).toHaveAttribute('data-builder-sync-state', /^(saved|idle)$/, { timeout: 15_000 });
}

/** Fills the inspector's label and key of the selected node and waits until the canvas shows them. */
export async function editSelected(
  page: Page,
  values: { label?: string; title?: string; key: string },
): Promise<void> {
  const inspector = page.locator('[data-builder-inspector]');
  const text = values.label ?? values.title;
  if (text !== undefined) {
    const field = inspector
      .getByRole('textbox', {
        name: /^(bezeichnung|label|seitentitel|titel|title)/i,
      })
      .first();
    await field.fill(text);
    await field.blur();
  }
  const key = inspector.getByRole('textbox', { name: /^(schlüssel|seitenschlüssel|key)/i }).first();
  await expect(key).toBeEditable();
  await key.fill(values.key);
  await key.blur();
  await expect(
    page.locator(`[data-builder-key="${values.key}"], [data-builder-page-key="${values.key}"]`).first(),
  ).toBeVisible({
    timeout: 10_000,
  });
}

/**
 * Drags with real mouse steps (SortableJS runs in fallback mode). The placeholder moves the layout
 * while dragging, so the pointer re-aims at the target's current box before releasing.
 */
export async function dragTo(
  page: Page,
  source: Locator,
  target: Locator,
  edge: 'top' | 'bottom' = 'bottom',
): Promise<void> {
  const from = await source.boundingBox();
  if (from === null) {
    throw new Error('drag source is not visible');
  }
  await source.scrollIntoViewIfNeeded();
  await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
  await page.mouse.down();
  for (let round = 0; round < 4; round++) {
    await target.scrollIntoViewIfNeeded();
    const box = await target.boundingBox();
    if (box === null) {
      throw new Error('drag target is not visible');
    }
    const y = edge === 'top' ? box.y + Math.min(10, box.height / 2) : box.y + box.height - 6;
    await page.mouse.move(box.x + box.width / 2, y, {
      steps: round === 0 ? 15 : 4,
    });
    await page.waitForTimeout(80);
  }
  await page.mouse.up();
}

/** The definition as the builder holds it, read from the read-only JSON view. */
export async function readDefinition(page: Page): Promise<{
  pages: { key: string; title: string; elements: SkeletonElement[] }[];
}> {
  await page.locator('[data-builder-toolbar="json"]').click();
  const content = page.locator('[data-builder-json-content]');
  await expect(content).toContainText('"pages"');
  const json = JSON.parse((await content.textContent()) ?? '{}') as {
    pages: { key: string; title: string; elements: SkeletonElement[] }[];
  };
  await page.locator('[data-builder-json-close]').click();
  return json;
}

function strip(element: SkeletonElement): SkeletonElement {
  const base = { type: element.type, key: element.key, label: element.label };
  return element.elements === undefined ? base : { ...base, elements: element.elements.map(strip) };
}

/** Pages reduced to the fixture's shape (type/key/label per element). */
export function skeletonOf(definition: Awaited<ReturnType<typeof readDefinition>>): SkeletonPage[] {
  return definition.pages.map((p) => ({
    key: p.key,
    title: p.title,
    elements: p.elements.map(strip),
  }));
}
