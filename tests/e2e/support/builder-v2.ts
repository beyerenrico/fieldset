import { expect, type Locator, type Page, type Response } from '@playwright/test';
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
  // New forms open in the canvas builder, the default edit target (ADR 0044).
  await expect(page).toHaveURL(/\/forms\/\d+\/canvas/, { timeout: 30_000 });
  await expect(root(page)).toHaveAttribute('data-builder-ready', '', {
    timeout: 15_000,
  });
  // The page's first Livewire requests are still in flight when the builder reports ready. One answering after a
  // selection replaces the client snapshot with its older one, and the next edit is sent without the selection
  // (QUESTIONS 50): let them finish first.
  await page.waitForLoadState('networkidle');
}

export async function expectSaved(page: Page): Promise<void> {
  await expect(root(page)).toHaveAttribute('data-builder-sync-state', /^(saved|idle)$/, { timeout: 15_000 });
}

const isLivewireUpdate = (response: Response): boolean =>
  response.request().method() === 'POST' && /\/livewire(-[^/]+)?\/update/.test(response.url());

/** The id of the selected card (page or element). */
export async function selectedId(page: Page): Promise<string> {
  const card = selected(page);
  await expect(card).toBeVisible();
  const id = (await card.getAttribute('data-builder-node')) ?? (await card.getAttribute('data-builder-page'));
  if (id === null) {
    throw new Error('the selected card has no id');
  }
  return id;
}

/**
 * Fills the inspector's label and key of the selected node and waits until the canvas shows them.
 * The inspector loads the selection over Livewire: its fields belong to the node named by
 * `data-builder-inspected`, so the helper waits for that before typing.
 */
export async function editSelected(
  page: Page,
  values: { label?: string; title?: string; key: string },
): Promise<void> {
  const inspector = page.locator('[data-builder-inspector]');
  await expect(inspector).toHaveAttribute('data-builder-inspected', await selectedId(page), { timeout: 10_000 });
  const text = values.label ?? values.title;
  if (text !== undefined) {
    const field = inspector
      .getByRole('textbox', {
        name: /^(bezeichnung|label|seitentitel|titel|title)/i,
      })
      .first();
    if ((await field.inputValue()) !== text) {
      await field.fill(text);
      // The label is live on blur and may suggest a key (builder-v2:key-suggested): wait for that
      // round trip so the steps stay deterministic. A key typed meanwhile would be kept.
      const roundTrip = page.waitForResponse(isLivewireUpdate);
      await field.blur();
      await roundTrip;
    }
  }
  const key = inspector.getByRole('textbox', { name: /^(schlüssel|seitenschlüssel|key)/i }).first();
  await expect(key).toBeEditable();
  // Livewire dispatches builder-v2:key-suggested a tick after the response above. Landing between fill's select-all
  // and its insert, the suggestion drops the selection and the key is appended to it ("totalline_total"). Once the
  // field no longer shows the suggestion's `from`, later suggestions are ignored, so a second fill settles it.
  await expect(async () => {
    await key.fill(values.key);
    await expect(key).toHaveValue(values.key, { timeout: 500 });
  }).toPass({ timeout: 10_000 });
  await key.blur();
  await expect(
    page.locator(`[data-builder-key="${values.key}"], [data-builder-page-key="${values.key}"]`).first(),
  ).toBeVisible({
    timeout: 10_000,
  });
}

/**
 * True once SortableJS's placeholder (the `bv2-ghost` item inside a canvas list) sits where the drop
 * should go: at the end (bottom) or start (top) of a target list, or right after/before a target card.
 */
function placeholderPlaced(target: Locator, edge: 'top' | 'bottom'): Promise<boolean> {
  return target.evaluate((el, aim) => {
    const ghost = document.querySelector('[data-builder-list] > .bv2-ghost');
    if (ghost === null) {
      return false;
    }
    if (el.hasAttribute('data-builder-list')) {
      const sibling = aim === 'bottom' ? ghost.nextElementSibling : ghost.previousElementSibling;
      return ghost.parentElement === el && sibling === null;
    }
    return (aim === 'top' ? el.previousElementSibling : el.nextElementSibling) === ghost;
  }, edge);
}

/**
 * Drags with real mouse steps (SortableJS runs in fallback mode). The placeholder moves the layout
 * while dragging, so the pointer re-aims at the target's current box until the placeholder sits at
 * the requested place, then releases at once: moving on over cards that are still animating would
 * swap them back and forth.
 */
export async function dragTo(
  page: Page,
  source: Locator,
  target: Locator,
  edge: 'top' | 'bottom' = 'bottom',
): Promise<void> {
  // Scroll first: a box measured before scrolling points at the wrong place.
  await source.scrollIntoViewIfNeeded();
  const from = await source.boundingBox();
  if (from === null) {
    throw new Error('drag source is not visible');
  }
  await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
  await page.mouse.down();
  for (let round = 0; round < 4; round++) {
    if (round > 0 && (await placeholderPlaced(target, edge))) {
      break;
    }
    // Bring the aimed point to the middle of the viewport: Filament's sticky top bar covers the top
    // rows, and a tall list's bottom edge may lie below the fold.
    await target.evaluate((el, aim) => {
      const rect = el.getBoundingClientRect();
      const y = aim === 'top' ? rect.top + Math.min(10, rect.height / 2) : rect.bottom - 6;
      window.scrollBy(0, y - window.innerHeight / 2);
    }, edge);
    const box = await target.boundingBox();
    if (box === null) {
      throw new Error('drag target is not visible');
    }
    // Approach vertically from the middle of the card next to the drop place (the target card, or a
    // list's last card), so the pointer crosses SortableJS's swap zones in a defined order instead
    // of entering the last card sideways, below them.
    const via = await approachPoint(target);
    if (via !== null) {
      await page.mouse.move(via.x, via.y, { steps: round === 0 ? 15 : 4 });
      await page.waitForTimeout(150); // longer than SortableJS's 120 ms animation
      if (await placeholderPlaced(target, edge)) {
        break;
      }
    }
    const y = edge === 'top' ? box.y + Math.min(10, box.height / 2) : box.y + box.height - 6;
    await page.mouse.move(box.x + box.width / 2, y, { steps: 4 });
    await page.waitForTimeout(150); // longer than SortableJS's 120 ms animation
  }
  await page.mouse.up();
}

/** The middle of the target card, or of a target list's last card (null for an empty list). */
function approachPoint(target: Locator): Promise<{ x: number; y: number } | null> {
  return target.evaluate((el) => {
    const card = el.hasAttribute('data-builder-list')
      ? Array.from(el.children)
          .filter((child) => child.matches('[data-builder-node]') && !child.classList.contains('bv2-ghost'))
          .at(-1)
      : el;
    if (card === undefined) {
      return null;
    }
    const rect = card.getBoundingClientRect();
    return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
  });
}

/**
 * Publishes the draft through the toolbar's Publish action (mouse, or keyboard only) and waits until
 * the builder announces the version and shows the published elements' keys as locked.
 */
export async function publishDraft(page: Page, changelog: string, input: 'mouse' | 'keyboard'): Promise<void> {
  const press = async (button: Locator): Promise<void> => {
    if (input === 'mouse') {
      await button.click();
    } else {
      await button.focus();
      await page.keyboard.press('Enter');
    }
  };
  await press(page.locator('[data-builder-toolbar="publish"]'));
  const dialog = page.getByRole('dialog').filter({ hasText: /Änderungsprotokoll|changelog/i });
  const textarea = dialog.locator('textarea');
  await textarea.focus();
  await page.keyboard.type(changelog);
  await press(dialog.getByRole('button', { name: /^(absenden|submit)$/i }));
  await expect(page.locator('[data-builder-live]')).toContainText(/Version 1 (veröffentlicht|published)/, {
    timeout: 20_000,
  });
  await expect(page.locator('[data-builder-node][data-builder-locked]').first()).toBeVisible();
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
